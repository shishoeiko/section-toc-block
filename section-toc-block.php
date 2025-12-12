<?php
/**
 * Plugin Name: Section TOC Block
 * Description: H2見出し配下のH3を自動で箇条書きリストとして表示するGutenbergブロック
 * Version: 1.0.11
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: section-toc-block
 */

// 直接アクセス禁止
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// 定数定義
define( 'STOC_VERSION', '1.0.11' );
define( 'STOC_PLUGIN_FILE', __FILE__ );
define( 'STOC_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );
define( 'STOC_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'STOC_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

// クラスファイルの読み込み
require_once STOC_PLUGIN_DIR . 'includes/class-settings.php';
require_once STOC_PLUGIN_DIR . 'includes/class-render.php';
require_once STOC_PLUGIN_DIR . 'includes/class-github-updater.php';

/**
 * Section TOC Block メインクラス
 */
class Section_TOC_Block {

    const VERSION = '1.0.11';

    // GitHub自動更新用定数
    const GITHUB_USERNAME = 'shishoeiko';
    const GITHUB_REPO = 'section-toc-block';
    const GITHUB_API_URL = 'https://api.github.com/repos/shishoeiko/section-toc-block/releases/latest';
    const CACHE_KEY = 'section_toc_block_github_release';
    const CACHE_EXPIRATION = 43200; // 12時間

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
        // 各クラスを初期化
        STOC_Settings::get_instance();
        STOC_GitHub_Updater::get_instance();

        add_action( 'init', array( $this, 'register_block' ) );
        add_action( 'enqueue_block_editor_assets', array( $this, 'enqueue_editor_assets' ) );
        add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_frontend_assets' ) );

        // プラグイン一覧に設定リンクを追加
        add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), array( $this, 'add_settings_link' ) );
    }

    /**
     * プラグイン一覧に設定リンクを追加
     */
    public function add_settings_link( $links ) {
        $settings_link = '<a href="' . admin_url( 'options-general.php?page=section-toc-block-settings' ) . '">' . __( '設定', 'section-toc-block' ) . '</a>';
        array_unshift( $links, $settings_link );
        return $links;
    }

    /**
     * ブロックを登録
     */
    public function register_block() {
        register_block_type( 'section-toc/toc-list', array(
            'api_version'     => 3,
            'attributes'      => array(
                'parentH2ClientId' => array(
                    'type'    => 'string',
                    'default' => '',
                ),
                'h3Items' => array(
                    'type'    => 'array',
                    'default' => array(),
                    'items'   => array(
                        'type' => 'object',
                    ),
                ),
                'listStyle' => array(
                    'type'    => 'string',
                    'default' => 'disc',
                ),
            ),
            'render_callback' => array( 'STOC_Render', 'render_block' ),
        ) );
    }

    /**
     * エディター用アセットを読み込む
     */
    public function enqueue_editor_assets() {
        $screen = get_current_screen();

        // 投稿編集画面でのみ読み込む
        if ( ! $screen || $screen->base !== 'post' || ! $screen->is_block_editor() ) {
            return;
        }

        // JavaScript
        wp_enqueue_script(
            'section-toc-editor',
            STOC_PLUGIN_URL . 'js/editor.js',
            array(
                'wp-blocks',
                'wp-element',
                'wp-data',
                'wp-components',
                'wp-block-editor',
                'wp-compose',
                'wp-i18n',
            ),
            STOC_VERSION,
            true
        );

        // CSS
        wp_enqueue_style(
            'section-toc-editor-style',
            STOC_PLUGIN_URL . 'css/editor.css',
            array(),
            STOC_VERSION
        );

        // 翻訳用設定
        wp_localize_script( 'section-toc-editor', 'sectionTocConfig', array(
            'l10n' => array(
                'blockTitle'       => __( 'Section TOC', 'section-toc-block' ),
                'blockDescription' => __( '直上のH2配下にあるH3見出しを箇条書きでリスト表示します', 'section-toc-block' ),
                'noParentH2'       => __( 'このブロックはH2見出しの直下に配置してください。', 'section-toc-block' ),
                'noH3'             => __( 'このセクションにはH3見出しがありません。', 'section-toc-block' ),
                'emptyHeading'     => __( '(空の見出し)', 'section-toc-block' ),
                'settingsTitle'    => __( '表示設定', 'section-toc-block' ),
                'listStyleLabel'   => __( 'リストスタイル', 'section-toc-block' ),
                'styleBullet'      => __( 'ビュレット (●)', 'section-toc-block' ),
                'styleNumber'      => __( '番号付き (1. 2. 3.)', 'section-toc-block' ),
                'styleNone'        => __( 'なし', 'section-toc-block' ),
                'ariaLabel'        => __( 'セクション目次', 'section-toc-block' ),
            ),
        ) );
    }

    /**
     * フロントエンド用アセットを読み込む
     */
    public function enqueue_frontend_assets() {
        // このブロックが使用されている場合のみ読み込む
        if ( has_block( 'section-toc/toc-list' ) ) {
            wp_enqueue_style(
                'section-toc-frontend-style',
                STOC_PLUGIN_URL . 'css/frontend.css',
                array(),
                STOC_VERSION
            );

            // カスタムCSSを追加
            $settings = STOC_Settings::get_instance()->get_settings();
            if ( ! empty( $settings['custom_css'] ) ) {
                wp_add_inline_style( 'section-toc-frontend-style', $settings['custom_css'] );
            }
        }
    }
}

// プラグインを初期化
Section_TOC_Block::get_instance();
