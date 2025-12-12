(function(wp) {
    'use strict';

    var el = wp.element.createElement;
    var registerBlockType = wp.blocks.registerBlockType;
    var useBlockProps = wp.blockEditor.useBlockProps;
    var InspectorControls = wp.blockEditor.InspectorControls;
    var PanelBody = wp.components.PanelBody;
    var SelectControl = wp.components.SelectControl;
    var Placeholder = wp.components.Placeholder;
    var useState = wp.element.useState;
    var useEffect = wp.element.useEffect;
    var useRef = wp.element.useRef;

    var config = window.sectionTocConfig || {};
    var l10n = config.l10n || {};

    // ブロックをフラット化
    function flattenBlocks(blocks) {
        var flat = [];
        if (!blocks) return flat;
        for (var i = 0; i < blocks.length; i++) {
            var block = blocks[i];
            if (block && block.name) {
                flat.push(block);
                if (block.innerBlocks && block.innerBlocks.length > 0) {
                    flat = flat.concat(flattenBlocks(block.innerBlocks));
                }
            }
        }
        return flat;
    }

    // アンカーID生成
    function generateAnchorId(text) {
        if (!text) return 'h3-' + Math.random().toString(36).substr(2, 8);
        var hash = 0;
        for (var i = 0; i < text.length; i++) {
            hash = ((hash << 5) - hash) + text.charCodeAt(i);
            hash = hash & hash;
        }
        return 'h3-' + Math.abs(hash).toString(16).substr(0, 8);
    }

    // 直上のH2を探す
    function findParentH2(clientId, flatBlocks) {
        var idx = -1;
        for (var i = 0; i < flatBlocks.length; i++) {
            if (flatBlocks[i].clientId === clientId) {
                idx = i;
                break;
            }
        }
        if (idx === -1) return null;
        for (var j = idx - 1; j >= 0; j--) {
            var b = flatBlocks[j];
            if (b.name === 'core/heading' && (b.attributes.level || 2) === 2) {
                return {
                    clientId: b.clientId,
                    text: (b.attributes.content || '').replace(/<[^>]*>/g, ''),
                    anchor: b.attributes.anchor
                };
            }
        }
        return null;
    }

    // H2配下のH3を収集
    function collectH3(h2ClientId, flatBlocks) {
        var idx = -1;
        for (var i = 0; i < flatBlocks.length; i++) {
            if (flatBlocks[i].clientId === h2ClientId) {
                idx = i;
                break;
            }
        }
        if (idx === -1) return [];
        var list = [];
        for (var j = idx + 1; j < flatBlocks.length; j++) {
            var b = flatBlocks[j];
            if (b.name === 'core/heading') {
                var level = b.attributes.level || 2;
                if (level === 2) break;
                if (level === 3) {
                    list.push({
                        clientId: b.clientId,
                        text: (b.attributes.content || '').replace(/<[^>]*>/g, ''),
                        anchor: b.attributes.anchor
                    });
                }
            }
        }
        return list;
    }

    registerBlockType('section-toc/toc-list', {
        title: l10n.blockTitle || 'Section TOC',
        description: l10n.blockDescription || 'H2配下のH3を表示',
        category: 'widgets',
        icon: 'list-view',
        supports: { html: false },
        attributes: {
            h3Items: { type: 'array', default: [] },
            listStyle: { type: 'string', default: 'disc' }
        },

        edit: function(props) {
            var clientId = props.clientId;
            var attrs = props.attributes;
            var setAttrs = props.setAttributes;

            var stateH2 = useState(null);
            var parentH2 = stateH2[0];
            var setParentH2 = stateH2[1];

            var stateH3 = useState([]);
            var h3Items = stateH3[0];
            var setH3Items = stateH3[1];

            var blockProps = useBlockProps({ className: 'section-toc-block' });

            // 前回のH3テキストを保持（変更検知用）
            var prevH3TextRef = useRef('');
            // 更新中フラグ（再入防止）
            var isUpdatingRef = useRef(false);
            // マウント状態
            var isMountedRef = useRef(true);

            // 初期実行 + 監視
            useEffect(function() {
                var debounceTimer = null;
                isMountedRef.current = true;

                function updateTOC() {
                    // 再入防止チェック
                    if (isUpdatingRef.current || !isMountedRef.current) {
                        return;
                    }

                    var blocks = wp.data.select('core/block-editor').getBlocks();
                    var flat = flattenBlocks(blocks);
                    var h2 = findParentH2(clientId, flat);

                    if (h2) {
                        var h3s = collectH3(h2.clientId, flat);

                        // H3テキストを連結して比較
                        var h3Text = h3s.map(function(h) { return h.text; }).join('|');

                        // 変更があった場合のみ更新
                        if (h3Text !== prevH3TextRef.current) {
                            prevH3TextRef.current = h3Text;
                            isUpdatingRef.current = true;

                            setParentH2(h2);
                            setH3Items(h3s);
                            setAttrs({
                                h3Items: h3s.map(function(h) {
                                    return { text: h.text, anchor: h.anchor || generateAnchorId(h.text) };
                                })
                            });

                            // 更新完了後にフラグをリセット
                            setTimeout(function() {
                                isUpdatingRef.current = false;
                            }, 300);
                        }
                    } else {
                        if (prevH3TextRef.current !== '') {
                            prevH3TextRef.current = '';
                            isUpdatingRef.current = true;

                            setParentH2(null);
                            setH3Items([]);
                            setAttrs({ h3Items: [] });

                            setTimeout(function() {
                                isUpdatingRef.current = false;
                            }, 300);
                        }
                    }
                }

                // 初期実行（少し遅延させる）
                setTimeout(updateTOC, 100);

                // デバウンス付き監視
                var unsubscribe = wp.data.subscribe(function() {
                    if (!isMountedRef.current) return;
                    clearTimeout(debounceTimer);
                    debounceTimer = setTimeout(updateTOC, 250);
                });

                return function() {
                    isMountedRef.current = false;
                    unsubscribe();
                    clearTimeout(debounceTimer);
                };
            }, [clientId]);

            // インスペクター
            var inspector = el(InspectorControls, {},
                el(PanelBody, { title: l10n.settingsTitle || '表示設定' },
                    el(SelectControl, {
                        label: l10n.listStyleLabel || 'リストスタイル',
                        value: attrs.listStyle,
                        options: [
                            { value: 'disc', label: 'ビュレット' },
                            { value: 'decimal', label: '番号付き' },
                            { value: 'none', label: 'なし' }
                        ],
                        onChange: function(v) { setAttrs({ listStyle: v }); }
                    })
                )
            );

            // H2がない
            if (!parentH2) {
                return el('div', blockProps,
                    inspector,
                    el(Placeholder, {
                        icon: 'warning',
                        label: 'Section TOC',
                        instructions: l10n.noParentH2 || 'H2見出しの直下に配置してください'
                    })
                );
            }

            // H3がない
            if (h3Items.length === 0) {
                return el('div', blockProps,
                    inspector,
                    el('div', { className: 'section-toc-empty' },
                        l10n.noH3 || 'H3見出しがありません'
                    )
                );
            }

            // リスト表示
            var tag = attrs.listStyle === 'decimal' ? 'ol' : 'ul';
            var items = h3Items.map(function(h3, i) {
                return el('li', { key: h3.clientId || i, className: 'section-toc-item' },
                    el('a', {
                        href: '#' + (h3.anchor || generateAnchorId(h3.text)),
                        onClick: function(e) {
                            e.preventDefault();
                            wp.data.dispatch('core/block-editor').selectBlock(h3.clientId);
                        }
                    }, h3.text || '(空)')
                );
            });

            return el('div', blockProps,
                inspector,
                el('nav', { className: 'section-toc-nav' },
                    el(tag, { className: 'section-toc-list section-toc-style-' + attrs.listStyle }, items)
                )
            );
        },

        save: function() {
            return null;
        }
    });

})(window.wp);
