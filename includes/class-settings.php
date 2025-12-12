<?php
/**
 * Section TOC Block - 設定クラス
 */

// 直接アクセス禁止
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class STOC_Settings {

    private static $instance = null;

    /**
     * デフォルト設定値
     */
    private $defaults = array(
        'wrapper_template' => '<div class="section-toc-wrapper">
  <nav class="section-toc-nav" aria-label="セクション目次">
    <ul class="section-toc-list">
{{items}}
    </ul>
  </nav>
</div>',
        'item_template' => '      <li class="section-toc-item">
        <a href="{{anchor}}">{{text}}</a>
      </li>',
    );

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
        add_action( 'admin_menu', array( $this, 'add_settings_page' ) );
        add_action( 'admin_init', array( $this, 'register_settings' ) );
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_styles' ) );
    }

    /**
     * 設定ページを追加
     */
    public function add_settings_page() {
        add_options_page(
            __( 'Section TOC Block 設定', 'section-toc-block' ),
            __( 'Section TOC Block', 'section-toc-block' ),
            'manage_options',
            'section-toc-block-settings',
            array( $this, 'render_settings_page' )
        );
    }

    /**
     * 設定を登録
     */
    public function register_settings() {
        register_setting(
            'stoc_settings_group',
            'stoc_settings',
            array( $this, 'sanitize_settings' )
        );

        add_settings_section(
            'stoc_template_section',
            __( 'テンプレート設定', 'section-toc-block' ),
            array( $this, 'render_section_description' ),
            'section-toc-block-settings'
        );

        add_settings_field(
            'wrapper_template',
            __( 'ラッパーテンプレート', 'section-toc-block' ),
            array( $this, 'render_wrapper_template_field' ),
            'section-toc-block-settings',
            'stoc_template_section'
        );

        add_settings_field(
            'item_template',
            __( '項目テンプレート', 'section-toc-block' ),
            array( $this, 'render_item_template_field' ),
            'section-toc-block-settings',
            'stoc_template_section'
        );
    }

    /**
     * 管理画面用スタイルを読み込む
     */
    public function enqueue_admin_styles( $hook ) {
        if ( 'settings_page_section-toc-block-settings' !== $hook ) {
            return;
        }

        wp_add_inline_style( 'common', '
            .stoc-settings-wrap {
                max-width: 800px;
            }
            .stoc-settings-wrap textarea {
                width: 100%;
                font-family: monospace;
                font-size: 13px;
                line-height: 1.5;
                background-color: #f6f7f7;
                border: 1px solid #dcdcde;
                padding: 10px;
            }
            .stoc-settings-wrap .description {
                margin-top: 8px;
                color: #646970;
            }
            .stoc-settings-wrap .placeholder-list {
                background-color: #fff;
                border: 1px solid #dcdcde;
                padding: 10px 15px;
                margin-top: 10px;
                border-radius: 4px;
            }
            .stoc-settings-wrap .placeholder-list code {
                background-color: #f0f0f1;
                padding: 2px 6px;
                border-radius: 3px;
            }
            .stoc-reset-button {
                margin-left: 10px;
            }
        ' );
    }

    /**
     * 設定ページを表示
     */
    public function render_settings_page() {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        // リセットボタンの処理
        if ( isset( $_POST['stoc_reset_defaults'] ) && check_admin_referer( 'stoc_reset_defaults_nonce' ) ) {
            delete_option( 'stoc_settings' );
            echo '<div class="notice notice-success"><p>' . esc_html__( 'デフォルト設定に戻しました。', 'section-toc-block' ) . '</p></div>';
        }

        // 更新確認後のメッセージ
        if ( isset( $_GET['update_checked'] ) && $_GET['update_checked'] === '1' ) {
            echo '<div class="notice notice-success"><p>' . esc_html__( '更新を確認しました。', 'section-toc-block' ) . '</p></div>';
        }

        // GitHub API接続テスト
        $github_status = $this->test_github_connection();

        ?>
        <div class="wrap stoc-settings-wrap">
            <h1><?php echo esc_html( get_admin_page_title() ); ?></h1>

            <!-- 更新情報セクション -->
            <div class="stoc-update-section" style="background: #fff; border: 1px solid #ccd0d4; padding: 15px 20px; margin-bottom: 20px;">
                <h2 style="margin-top: 0;"><?php esc_html_e( 'プラグイン更新', 'section-toc-block' ); ?></h2>
                <table class="form-table">
                    <tr>
                        <th><?php esc_html_e( '現在のバージョン', 'section-toc-block' ); ?></th>
                        <td><code><?php echo esc_html( Section_TOC_Block::VERSION ); ?></code></td>
                    </tr>
                    <tr>
                        <th><?php esc_html_e( 'GitHub最新バージョン', 'section-toc-block' ); ?></th>
                        <td>
                            <?php if ( $github_status['success'] ) : ?>
                                <code><?php echo esc_html( $github_status['version'] ); ?></code>
                                <?php if ( version_compare( $github_status['version'], Section_TOC_Block::VERSION, '>' ) ) : ?>
                                    <span style="color: #d63638; margin-left: 10px;">⚠ <?php esc_html_e( '更新があります', 'section-toc-block' ); ?></span>
                                <?php else : ?>
                                    <span style="color: #00a32a; margin-left: 10px;">✓ <?php esc_html_e( '最新です', 'section-toc-block' ); ?></span>
                                <?php endif; ?>
                            <?php else : ?>
                                <span style="color: #d63638;">❌ <?php echo esc_html( $github_status['error'] ); ?></span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <tr>
                        <th><?php esc_html_e( 'GitHub API', 'section-toc-block' ); ?></th>
                        <td>
                            <code style="font-size: 11px;"><?php echo esc_html( Section_TOC_Block::GITHUB_API_URL ); ?></code>
                        </td>
                    </tr>
                    <tr>
                        <th><?php esc_html_e( 'WP更新トランジェント', 'section-toc-block' ); ?></th>
                        <td>
                            <?php
                            $update_plugins = get_site_transient( 'update_plugins' );
                            $plugin_file = 'section-toc-block/section-toc-block.php';
                            if ( isset( $update_plugins->response[ $plugin_file ] ) ) {
                                $update_info = $update_plugins->response[ $plugin_file ];
                                echo '<span style="color: #00a32a;">✓ ' . esc_html__( '更新情報が登録されています', 'section-toc-block' ) . '</span><br>';
                                echo '<code>new_version: ' . esc_html( $update_info->new_version ) . '</code>';
                            } else {
                                echo '<span style="color: #d63638;">❌ ' . esc_html__( '更新情報が登録されていません', 'section-toc-block' ) . '</span>';
                            }
                            ?>
                        </td>
                    </tr>
                </table>
                <p>
                    <a href="<?php echo esc_url( wp_nonce_url( admin_url( 'options-general.php?page=section-toc-block-settings&stoc_check_update=1' ), 'stoc_check_update' ) ); ?>" class="button button-secondary">
                        <?php esc_html_e( '更新を確認', 'section-toc-block' ); ?>
                    </a>
                    <a href="https://github.com/<?php echo esc_attr( Section_TOC_Block::GITHUB_USERNAME . '/' . Section_TOC_Block::GITHUB_REPO ); ?>/releases" target="_blank" class="button button-secondary" style="margin-left: 5px;">
                        <?php esc_html_e( 'GitHubリリースを見る', 'section-toc-block' ); ?>
                    </a>
                </p>
            </div>

            <form action="options.php" method="post">
                <?php
                settings_fields( 'stoc_settings_group' );
                do_settings_sections( 'section-toc-block-settings' );
                submit_button( __( '変更を保存', 'section-toc-block' ) );
                ?>
            </form>

            <form method="post" style="margin-top: 20px;">
                <?php wp_nonce_field( 'stoc_reset_defaults_nonce' ); ?>
                <input type="submit" name="stoc_reset_defaults" class="button button-secondary"
                       value="<?php esc_attr_e( 'デフォルトに戻す', 'section-toc-block' ); ?>"
                       onclick="return confirm('<?php esc_attr_e( 'テンプレートをデフォルトに戻しますか？', 'section-toc-block' ); ?>');">
            </form>
        </div>
        <?php
    }

    /**
     * セクション説明を表示
     */
    public function render_section_description() {
        echo '<p>' . esc_html__( 'フロントエンドで表示されるHTMLテンプレートをカスタマイズできます。', 'section-toc-block' ) . '</p>';
    }

    /**
     * ラッパーテンプレートフィールドを表示
     */
    public function render_wrapper_template_field() {
        $settings = $this->get_settings();
        $value = isset( $settings['wrapper_template'] ) ? $settings['wrapper_template'] : $this->defaults['wrapper_template'];
        ?>
        <textarea name="stoc_settings[wrapper_template]" rows="10"><?php echo esc_textarea( $value ); ?></textarea>
        <p class="description">
            <?php esc_html_e( '全体を囲むHTMLテンプレートです。', 'section-toc-block' ); ?>
        </p>
        <div class="placeholder-list">
            <strong><?php esc_html_e( '使用可能なプレースホルダー:', 'section-toc-block' ); ?></strong><br>
            <code>{{items}}</code> - <?php esc_html_e( 'H3見出しリストが挿入される位置', 'section-toc-block' ); ?>
        </div>
        <?php
    }

    /**
     * 項目テンプレートフィールドを表示
     */
    public function render_item_template_field() {
        $settings = $this->get_settings();
        $value = isset( $settings['item_template'] ) ? $settings['item_template'] : $this->defaults['item_template'];
        ?>
        <textarea name="stoc_settings[item_template]" rows="5"><?php echo esc_textarea( $value ); ?></textarea>
        <p class="description">
            <?php esc_html_e( '各H3見出し項目のHTMLテンプレートです。', 'section-toc-block' ); ?>
        </p>
        <div class="placeholder-list">
            <strong><?php esc_html_e( '使用可能なプレースホルダー:', 'section-toc-block' ); ?></strong><br>
            <code>{{text}}</code> - <?php esc_html_e( '見出しテキスト', 'section-toc-block' ); ?><br>
            <code>{{anchor}}</code> - <?php esc_html_e( 'アンカーリンク（#付きID）', 'section-toc-block' ); ?>
        </div>
        <?php
    }

    /**
     * 設定をサニタイズ
     */
    public function sanitize_settings( $input ) {
        $sanitized = array();

        // ラッパーテンプレート
        if ( isset( $input['wrapper_template'] ) ) {
            // HTMLを許可しつつ、危険なスクリプトは除去
            $sanitized['wrapper_template'] = wp_kses_post( $input['wrapper_template'] );
            // プレースホルダーを復元（wp_kses_postで削除される可能性があるため）
            if ( strpos( $input['wrapper_template'], '{{items}}' ) !== false &&
                 strpos( $sanitized['wrapper_template'], '{{items}}' ) === false ) {
                $sanitized['wrapper_template'] = $input['wrapper_template'];
            }
        }

        // 項目テンプレート
        if ( isset( $input['item_template'] ) ) {
            $sanitized['item_template'] = wp_kses_post( $input['item_template'] );
            // プレースホルダーを復元
            if ( ( strpos( $input['item_template'], '{{text}}' ) !== false ||
                   strpos( $input['item_template'], '{{anchor}}' ) !== false ) &&
                 ( strpos( $sanitized['item_template'], '{{text}}' ) === false &&
                   strpos( $sanitized['item_template'], '{{anchor}}' ) === false ) ) {
                $sanitized['item_template'] = $input['item_template'];
            }
        }

        return $sanitized;
    }

    /**
     * 設定を取得
     */
    public function get_settings() {
        $settings = get_option( 'stoc_settings', array() );
        return wp_parse_args( $settings, $this->defaults );
    }

    /**
     * デフォルト設定を取得
     */
    public function get_defaults() {
        return $this->defaults;
    }

    /**
     * GitHub API接続テスト
     *
     * @return array 接続結果
     */
    private function test_github_connection() {
        $response = wp_remote_get( Section_TOC_Block::GITHUB_API_URL, array(
            'timeout' => 10,
            'headers' => array(
                'Accept' => 'application/vnd.github.v3+json',
                'User-Agent' => 'WordPress/' . get_bloginfo( 'version' ) . '; ' . home_url(),
            ),
        ) );

        if ( is_wp_error( $response ) ) {
            return array(
                'success' => false,
                'error'   => $response->get_error_message(),
            );
        }

        $code = wp_remote_retrieve_response_code( $response );
        if ( 200 !== $code ) {
            return array(
                'success' => false,
                'error'   => sprintf( 'HTTP %d エラー', $code ),
            );
        }

        $body = json_decode( wp_remote_retrieve_body( $response ) );

        if ( empty( $body ) || ! isset( $body->tag_name ) ) {
            return array(
                'success' => false,
                'error'   => 'レスポンスの解析に失敗',
            );
        }

        return array(
            'success' => true,
            'version' => ltrim( $body->tag_name, 'vV' ),
        );
    }
}
