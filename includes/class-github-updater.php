<?php
/**
 * STOC_GitHub_Updater - GitHub自動更新クラス
 *
 * @package Section_TOC_Block
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * GitHub自動更新管理クラス
 */
class STOC_GitHub_Updater {

    private static $instance = null;

    /**
     * シングルトンインスタンス取得
     */
    public static function get_instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * コンストラクタ
     */
    private function __construct() {
        add_filter( 'pre_set_site_transient_update_plugins', array( $this, 'check_for_plugin_update' ) );
        add_filter( 'plugins_api', array( $this, 'plugin_info' ), 20, 3 );
        add_filter( 'upgrader_post_install', array( $this, 'after_install' ), 10, 3 );
        add_action( 'admin_init', array( $this, 'handle_check_update' ) );
    }

    /**
     * 更新確認ボタンの処理
     */
    public function handle_check_update() {
        if ( ! isset( $_GET['stoc_check_update'] ) || $_GET['stoc_check_update'] !== '1' ) {
            return;
        }

        if ( ! isset( $_GET['_wpnonce'] ) || ! wp_verify_nonce( $_GET['_wpnonce'], 'stoc_check_update' ) ) {
            return;
        }

        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        // キャッシュをクリア
        delete_transient( Section_TOC_Block::CACHE_KEY );
        delete_site_transient( 'update_plugins' );

        // 設定ページにリダイレクト
        wp_redirect( admin_url( 'options-general.php?page=section-toc-block-settings&update_checked=1' ) );
        exit;
    }

    /**
     * GitHub APIから最新リリース情報を取得
     *
     * @return object|false リリース情報またはfalse
     */
    private function get_github_release_info() {
        // キャッシュをチェック
        $cached = get_transient( Section_TOC_Block::CACHE_KEY );
        if ( $cached !== false ) {
            return $cached;
        }

        // GitHub APIリクエスト
        $response = wp_remote_get( Section_TOC_Block::GITHUB_API_URL, array(
            'timeout' => 10,
            'headers' => array(
                'Accept' => 'application/vnd.github.v3+json',
                'User-Agent' => 'WordPress/' . get_bloginfo( 'version' ) . '; ' . home_url(),
            ),
        ) );

        // エラーチェック
        if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
            return false;
        }

        $release = json_decode( wp_remote_retrieve_body( $response ) );

        if ( empty( $release ) || ! isset( $release->tag_name ) ) {
            return false;
        }

        // バージョン番号を正規化（v1.0.1 -> 1.0.1）
        $version = ltrim( $release->tag_name, 'vV' );

        // 必要な情報を整形
        $release_info = (object) array(
            'version'      => $version,
            'download_url' => $release->zipball_url,
            'published_at' => $release->published_at,
            'body'         => isset( $release->body ) ? $release->body : '',
            'html_url'     => $release->html_url,
        );

        // キャッシュに保存
        set_transient( Section_TOC_Block::CACHE_KEY, $release_info, Section_TOC_Block::CACHE_EXPIRATION );

        return $release_info;
    }

    /**
     * WordPressの更新チェックに自プラグインの更新情報を注入
     *
     * @param object $transient 更新情報のトランジェント
     * @return object 更新後のトランジェント
     */
    public function check_for_plugin_update( $transient ) {
        if ( empty( $transient->checked ) ) {
            return $transient;
        }

        $release_info = $this->get_github_release_info();

        if ( $release_info === false ) {
            return $transient;
        }

        // 現在のバージョンと比較
        $current_version = Section_TOC_Block::VERSION;
        $new_version = $release_info->version;

        if ( version_compare( $new_version, $current_version, '>' ) ) {
            $plugin_file = STOC_PLUGIN_BASENAME;

            $transient->response[ $plugin_file ] = (object) array(
                'slug'        => 'section-toc-block',
                'plugin'      => $plugin_file,
                'new_version' => $new_version,
                'url'         => 'https://github.com/' . Section_TOC_Block::GITHUB_USERNAME . '/' . Section_TOC_Block::GITHUB_REPO,
                'package'     => $release_info->download_url,
                'icons'       => array(),
                'banners'     => array(),
                'tested'      => '',
                'requires_php' => '7.4',
            );
        }

        return $transient;
    }

    /**
     * プラグイン詳細情報を提供（「詳細を表示」リンク用）
     *
     * @param false|object|array $result
     * @param string $action
     * @param object $args
     * @return false|object
     */
    public function plugin_info( $result, $action, $args ) {
        if ( $action !== 'plugin_information' ) {
            return $result;
        }

        $plugin_slug = 'section-toc-block';

        if ( ! isset( $args->slug ) || $args->slug !== $plugin_slug ) {
            return $result;
        }

        $release_info = $this->get_github_release_info();

        if ( $release_info === false ) {
            return $result;
        }

        // Markdown -> HTML変換
        $changelog = $this->parse_markdown( $release_info->body );

        return (object) array(
            'name'              => 'Section TOC Block',
            'slug'              => $plugin_slug,
            'version'           => $release_info->version,
            'author'            => '<a href="https://github.com/' . Section_TOC_Block::GITHUB_USERNAME . '">' . Section_TOC_Block::GITHUB_USERNAME . '</a>',
            'author_profile'    => 'https://github.com/' . Section_TOC_Block::GITHUB_USERNAME,
            'homepage'          => 'https://github.com/' . Section_TOC_Block::GITHUB_USERNAME . '/' . Section_TOC_Block::GITHUB_REPO,
            'short_description' => 'H2見出し配下のH3を自動で箇条書きリストとして表示するGutenbergブロック',
            'sections'          => array(
                'description' => 'H2見出し配下のH3を自動で箇条書きリストとして表示するGutenbergブロック',
                'changelog'   => $changelog,
            ),
            'download_link'     => $release_info->download_url,
            'last_updated'      => $release_info->published_at,
            'requires'          => '5.0',
            'tested'            => '6.7',
            'requires_php'      => '7.4',
        );
    }

    /**
     * GitHub MarkdownをHTMLに簡易変換
     *
     * @param string $markdown
     * @return string HTML
     */
    private function parse_markdown( $markdown ) {
        if ( empty( $markdown ) ) {
            return '<p>変更履歴の情報はありません。</p>';
        }

        // 基本的な変換
        $html = esc_html( $markdown );

        // 見出し
        $html = preg_replace( '/^### (.+)$/m', '<h4>$1</h4>', $html );
        $html = preg_replace( '/^## (.+)$/m', '<h3>$1</h3>', $html );
        $html = preg_replace( '/^# (.+)$/m', '<h2>$1</h2>', $html );

        // リスト項目
        $html = preg_replace( '/^- (.+)$/m', '<li>$1</li>', $html );
        $html = preg_replace( '/^\* (.+)$/m', '<li>$1</li>', $html );

        // 連続する<li>を<ul>で囲む
        $html = preg_replace( '/(<li>.*<\/li>\n?)+/s', '<ul>$0</ul>', $html );

        // 改行を<br>に
        $html = nl2br( $html );

        return $html;
    }

    /**
     * 更新インストール後の処理
     * GitHubのzipballはディレクトリ名が異なるため修正
     *
     * @param bool $response
     * @param array $hook_extra
     * @param array $result
     * @return array
     */
    public function after_install( $response, $hook_extra, $result ) {
        global $wp_filesystem;

        // このプラグインの更新かどうかチェック
        if ( ! isset( $hook_extra['plugin'] ) ) {
            return $result;
        }

        if ( $hook_extra['plugin'] !== STOC_PLUGIN_BASENAME ) {
            return $result;
        }

        // 正しいディレクトリ名に変更
        $plugin_slug = dirname( STOC_PLUGIN_BASENAME );
        $plugin_dir = WP_PLUGIN_DIR . '/' . $plugin_slug;

        if ( $wp_filesystem->move( $result['destination'], $plugin_dir ) ) {
            $result['destination'] = $plugin_dir;
        }

        // プラグインを再有効化
        activate_plugin( $hook_extra['plugin'] );

        return $result;
    }
}
