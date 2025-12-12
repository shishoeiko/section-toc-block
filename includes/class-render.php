<?php
/**
 * Section TOC Block - フロントエンド用レンダリングクラス
 */

// 直接アクセス禁止
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class STOC_Render {

    /**
     * ブロックをレンダリング
     *
     * @param array    $attributes ブロック属性
     * @param string   $content    ブロックコンテンツ
     * @param WP_Block $block      ブロックインスタンス
     * @return string HTML出力
     */
    public static function render_block( $attributes, $content, $block ) {
        $h3_items = isset( $attributes['h3Items'] ) ? $attributes['h3Items'] : array();

        // H3がない場合は何も表示しない
        if ( empty( $h3_items ) ) {
            return '';
        }

        // 設定からテンプレートを取得
        $settings         = STOC_Settings::get_instance()->get_settings();
        $wrapper_template = $settings['wrapper_template'];
        $item_template    = $settings['item_template'];

        // 各項目をレンダリング
        $items_html = '';
        foreach ( $h3_items as $item ) {
            $text   = isset( $item['text'] ) ? $item['text'] : '';
            $anchor = isset( $item['anchor'] ) ? $item['anchor'] : '';

            if ( empty( $text ) ) {
                continue;
            }

            // アンカーが空の場合は生成
            if ( empty( $anchor ) ) {
                $anchor = self::generate_anchor_id( $text );
            }

            // 項目テンプレートにプレースホルダーを適用
            $item_html = $item_template;
            $item_html = str_replace( '{{text}}', esc_html( $text ), $item_html );
            $item_html = str_replace( '{{anchor}}', '#' . esc_attr( $anchor ), $item_html );

            $items_html .= $item_html . "\n";
        }

        // ラッパーテンプレートに項目リストを挿入
        $output = str_replace( '{{items}}', $items_html, $wrapper_template );

        return $output;
    }

    /**
     * アンカーIDを生成（日本語対応）
     * JavaScriptのgenerateAnchorIdと同じアルゴリズムを使用
     *
     * @param string $text 見出しテキスト
     * @return string アンカーID
     */
    public static function generate_anchor_id( $text ) {
        if ( empty( $text ) ) {
            return 'h3-' . substr( md5( uniqid() ), 0, 8 );
        }

        // JavaScriptと同じハッシュアルゴリズム
        // hash = ((hash << 5) - hash) + charCode (= hash * 31 + charCode)
        $hash = 0;
        $len = mb_strlen( $text, 'UTF-8' );
        for ( $i = 0; $i < $len; $i++ ) {
            $char = mb_substr( $text, $i, 1, 'UTF-8' );
            $code = self::char_code_at( $char );
            $hash = self::int32( ( $hash << 5 ) - $hash + $code );
        }

        return 'h3-' . substr( dechex( abs( $hash ) ), 0, 8 );
    }

    /**
     * 文字のUnicodeコードポイントを取得（JavaScriptのcharCodeAtと同等）
     *
     * @param string $char 1文字
     * @return int コードポイント
     */
    private static function char_code_at( $char ) {
        $ord = mb_ord( $char, 'UTF-8' );
        return $ord !== false ? $ord : 0;
    }

    /**
     * 32ビット整数としてオーバーフローを処理（JavaScriptの挙動を再現）
     *
     * @param int $value
     * @return int
     */
    private static function int32( $value ) {
        $value = $value & 0xFFFFFFFF;
        if ( $value >= 0x80000000 ) {
            $value -= 0x100000000;
        }
        return $value;
    }
}

/**
 * H3見出しにアンカーIDを自動付与するフィルター
 *
 * 投稿コンテンツ内のH3見出しにIDがない場合、自動的にアンカーIDを付与します。
 */
add_filter( 'render_block_core/heading', function( $block_content, $block ) {
    // H3以外は対象外
    $level = isset( $block['attrs']['level'] ) ? $block['attrs']['level'] : 2;
    if ( 3 !== $level ) {
        return $block_content;
    }

    // 既にIDがある場合はそのまま
    if ( preg_match( '/\sid=["\'][^"\']+["\']/', $block_content ) ) {
        return $block_content;
    }

    // コンテンツからテキストを抽出
    $text = wp_strip_all_tags( $block_content );
    $anchor = STOC_Render::generate_anchor_id( $text );

    // h3タグにIDを追加
    $block_content = preg_replace(
        '/<h3([^>]*)>/',
        '<h3$1 id="' . esc_attr( $anchor ) . '">',
        $block_content,
        1
    );

    return $block_content;
}, 10, 2 );
