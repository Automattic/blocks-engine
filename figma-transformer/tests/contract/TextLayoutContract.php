<?php

declare(strict_types=1);

function blocks_engine_figma_transformer_run_text_layout_contract(callable $assert, callable $fileContent, string $quadraticCommandBlob): void
{
    $multilineTextResult = blocks_engine_figma_transformer_transform_scenegraph(array(
        'name'  => 'Multiline Text Fixture',
        'nodes' => array(
            array(
                'id'         => 'text:multiline',
                'type'       => 'TEXT',
                'name'       => 'Checklist Text',
                'characters' => "One\nTwo\nThree",
                'fontSize'   => 16,
            ),
        ),
    ));
    $multilineTextCss = $fileContent($multilineTextResult, 'style.css');
    $assert(str_contains($multilineTextCss, '.figma-node-text-multiline-checklist-text{font-size:16px;white-space:pre-line}'), 'multiline-text-preserves-line-breaks');

    $styleTokenFontResolution = ( new \Automattic\BlocksEngine\FigmaTransformer\Html\FontResolver() )->resolve(array(
        array(
            'family' => 'Skolar Latin',
            'weights' => array(600),
            'weight_counts' => array(),
            'text_node_count' => 0,
            'visible_text_area_px' => 0,
            'sample_nodes' => array(
                array('weight' => 600, 'source' => 'materialized_css'),
            ),
        ),
    ));
    $assert(array() === ($styleTokenFontResolution['unresolved_families'] ?? null), 'style-token-only-font-usage-not-missing-css');
    $assert('style_token_only' === ($styleTokenFontResolution['coverage'][0]['resolution'] ?? null), 'style-token-only-font-resolution-reported');

    $visibleFontResolution = ( new \Automattic\BlocksEngine\FigmaTransformer\Html\FontResolver() )->resolve(array(
        array(
            'family' => 'Skolar Latin',
            'weights' => array(600),
            'text_node_count' => 1,
            'visible_text_area_px' => 1200,
            'sample_nodes' => array(
                array('node_id' => 'text:visible', 'name' => 'Visible text', 'weight' => 600),
            ),
        ),
    ));
    $assert(array('Skolar Latin') === ($visibleFontResolution['unresolved_families'] ?? null), 'visible-unresolved-font-usage-still-missing-css');

    $systemFontResolution = ( new \Automattic\BlocksEngine\FigmaTransformer\Html\FontResolver() )->resolve(array(
        array(
            'family' => 'SF Pro Text',
            'weights' => array(900),
            'text_node_count' => 1,
            'visible_text_area_px' => 1200,
            'sample_nodes' => array(
                array('node_id' => 'text:footer', 'name' => 'Footer text', 'weight' => 900),
            ),
        ),
    ));
    $assert(array() === ($systemFontResolution['unresolved_families'] ?? null), 'sf-pro-text-system-font-not-missing-css');
    $assert('web_safe' === ($systemFontResolution['coverage'][0]['resolution'] ?? null), 'sf-pro-text-system-font-resolution-reported');

    $avenirFontResolution = ( new \Automattic\BlocksEngine\FigmaTransformer\Html\FontResolver() )->resolve(array(
        array(
            'family' => 'Avenir',
            'weights' => array(400, 500, 800),
            'text_node_count' => 3,
            'visible_text_area_px' => 2400,
            'sample_nodes' => array(
                array('node_id' => 'text:avenir-regular', 'name' => 'Avenir regular', 'weight' => 400),
                array('node_id' => 'text:avenir-medium', 'name' => 'Avenir medium', 'weight' => 500),
                array('node_id' => 'text:avenir-heavy', 'name' => 'Avenir heavy', 'weight' => 800),
            ),
        ),
    ));
    $assert(array() === ($avenirFontResolution['unresolved_families'] ?? null), 'avenir-system-font-not-missing-css');
    $assert('web_safe' === ($avenirFontResolution['coverage'][0]['resolution'] ?? null), 'avenir-system-font-resolution-reported');
    $assert(false === ($avenirFontResolution['coverage'][0]['needs_operator_font'] ?? null), 'avenir-system-font-does-not-need-operator-font');
    $assert('Avenir, "Avenir Next", "Helvetica Neue", Arial, sans-serif' === ($avenirFontResolution['coverage'][0]['fallback_stack'] ?? null), 'avenir-system-font-stack-reported');

    $avenirCssResult = blocks_engine_figma_transformer_transform_scenegraph(array(
        'name'  => 'Avenir Font Fixture',
        'nodes' => array(
            array(
                'id'         => 'text:avenir-heavy',
                'type'       => 'TEXT',
                'name'       => 'Avenir Heavy',
                'characters' => 'Avenir heavy text',
                'fontName'   => array('family' => 'Avenir', 'style' => 'Heavy'),
                'fontWeight' => 800,
                'fontSize'   => 18,
            ),
        ),
    ));
    $avenirCss = $fileContent($avenirCssResult, 'style.css');
    $assert(str_contains($avenirCss, 'font-family:Avenir, "Avenir Next", "Helvetica Neue", Arial, sans-serif'), 'avenir-system-font-stack-emitted-css');
    blocks_engine_figma_transformer_contract_assert_no_quality_signal($assert, $avenirCssResult, 'font_css_missing_for_source_font', 'avenir-system-font-no-missing-css-signal');

    $derivedTextLayoutScenegraph = array(
        'name'  => 'Derived Text Layout Fixture',
        'blobs' => array(
            array('bytes' => $quadraticCommandBlob),
        ),
        'nodes' => array(
            array(
                'id'              => 'text:derived-layout',
                'type'            => 'TEXT',
                'name'            => 'Measured Text',
                'characters'      => 'A B',
                'width'           => 146.5,
                'height'          => 32.25,
                'fontSize'        => 10,
                'fontName'        => array('family' => 'Example Sans', 'style' => 'Regular'),
                'textTruncation'  => 'ENDING',
                'textWrapStyle'   => 'BALANCE',
                'maxLines'        => 2,
                'hangingList'     => true,
                'hangingPunctuation' => false,
                'hasHadRTLText'   => true,
                'textBidiVersion' => 3,
                'listSpacing'     => 8,
                'textData'        => array(
                    'lines' => array(
                        array(
                            'lineType'             => 'BULLET',
                            'styleId'              => 4,
                            'indentationLevel'     => 1,
                            'sourceDirectionality' => 'AUTO',
                            'directionality'       => 'LTR',
                            'directionalityIntent' => 'EXPLICIT',
                            'downgradeStyleId'     => 2,
                            'consistencyStyleId'   => 3,
                            'listStartOffset'      => 1,
                            'isFirstLineOfList'    => true,
                        ),
                    ),
                ),
                'derivedTextData' => array(
                    'layoutSize' => array('x' => 146.5, 'y' => 32.25),
                    'baselines'  => array(
                        array(
                            'position'       => array('x' => 0, 'y' => 20),
                            'width'          => 140,
                            'lineY'          => 0,
                            'lineHeight'     => 22,
                            'lineAscent'     => 17,
                            'firstCharacter' => 0,
                            'endCharacter'   => 17,
                        ),
                    ),
                    'glyphs' => array(
                        array('firstCharacter' => 0, 'advance' => 0.5, 'fontSize' => 10, 'x' => 2, 'y' => 3, 'commandsBlob' => 0),
                        array('firstCharacter' => 1, 'advance' => 0.5, 'fontSize' => 10, 'commandsBlob' => 0),
                        array('firstCharacter' => 2, 'advance' => 0.5, 'fontSize' => 10, 'commandsBlob' => 0),
                    ),
                    'fontMetaData' => array(
                        array(
                            'key'            => array('family' => 'Example Sans', 'style' => 'Regular'),
                            'fontLineHeight' => 1.2,
                            'fontWeight'     => 400,
                            'fontDigest'     => 'example-font-digest',
                        ),
                    ),
                    'decorations' => array(
                        array(
                            'rects'   => array(array('x' => 1, 'y' => 20, 'w' => 80, 'h' => 2)),
                            'styleID' => 4,
                        ),
                    ),
                    'hyperlinkBoxes' => array(
                        array(
                            'bounds'       => array('x' => 1, 'y' => 0, 'w' => 80, 'h' => 18),
                            'url'          => 'https://example.com/text-link',
                            'hyperlinkID'  => 9,
                            'openInNewTab' => true,
                        ),
                    ),
                    'logicalIndexToCharacterOffsetMap' => range(0, 299),
                    'derivedLines' => array(
                        array('directionality' => 'RTL'),
                    ),
                ),
            ),
        ),
    );
    $derivedTextLayoutResult = blocks_engine_figma_transformer_transform_scenegraph($derivedTextLayoutScenegraph);
    $derivedTextNormalized = ( new \Automattic\BlocksEngine\FigmaTransformer\Scenegraph\ScenegraphNormalizer() )->normalize($derivedTextLayoutScenegraph);
    $derivedTextNormalizedNode = $derivedTextNormalized['nodes'][0] ?? array();
    $derivedTextVisualNodes = $derivedTextLayoutResult['source_reports']['figma']['html']['visual_node_map'] ?? array();
    $derivedTextVisualNode = null;
    foreach ( is_array($derivedTextVisualNodes) ? $derivedTextVisualNodes : array() as $visualNode ) {
        if ( is_array($visualNode) && 'text:derived-layout' === ($visualNode['id'] ?? null) ) {
            $derivedTextVisualNode = $visualNode;
            break;
        }
    }
    $assert(true === ($derivedTextVisualNode['text']['has_derived_layout'] ?? null), 'visual-node-derived-text-layout-present');
    $assert(1 === ($derivedTextVisualNode['text']['baseline_count'] ?? null), 'visual-node-derived-text-baseline-count');
    $assert(3 === ($derivedTextVisualNode['text']['glyph_count'] ?? null), 'visual-node-derived-text-glyph-count');
    $assert(146.5 === ($derivedTextVisualNode['text']['derived_layout']['size']['width'] ?? null), 'visual-node-derived-text-layout-width');
    $assert(300 === ($derivedTextVisualNode['text']['derived_layout']['logical_character_offset_count'] ?? null), 'visual-node-derived-text-logical-offset-count');
    $assert(256 === count($derivedTextVisualNode['text']['derived_layout']['logical_character_offsets'] ?? array()), 'visual-node-derived-text-logical-offset-sample-count');
    $assert(true === ($derivedTextVisualNode['text']['derived_layout']['logical_character_offsets_truncated'] ?? null), 'visual-node-derived-text-logical-offset-truncated');
    $assert(1 === ($derivedTextVisualNode['text']['derived_layout']['line_count'] ?? null), 'visual-node-text-line-count');
    $assert('BULLET' === ($derivedTextVisualNode['text']['derived_layout']['lines'][0]['line_type'] ?? null), 'visual-node-text-line-type');
    $assert(1 === ($derivedTextVisualNode['text']['derived_layout']['lines'][0]['indentation_level'] ?? null), 'visual-node-text-line-indentation');
    $assert('LTR' === ($derivedTextVisualNode['text']['derived_layout']['lines'][0]['directionality'] ?? null), 'visual-node-text-line-directionality');
    $assert(true === ($derivedTextVisualNode['text']['derived_layout']['lines'][0]['is_first_line_of_list'] ?? null), 'visual-node-text-line-list-first');
    $assert(1 === ($derivedTextVisualNode['text']['derived_layout']['derived_line_count'] ?? null), 'visual-node-derived-text-line-count');
    $assert('RTL' === ($derivedTextVisualNode['text']['derived_layout']['derived_lines'][0]['directionality'] ?? null), 'visual-node-derived-text-line-directionality');
    $assert('example-font-digest' === ($derivedTextVisualNode['text']['derived_layout']['fonts'][0]['font_digest'] ?? null), 'visual-node-derived-text-font-digest');
    $assert(1 === ($derivedTextVisualNode['text']['derived_layout']['decoration_count'] ?? null), 'visual-node-derived-text-decoration-count');
    $assert(80.0 === ($derivedTextVisualNode['text']['derived_layout']['decorations'][0]['rects'][0]['width'] ?? null), 'visual-node-derived-text-decoration-rect-width');
    $assert(1 === ($derivedTextVisualNode['text']['derived_layout']['hyperlink_box_count'] ?? null), 'visual-node-derived-text-hyperlink-box-count');
    $assert('https://example.com/text-link' === ($derivedTextVisualNode['text']['derived_layout']['hyperlink_boxes'][0]['url'] ?? null), 'visual-node-derived-text-hyperlink-box-url');
    $assert(18.0 === ($derivedTextVisualNode['text']['derived_layout']['hyperlink_boxes'][0]['bounds']['height'] ?? null), 'visual-node-derived-text-hyperlink-box-height');
    $assert('ending' === ($derivedTextNormalizedNode['figma_text']['style']['text_truncation'] ?? null), 'normalized-text-style-truncation');
    $assert('balance' === ($derivedTextNormalizedNode['figma_text']['style']['text_wrap_style'] ?? null), 'normalized-text-style-wrap');
    $assert(true === ($derivedTextNormalizedNode['figma_text']['style']['hanging_list'] ?? null), 'normalized-text-style-hanging-list');
    $assert(false === ($derivedTextNormalizedNode['figma_text']['style']['hanging_punctuation'] ?? null), 'normalized-text-style-hanging-punctuation');
    $assert(true === ($derivedTextNormalizedNode['figma_text']['style']['has_had_rtl_text'] ?? null), 'normalized-text-style-had-rtl');
    $assert(3 === ($derivedTextNormalizedNode['figma_text']['style']['text_bidi_version'] ?? null), 'normalized-text-style-bidi-version');
    $assert(8.0 === ($derivedTextNormalizedNode['figma_text']['style']['list_spacing'] ?? null), 'normalized-text-style-list-spacing');
    $assert(! isset($derivedTextVisualNode['text']['derived_layout']['glyph_paths']), 'visual-node-derived-text-default-omits-glyph-paths');
    $assert('dom_text' === ($derivedTextVisualNode['text']['glyph_rendering'] ?? null), 'visual-node-derived-text-default-dom-rendering');
    $derivedTextLayoutCss = $fileContent($derivedTextLayoutResult, 'style.css');
    $assert(str_contains($derivedTextLayoutCss, '.figma-node-text-derived-layout-measured-text{') && str_contains($derivedTextLayoutCss, 'width:146.5px;height:32.25px') && str_contains($derivedTextLayoutCss, 'line-height:22px'), 'single-line-derived-baseline-line-height-css');
    $assert(false === ($derivedTextLayoutResult['source_reports']['figma']['html']['render_text_glyph_paths'] ?? null), 'derived-text-glyph-rendering-default-disabled');
    $assert(! str_contains($fileContent($derivedTextLayoutResult, 'index.html'), 'data-figma-text-glyphs="true"'), 'derived-text-default-avoids-glyph-svg');

    $orderedTextListResult = blocks_engine_figma_transformer_transform_scenegraph(array(
        'name'  => 'Ordered Text List Fixture',
        'nodes' => array(
            array(
                'id'         => 'text:ordered-list',
                'type'       => 'TEXT',
                'name'       => 'Coverage List',
                'characters' => "Comprehensive News Coverage\nIn-Depth Reviews\nHelpful Guides and Tutorials\nCommunity Engagement",
                'fontSize'   => 16,
                'textData'   => array(
                    'lines' => array(
                        array('lineType' => 'ORDERED', 'listStartOffset' => 3, 'isFirstLineOfList' => true),
                        array('lineType' => 'ORDERED', 'listStartOffset' => 4, 'isFirstLineOfList' => true),
                        array('lineType' => 'ORDERED', 'listStartOffset' => 5, 'isFirstLineOfList' => true),
                        array('lineType' => 'ORDERED', 'listStartOffset' => 6, 'isFirstLineOfList' => true),
                    ),
                ),
            ),
        ),
    ));
    $orderedTextListHtml = $fileContent($orderedTextListResult, 'index.html');
    $orderedTextListCss = $fileContent($orderedTextListResult, 'style.css');
    $assert(str_contains($orderedTextListHtml, '<ol class="figma-node-text-ordered-list-coverage-list" data-figma-node-id="text:ordered-list" data-figma-node-name="Coverage List" start="3">'), 'source-text-ordered-list-emits-ol-start');
    $assert(str_contains($orderedTextListHtml, '<li>Comprehensive News Coverage</li><li>In-Depth Reviews</li><li>Helpful Guides and Tutorials</li><li>Community Engagement</li>'), 'source-text-ordered-list-emits-list-items');
    blocks_engine_figma_transformer_contract_assert_tag_count($assert, $orderedTextListHtml, 'ol', 1, 'source-text-ordered-list-single-ol');
    blocks_engine_figma_transformer_contract_assert_tag_count($assert, $orderedTextListHtml, 'li', 4, 'source-text-ordered-list-li-count');
    blocks_engine_figma_transformer_contract_assert_css_rule_contains($assert, $orderedTextListCss, '.figma-node-text-ordered-list-coverage-list', array('list-style:decimal', 'padding-left:1.5em'), 'source-text-ordered-list-restores-marker-css');

    $bulletTextListResult = blocks_engine_figma_transformer_transform_scenegraph(array(
        'name'  => 'Bullet Text List Fixture',
        'nodes' => array(
            array(
                'id'         => 'text:bullet-list',
                'type'       => 'TEXT',
                'name'       => 'Bullet Coverage List',
                'characters' => "• Comprehensive News Coverage\n• In-Depth Reviews",
                'fontSize'   => 16,
                'textData'   => array(
                    'lines' => array(
                        array('lineType' => 'BULLET', 'isFirstLineOfList' => true),
                        array('lineType' => 'BULLET', 'isFirstLineOfList' => true),
                    ),
                ),
            ),
        ),
    ));
    $bulletTextListHtml = $fileContent($bulletTextListResult, 'index.html');
    $bulletTextListCss = $fileContent($bulletTextListResult, 'style.css');
    $assert(str_contains($bulletTextListHtml, '<ul class="figma-node-text-bullet-list-bullet-coverage-list" data-figma-node-id="text:bullet-list" data-figma-node-name="Bullet Coverage List"><li>Comprehensive News Coverage</li><li>In-Depth Reviews</li></ul>'), 'source-text-bullet-list-strips-embedded-marker-glyphs');
    blocks_engine_figma_transformer_contract_assert_tag_count($assert, $bulletTextListHtml, 'ul', 1, 'source-text-bullet-list-single-ul');
    blocks_engine_figma_transformer_contract_assert_tag_count($assert, $bulletTextListHtml, 'li', 2, 'source-text-bullet-list-li-count');
    blocks_engine_figma_transformer_contract_assert_css_rule_contains($assert, $bulletTextListCss, '.figma-node-text-bullet-list-bullet-coverage-list', array('list-style:disc', 'padding-left:1.5em'), 'source-text-bullet-list-restores-marker-css');

    $nestedRichTextListResult = blocks_engine_figma_transformer_transform_scenegraph(array(
        'name'  => 'Nested Source Text List Fixture',
        'nodes' => array(
            array(
                'id'       => 'text:nested-rich-list',
                'type'     => 'TEXT',
                'name'     => 'Nested Rich Coverage List',
                'fontSize' => 16,
                'fontName' => array('family' => 'Inter', 'style' => 'Regular'),
                'textData' => array(
                    'characters' => 'Intro itemNested bold noteFinal item',
                    'lines'      => array(
                        array('lineType' => 'ORDERED', 'indentationLevel' => 0, 'listStartOffset' => 2, 'isFirstLineOfList' => true),
                        array('lineType' => 'BULLET', 'indentationLevel' => 1, 'isFirstLineOfList' => true),
                        array('lineType' => 'ORDERED', 'indentationLevel' => 0, 'listStartOffset' => 3, 'isFirstLineOfList' => true),
                    ),
                ),
                'derivedTextData' => array(
                    'baselines' => array(
                        array('firstCharacter' => 0, 'endCharacter' => 10, 'position' => array('x' => 0, 'y' => 16), 'lineHeight' => 20),
                        array('firstCharacter' => 10, 'endCharacter' => 26, 'position' => array('x' => 18, 'y' => 36), 'lineHeight' => 20),
                        array('firstCharacter' => 26, 'endCharacter' => 36, 'position' => array('x' => 0, 'y' => 56), 'lineHeight' => 20),
                    ),
                    'characterStyleIDs' => array_merge(array_fill(0, 10, 0), array_fill(0, 11, 1), array_fill(0, 15, 0)),
                    'styleOverrideTable' => array(
                        array('styleID' => 1, 'fontName' => array('family' => 'Inter', 'style' => 'Bold')),
                    ),
                ),
            ),
        ),
    ));
    $nestedRichTextListHtml = $fileContent($nestedRichTextListResult, 'index.html');
    $nestedRichTextListCss = $fileContent($nestedRichTextListResult, 'style.css');
    $assert(str_contains($nestedRichTextListHtml, '<ol class="figma-node-text-nested-rich-list-nested-rich-coverage-list" data-figma-node-id="text:nested-rich-list" data-figma-node-name="Nested Rich Coverage List" start="2">'), 'source-text-nested-list-emits-root-ol-start');
    $assert(str_contains($nestedRichTextListHtml, '<li>Intro item<ul style="list-style:disc;padding-left:1.5em"><li><span style="font-weight:700">Nested bold</span> note</li></ul></li><li>Final item</li>'), 'source-text-nested-list-emits-indent-and-rich-spans');
    blocks_engine_figma_transformer_contract_assert_tag_count($assert, $nestedRichTextListHtml, 'ol', 1, 'source-text-nested-list-root-ol-count');
    blocks_engine_figma_transformer_contract_assert_tag_count($assert, $nestedRichTextListHtml, 'ul', 1, 'source-text-nested-list-child-ul-count');
    blocks_engine_figma_transformer_contract_assert_tag_count($assert, $nestedRichTextListHtml, 'li', 3, 'source-text-nested-list-li-count');
    blocks_engine_figma_transformer_contract_assert_css_rule_contains($assert, $nestedRichTextListCss, '.figma-node-text-nested-rich-list-nested-rich-coverage-list', array('list-style:decimal', 'padding-left:1.5em'), 'source-text-nested-list-root-marker-css');

    $nestedContinuationListResult = blocks_engine_figma_transformer_transform_scenegraph(array(
        'name'  => 'Nested Source Text List Continuation Fixture',
        'nodes' => array(
            array(
                'id'       => 'text:nested-continuation-list',
                'type'     => 'TEXT',
                'name'     => 'Nested Continuation List',
                'fontSize' => 16,
                'textData' => array(
                    'characters' => 'Parent item continuationNested childFinal item',
                    'lines'      => array(
                        array('lineType' => 'ORDERED', 'indentationLevel' => 0, 'listStartOffset' => 1, 'isFirstLineOfList' => true),
                        array('lineType' => 'ORDERED', 'indentationLevel' => 0, 'listStartOffset' => 1, 'isFirstLineOfList' => false),
                        array('lineType' => 'BULLET', 'indentationLevel' => 1, 'isFirstLineOfList' => true),
                        array('lineType' => 'ORDERED', 'indentationLevel' => 0, 'listStartOffset' => 2, 'isFirstLineOfList' => true),
                    ),
                ),
                'derivedTextData' => array(
                    'baselines' => array(
                        array('firstCharacter' => 0, 'endCharacter' => 11, 'position' => array('x' => 0, 'y' => 16), 'lineHeight' => 20),
                        array('firstCharacter' => 11, 'endCharacter' => 24, 'position' => array('x' => 24, 'y' => 36), 'lineHeight' => 20),
                        array('firstCharacter' => 24, 'endCharacter' => 36, 'position' => array('x' => 18, 'y' => 56), 'lineHeight' => 20),
                        array('firstCharacter' => 36, 'endCharacter' => 46, 'position' => array('x' => 0, 'y' => 76), 'lineHeight' => 20),
                    ),
                    'characterStyleIDs' => array_fill(0, 46, 0),
                ),
            ),
        ),
    ));
    $nestedContinuationListHtml = $fileContent($nestedContinuationListResult, 'index.html');
    $assert(str_contains($nestedContinuationListHtml, '<li>Parent item<br>continuation<ul style="list-style:disc;padding-left:1.5em"><li>Nested child</li></ul></li><li>Final item</li>'), 'source-text-nested-list-continuation-stays-in-parent-li');
    blocks_engine_figma_transformer_contract_assert_tag_count($assert, $nestedContinuationListHtml, 'ol', 1, 'source-text-nested-continuation-root-ol-count');
    blocks_engine_figma_transformer_contract_assert_tag_count($assert, $nestedContinuationListHtml, 'ul', 1, 'source-text-nested-continuation-child-ul-count');
    blocks_engine_figma_transformer_contract_assert_tag_count($assert, $nestedContinuationListHtml, 'li', 3, 'source-text-nested-continuation-li-count');

    $explicitNewlineFalseListResult = blocks_engine_figma_transformer_transform_scenegraph(array(
        'name'  => 'Explicit Newline False List Fixture',
        'nodes' => array(
            array(
                'id'         => 'text:explicit-newline-false-list',
                'type'       => 'TEXT',
                'name'       => 'Explicit Newline False List',
                'characters' => "One\nTwo\nThree",
                'fontSize'   => 16,
                'textData'   => array(
                    'lines' => array(
                        array('lineType' => 'BULLET', 'indentationLevel' => 1, 'isFirstLineOfList' => false),
                        array('lineType' => 'BULLET', 'indentationLevel' => 1, 'isFirstLineOfList' => false),
                        array('lineType' => 'BULLET', 'indentationLevel' => 1, 'isFirstLineOfList' => false),
                    ),
                ),
                'derivedTextData' => array(
                    'baselines' => array(
                        array('firstCharacter' => 0, 'endCharacter' => 3, 'position' => array('x' => 0, 'y' => 16), 'lineHeight' => 20),
                        array('firstCharacter' => 4, 'endCharacter' => 7, 'position' => array('x' => 0, 'y' => 36), 'lineHeight' => 20),
                        array('firstCharacter' => 8, 'endCharacter' => 13, 'position' => array('x' => 0, 'y' => 56), 'lineHeight' => 20),
                    ),
                    'characterStyleIDs' => array_fill(0, 13, 0),
                ),
            ),
        ),
    ));
    $explicitNewlineFalseListHtml = $fileContent($explicitNewlineFalseListResult, 'index.html');
    $assert(str_contains($explicitNewlineFalseListHtml, '<ul class="figma-node-text-explicit-newline-false-list-explicit-newline-false-list" data-figma-node-id="text:explicit-newline-false-list" data-figma-node-name="Explicit Newline False List"><li>One</li><li>Two</li><li>Three</li></ul>'), 'source-text-explicit-newlines-keep-false-list-lines-separate');
    blocks_engine_figma_transformer_contract_assert_tag_count($assert, $explicitNewlineFalseListHtml, 'li', 3, 'source-text-explicit-newlines-false-li-count');

    $derivedSoftWrapResult = blocks_engine_figma_transformer_transform_scenegraph(array(
        'name'  => 'Derived Soft Wrap Fixture',
        'nodes' => array(
            array(
                'id'         => 'text:derived-soft-wrap',
                'type'       => 'TEXT',
                'name'       => 'Measured Soft Wrap Heading',
                'characters' => 'We' . "\u{2019}" . 're all about Lego',
                'width'      => 342,
                'height'     => 100,
                'fontSize'   => 56,
                'derivedTextData' => array(
                    'layoutSize' => array('x' => 342, 'y' => 100),
                    'baselines'  => array(
                        array('position' => array('x' => 0, 'y' => 50), 'width' => 210, 'lineY' => 0, 'lineHeight' => 50, 'firstCharacter' => 0, 'endCharacter' => 9),
                        array('position' => array('x' => 0, 'y' => 100), 'width' => 300, 'lineY' => 50, 'lineHeight' => 50, 'firstCharacter' => 10, 'endCharacter' => 20),
                    ),
                ),
            ),
        ),
    ));
    $derivedSoftWrapCss = $fileContent($derivedSoftWrapResult, 'style.css');
    $derivedSoftWrapHtml = $fileContent($derivedSoftWrapResult, 'index.html');
    $assert(str_contains($derivedSoftWrapCss, '.figma-node-text-derived-soft-wrap-measured-soft-wrap-heading{width:342px;height:100px;font-size:56px;line-height:50px;white-space:pre}'), 'derived-soft-wrap-preserves-line-boxes-without-browser-rewrap');
    $assert(str_contains($derivedSoftWrapHtml, "We\u{2019}re all\nabout Lego"), 'derived-soft-wrap-html-keeps-measured-line-break');

    $unsupportedGlyphScenegraph = array(
        'name'  => 'Unsupported Glyph Diagnostic Fixture',
        'blobs' => array(array('bytes' => chr(9))),
        'nodes' => array(
            array(
                'id'              => 'text:unsupported-glyph-a',
                'type'            => 'TEXT',
                'characters'      => 'Unsupported glyph A',
                'derivedTextData' => array(
                    'glyphs' => array(
                        array('firstCharacter' => 0, 'commandsBlob' => 0),
                        array('firstCharacter' => 1, 'commandsBlob' => 0),
                        array('firstCharacter' => 2, 'commandsBlob' => 0),
                    ),
                ),
            ),
            array(
                'id'              => 'text:unsupported-glyph-b',
                'type'            => 'TEXT',
                'characters'      => 'Unsupported glyph B',
                'derivedTextData' => array(
                    'glyphs' => array(
                        array('firstCharacter' => 0, 'commandsBlob' => 0),
                        array('firstCharacter' => 1, 'commandsBlob' => 0),
                    ),
                ),
            ),
        ),
    );
    $unsupportedGlyphResult = blocks_engine_figma_transformer_transform_scenegraph($unsupportedGlyphScenegraph, array('render_text_glyph_paths' => true));
    $unsupportedGlyphDiagnostics = array_values(array_filter(
        $unsupportedGlyphResult['diagnostics'] ?? array(),
        static fn (array $diagnostic): bool => 'unsupported_text_glyph_command_blob' === ($diagnostic['code'] ?? null)
    ));
    $assert(1 === count($unsupportedGlyphDiagnostics), 'unsupported-glyph-diagnostics-bounded');
    $assert(5 === ($unsupportedGlyphDiagnostics[0]['context']['total_count'] ?? null), 'unsupported-glyph-diagnostics-total-count');
    $assert(2 === ($unsupportedGlyphDiagnostics[0]['context']['affected_node_count'] ?? null), 'unsupported-glyph-diagnostics-node-count');
    $assert(array('text:unsupported-glyph-a', 'text:unsupported-glyph-b') === ($unsupportedGlyphDiagnostics[0]['context']['sample_node_ids'] ?? null), 'unsupported-glyph-diagnostics-sample-node-ids');
    $assert(str_contains($fileContent($unsupportedGlyphResult, 'index.html'), 'Unsupported glyph A'), 'unsupported-glyph-diagnostics-preserve-text-a');
    $assert(str_contains($fileContent($unsupportedGlyphResult, 'index.html'), 'Unsupported glyph B'), 'unsupported-glyph-diagnostics-preserve-text-b');

    $oversizedGlyphCommandBlob = str_repeat(chr(1) . pack('g', 0.0) . pack('g', 0.0), 32769);
    $oversizedGlyphScenegraph = array(
        'name'  => 'Oversized Glyph Diagnostic Fixture',
        'blobs' => array(array('bytes' => $oversizedGlyphCommandBlob)),
        'nodes' => array(
            array(
                'id'              => 'text:oversized-glyph',
                'type'            => 'TEXT',
                'name'            => 'Oversized Glyph Text',
                'characters'      => 'Text remains renderable',
                'derivedTextData' => array(
                    'glyphs' => array(
                        array('firstCharacter' => 0, 'commandsBlob' => 0),
                    ),
                ),
            ),
        ),
    );
    $oversizedGlyphResult = blocks_engine_figma_transformer_transform_scenegraph($oversizedGlyphScenegraph, array('render_text_glyph_paths' => true));
    $oversizedGlyphDiagnostics = array_values(array_filter(
        $oversizedGlyphResult['diagnostics'] ?? array(),
        static fn (array $diagnostic): bool => 'unsupported_text_glyph_command_blob' === ($diagnostic['code'] ?? null)
    ));
    $oversizedGlyphVisualNodes = $oversizedGlyphResult['source_reports']['figma']['html']['visual_node_map'] ?? array();
    $oversizedGlyphVisualNode = null;
    foreach ( is_array($oversizedGlyphVisualNodes) ? $oversizedGlyphVisualNodes : array() as $visualNode ) {
        if ( is_array($visualNode) && 'text:oversized-glyph' === ($visualNode['id'] ?? null) ) {
            $oversizedGlyphVisualNode = $visualNode;
            break;
        }
    }
    $assert(str_contains($fileContent($oversizedGlyphResult, 'index.html'), 'Text remains renderable'), 'oversized-glyph-preserves-dom-text');
    $assert(1 === count($oversizedGlyphDiagnostics), 'oversized-glyph-diagnostics-bounded');
    $assert(1 === ($oversizedGlyphDiagnostics[0]['context']['total_count'] ?? null), 'oversized-glyph-diagnostics-total-count');
    $assert('byte_limit_exceeded' === ($oversizedGlyphDiagnostics[0]['context']['sample_glyphs'][0]['reason'] ?? null), 'oversized-glyph-diagnostics-reason');
    $assert(strlen($oversizedGlyphCommandBlob) === ($oversizedGlyphDiagnostics[0]['context']['sample_glyphs'][0]['byte_length'] ?? null), 'oversized-glyph-diagnostics-byte-length');
    $assert(array() === ($oversizedGlyphVisualNode['text']['derived_layout']['glyph_paths'] ?? array()), 'oversized-glyph-omits-derived-path-data');

    // Whitespace glyphs are emitted by Figma as a single 0x00 (empty-path) command
    // blob: well-formed, but carrying no drawable outline. These are valid, not
    // unsupported, and must NOT raise unsupported_text_glyph_command_blob warnings
    // (the FSE Pilot footer text "Proudly powered by WordPress.com" produced
    // thousands of these false-positive warnings before this gate).
    $emptyGlyphCommandBlob = chr(0);
    $whitespaceGlyphScenegraph = array(
        'name'  => 'Whitespace Glyph Empty-Path Fixture',
        'blobs' => array(
            array('bytes' => $quadraticCommandBlob),
            array('bytes' => $emptyGlyphCommandBlob),
        ),
        'nodes' => array(
            array(
                'id'              => 'text:whitespace-glyph',
                'type'            => 'TEXT',
                'name'            => 'Measured Whitespace Text',
                'characters'      => 'A B',
                'width'           => 146.5,
                'height'          => 32.25,
                'fontSize'        => 10,
                'fontName'        => array('family' => 'Example Sans', 'style' => 'Regular'),
                'derivedTextData' => array(
                    'layoutSize' => array('x' => 146.5, 'y' => 32.25),
                    'glyphs' => array(
                        array('firstCharacter' => 0, 'advance' => 0.5, 'fontSize' => 10, 'x' => 2, 'y' => 3, 'commandsBlob' => 0),
                        array('firstCharacter' => 1, 'advance' => 0.5, 'fontSize' => 10, 'commandsBlob' => 1),
                        array('firstCharacter' => 2, 'advance' => 0.5, 'fontSize' => 10, 'commandsBlob' => 0),
                    ),
                ),
            ),
        ),
    );
    $whitespaceGlyphResult = blocks_engine_figma_transformer_transform_scenegraph($whitespaceGlyphScenegraph, array('render_text_glyph_paths' => true));
    $whitespaceGlyphDiagnostics = array_values(array_filter(
        $whitespaceGlyphResult['diagnostics'] ?? array(),
        static fn (array $diagnostic): bool => 'unsupported_text_glyph_command_blob' === ($diagnostic['code'] ?? null)
    ));
    $assert(0 === count($whitespaceGlyphDiagnostics), 'whitespace-glyph-empty-path-no-warning');
    $assert(str_contains($fileContent($whitespaceGlyphResult, 'index.html'), 'A B'), 'whitespace-glyph-preserves-text');
    $whitespaceGlyphVisualNodes = $whitespaceGlyphResult['source_reports']['figma']['html']['visual_node_map'] ?? array();
    $whitespaceGlyphVisualNode = null;
    foreach ( is_array($whitespaceGlyphVisualNodes) ? $whitespaceGlyphVisualNodes : array() as $visualNode ) {
        if ( is_array($visualNode) && 'text:whitespace-glyph' === ($visualNode['id'] ?? null) ) {
            $whitespaceGlyphVisualNode = $visualNode;
            break;
        }
    }
    $whitespaceGlyphPaths = $whitespaceGlyphVisualNode['text']['derived_layout']['glyph_paths'] ?? array();
    $whitespaceGlyphPathData = array_values(array_filter(array_map(
        static fn ($glyphPath): ?string => is_array($glyphPath) && isset($glyphPath['data']) ? (string) $glyphPath['data'] : null,
        is_array($whitespaceGlyphPaths) ? $whitespaceGlyphPaths : array()
    )));
    $assert(array('M 0 0 Q 4 8 8 0 Z', 'M 0 0 Q 4 8 8 0 Z') === $whitespaceGlyphPathData, 'whitespace-glyph-keeps-drawable-paths-only');
    
    $derivedTextGlyphResult = blocks_engine_figma_transformer_transform_scenegraph($derivedTextLayoutScenegraph, array('render_text_glyph_paths' => true));
    $derivedTextGlyphHtml = $fileContent($derivedTextGlyphResult, 'index.html');
    $derivedTextGlyphCss = $fileContent($derivedTextGlyphResult, 'style.css');
    $derivedTextGlyphVisualNodes = $derivedTextGlyphResult['source_reports']['figma']['html']['visual_node_map'] ?? array();
    $derivedTextGlyphVisualNode = null;
    foreach ( is_array($derivedTextGlyphVisualNodes) ? $derivedTextGlyphVisualNodes : array() as $visualNode ) {
        if ( is_array($visualNode) && 'text:derived-layout' === ($visualNode['id'] ?? null) ) {
            $derivedTextGlyphVisualNode = $visualNode;
            break;
        }
    }
    $assert(str_contains($derivedTextGlyphHtml, 'data-figma-text-glyphs="true"'), 'derived-text-glyph-svg-emitted');
    $assert(str_contains($derivedTextGlyphHtml, 'aria-label="A B"'), 'derived-text-glyph-svg-label');
    $assert(str_contains($derivedTextGlyphHtml, 'd="M 0 0 Q 4 8 8 0 Z"'), 'derived-text-glyph-svg-path');
    $assert(2 === substr_count($derivedTextGlyphHtml, 'd="M 0 0 Q 4 8 8 0 Z"'), 'derived-text-glyph-svg-preserves-drawable-path-count');
    $assert(str_contains($derivedTextGlyphHtml, 'transform="translate(2 3) scale(10 -10)"'), 'derived-text-glyph-svg-position');
    $assert(str_contains($derivedTextGlyphHtml, 'transform="translate(10 20) scale(10 -10)"'), 'derived-text-glyph-svg-advance-through-space');
    $assert(! str_contains($derivedTextGlyphHtml, 'transform="translate(5 20) scale(10 -10)"'), 'derived-text-glyph-svg-skips-space-path');
    $assert(str_contains($derivedTextGlyphCss, '.figma-text-glyphs{display:block;width:100%;height:100%;overflow:visible}'), 'derived-text-glyph-svg-css');
    $assert('svg_paths' === ($derivedTextGlyphVisualNode['text']['glyph_rendering'] ?? null), 'visual-node-derived-text-glyph-rendering-mode');
    
    $symbolicTextGlyphResult = blocks_engine_figma_transformer_transform_scenegraph(array(
        'name'  => 'Symbolic Text Glyph Fallback Fixture',
        'blobs' => array(array('bytes' => $quadraticCommandBlob)),
        'nodes' => array(
            array(
                'id'              => 'text:symbolic-glyph',
                'type'            => 'TEXT',
                'name'            => 'Checklist Text',
                'characters'      => "✔ Included\n✖ Excluded",
                'width'           => 120,
                'height'          => 48,
                'fontSize'        => 16,
                'derivedTextData' => array(
                    'layoutSize' => array('x' => 120, 'y' => 48),
                    'glyphs'     => array(array('firstCharacter' => 0, 'advance' => 1, 'fontSize' => 16, 'commandsBlob' => 0)),
                ),
            ),
        ),
    ), array('render_text_glyph_paths' => true));
    $symbolicTextGlyphHtml = $fileContent($symbolicTextGlyphResult, 'index.html');
    $symbolicTextGlyphVisualNode = null;
    foreach ( $symbolicTextGlyphResult['source_reports']['figma']['html']['visual_node_map'] ?? array() as $visualNode ) {
        if ( is_array($visualNode) && 'text:symbolic-glyph' === ($visualNode['id'] ?? null) ) {
            $symbolicTextGlyphVisualNode = $visualNode;
            break;
        }
    }
    $assert(str_contains($symbolicTextGlyphHtml, "✔ Included\n✖ Excluded"), 'symbolic-text-glyph-fallback-renders-dom-text');
    $assert(! str_contains($symbolicTextGlyphHtml, 'data-figma-text-glyphs="true"'), 'symbolic-text-glyph-fallback-avoids-svg-paths');
    $assert('dom_text' === ($symbolicTextGlyphVisualNode['text']['glyph_rendering'] ?? null), 'symbolic-text-glyph-fallback-visual-metadata-dom');
    
    $paragraphTextGlyphResult = blocks_engine_figma_transformer_transform_scenegraph(array(
        'name'  => 'Paragraph Text Glyph Fallback Fixture',
        'blobs' => array(array('bytes' => $quadraticCommandBlob)),
        'nodes' => array(
            array(
                'id'              => 'text:paragraph-glyph',
                'type'            => 'TEXT',
                'name'            => 'Paragraph copy',
                'characters'      => 'This longer paragraph copy should remain real DOM text instead of SVG glyph paths because it needs browser text flow and selection.',
                'width'           => 240,
                'height'          => 80,
                'fontSize'        => 16,
                'derivedTextData' => array(
                    'layoutSize' => array('x' => 240, 'y' => 80),
                    'glyphs'     => array(array('firstCharacter' => 0, 'advance' => 1, 'fontSize' => 16, 'commandsBlob' => 0)),
                ),
            ),
        ),
    ), array('render_text_glyph_paths' => true));
    $paragraphTextGlyphHtml = $fileContent($paragraphTextGlyphResult, 'index.html');
    $assert(str_contains($paragraphTextGlyphHtml, 'This longer paragraph copy should remain real DOM text'), 'paragraph-text-glyph-fallback-renders-dom-text');
    $assert(! str_contains($paragraphTextGlyphHtml, 'data-figma-text-glyphs="true"'), 'paragraph-text-glyph-fallback-avoids-svg-paths');
    
    $sentenceTextGlyphResult = blocks_engine_figma_transformer_transform_scenegraph(array(
        'name'  => 'Sentence Text Glyph Fallback Fixture',
        'blobs' => array(array('bytes' => $quadraticCommandBlob)),
        'nodes' => array(
            array(
                'id'              => 'text:sentence-glyph',
                'type'            => 'TEXT',
                'name'            => 'Sentence copy',
                'characters'      => 'Sentence-style body copy should remain DOM text.',
                'width'           => 240,
                'height'          => 32,
                'fontSize'        => 16,
                'derivedTextData' => array(
                    'layoutSize' => array('x' => 240, 'y' => 32),
                    'glyphs'     => array(array('firstCharacter' => 0, 'advance' => 1, 'fontSize' => 16, 'commandsBlob' => 0)),
                ),
            ),
        ),
    ), array('render_text_glyph_paths' => true));
    $sentenceTextGlyphHtml = $fileContent($sentenceTextGlyphResult, 'index.html');
    $assert(str_contains($sentenceTextGlyphHtml, 'Sentence-style body copy should remain DOM text.'), 'sentence-text-glyph-fallback-renders-dom-text');
    $assert(! str_contains($sentenceTextGlyphHtml, 'data-figma-text-glyphs="true"'), 'sentence-text-glyph-fallback-avoids-svg-paths');
    
    $multilineHeadingGlyphResult = blocks_engine_figma_transformer_transform_scenegraph(array(
        'name'  => 'Multiline Heading Glyph Fixture',
        'blobs' => array(array('bytes' => $quadraticCommandBlob)),
        'nodes' => array(
            array(
                'id'              => 'text:multiline-heading-glyph',
                'type'            => 'TEXT',
                'name'            => 'Short Wrapped Heading',
                'characters'      => "Short\nwrapped\nheading text",
                'width'           => 120,
                'height'          => 90,
                'style'           => array('fontWeight' => 700),
                'derivedTextData' => array(
                    'baselines' => array(
                        array('firstCharacter' => 0, 'endCharacter' => 5, 'position' => array('x' => 0, 'y' => 20)),
                        array('firstCharacter' => 6, 'endCharacter' => 13, 'position' => array('x' => 0, 'y' => 45)),
                        array('firstCharacter' => 14, 'endCharacter' => 26, 'position' => array('x' => 0, 'y' => 70)),
                    ),
                    'glyphs' => array(
                        array('firstCharacter' => 0, 'advance' => 1, 'fontSize' => 20, 'commandsBlob' => 0),
                        array('firstCharacter' => 6, 'advance' => 1, 'fontSize' => 20, 'commandsBlob' => 0),
                        array('firstCharacter' => 14, 'advance' => 1, 'fontSize' => 20, 'commandsBlob' => 0),
                    ),
                ),
            ),
        ),
    ), array('render_text_glyph_paths' => true));
    $multilineHeadingGlyphHtml = $fileContent($multilineHeadingGlyphResult, 'index.html');
    $assert(str_contains($multilineHeadingGlyphHtml, "aria-label=\"Short\nwrapped\nheading text\""), 'multiline-heading-glyph-renders-svg-label');
    $assert(str_contains($multilineHeadingGlyphHtml, 'data-figma-text-glyphs="true"'), 'multiline-heading-glyph-renders-svg');
    
    $multilineLargeDisplayGlyphResult = blocks_engine_figma_transformer_transform_scenegraph(array(
        'name'  => 'Multiline Large Display Glyph Fixture',
        'blobs' => array(array('bytes' => $quadraticCommandBlob)),
        'nodes' => array(
            array(
                'id'              => 'text:multiline-large-display-glyph',
                'type'            => 'TEXT',
                'name'            => 'Large Wrapped Display',
                'characters'      => "Large\nwrapped",
                'width'           => 160,
                'height'          => 80,
                'fontSize'        => 34,
                'style'           => array('fontWeight' => 400),
                'derivedTextData' => array(
                    'baselines' => array(
                        array('firstCharacter' => 0, 'endCharacter' => 5, 'position' => array('x' => 0, 'y' => 34)),
                        array('firstCharacter' => 6, 'endCharacter' => 13, 'position' => array('x' => 0, 'y' => 72)),
                    ),
                    'glyphs' => array(
                        array('firstCharacter' => 0, 'advance' => 1, 'fontSize' => 34, 'commandsBlob' => 0),
                        array('firstCharacter' => 6, 'advance' => 1, 'fontSize' => 34, 'commandsBlob' => 0),
                    ),
                ),
            ),
        ),
    ), array('render_text_glyph_paths' => true));
    $multilineLargeDisplayGlyphHtml = $fileContent($multilineLargeDisplayGlyphResult, 'index.html');
    $assert(str_contains($multilineLargeDisplayGlyphHtml, "aria-label=\"Large\nwrapped\""), 'multiline-large-display-glyph-renders-svg-label');
    $assert(str_contains($multilineLargeDisplayGlyphHtml, 'data-figma-text-glyphs="true"'), 'multiline-large-display-glyph-renders-svg');
    
    $multilineCopyGlyphResult = blocks_engine_figma_transformer_transform_scenegraph(array(
        'name'  => 'Multiline Copy Glyph Fixture',
        'blobs' => array(array('bytes' => $quadraticCommandBlob)),
        'nodes' => array(
            array(
                'id'              => 'text:multiline-copy-glyph',
                'type'            => 'TEXT',
                'name'            => 'Wrapped Copy',
                'characters'      => "Short\nwrapped\ncopy",
                'width'           => 120,
                'height'          => 60,
                'style'           => array('fontWeight' => 400),
                'derivedTextData' => array(
                    'baselines' => array(
                        array('firstCharacter' => 0, 'endCharacter' => 5, 'position' => array('x' => 0, 'y' => 20)),
                        array('firstCharacter' => 6, 'endCharacter' => 13, 'position' => array('x' => 0, 'y' => 45)),
                    ),
                    'glyphs' => array(
                        array('firstCharacter' => 0, 'advance' => 1, 'fontSize' => 20, 'commandsBlob' => 0),
                        array('firstCharacter' => 6, 'advance' => 1, 'fontSize' => 20, 'commandsBlob' => 0),
                    ),
                ),
            ),
        ),
    ), array('render_text_glyph_paths' => true));
    $multilineCopyGlyphHtml = $fileContent($multilineCopyGlyphResult, 'index.html');
    $assert(str_contains($multilineCopyGlyphHtml, "Short\nwrapped\ncopy"), 'multiline-copy-glyph-fallback-renders-dom-text');
    $assert(! str_contains($multilineCopyGlyphHtml, 'data-figma-text-glyphs="true"'), 'multiline-copy-glyph-fallback-avoids-svg');
    
    $derivedLineBreakResult = blocks_engine_figma_transformer_transform_scenegraph(array(
        'name'  => 'Derived Line Break Fixture',
        'nodes' => array(
            array(
                'id'              => 'text:derived-lines',
                'type'            => 'TEXT',
                'name'            => 'Measured Lines',
                'characters'      => 'First line Second line',
                'width'           => 120,
                'height'          => 44,
                'lineHeightPx'    => 40,
                'derivedTextData' => array(
                    'layoutSize' => array('x' => 120, 'y' => 44),
                    'baselines'  => array(
                        array('firstCharacter' => 0, 'endCharacter' => 10, 'position' => array('x' => 0, 'y' => 16)),
                        array('firstCharacter' => 11, 'endCharacter' => 22, 'position' => array('x' => 0, 'y' => 38)),
                    ),
                ),
            ),
        ),
    ));
    $derivedLineBreakHtml = $fileContent($derivedLineBreakResult, 'index.html');
    $derivedLineBreakCss = $fileContent($derivedLineBreakResult, 'style.css');
    $assert(str_contains($derivedLineBreakHtml, "First line\nSecond line"), 'derived-baselines-insert-line-breaks');
    $assert(str_contains($derivedLineBreakCss, '.figma-node-text-derived-lines-measured-lines{width:120px;height:44px;line-height:22px;white-space:pre}'), 'derived-baselines-enable-pre');
    $assert(! str_contains($derivedLineBreakCss, 'line-height:40px;line-height:22px'), 'derived-baselines-replace-source-line-height');
    
    $derivedHugTextHeightResult = blocks_engine_figma_transformer_transform_scenegraph(array(
        'name'  => 'Derived Hug Text Height Fixture',
        'nodes' => array(
            array(
                'id'       => 'frame:derived-hug-text-height',
                'type'     => 'FRAME',
                'name'     => 'Measured Text Stack',
                'width'    => 320,
                'height'   => 140,
                'layoutMode' => 'VERTICAL',
                'itemSpacing' => 32,
                'children' => array(
                    array(
                        'id'              => 'text:derived-hug-text-height',
                        'type'            => 'TEXT',
                        'name'            => 'Trimmed Heading',
                        'characters'      => 'PAge not found',
                        'width'           => 320,
                        'height'          => 86,
                        'fontSize'        => 128,
                        'lineHeight'      => array('value' => 0.9, 'units' => 'RAW'),
                        'textAutoResize'  => 'HEIGHT',
                        'derivedTextData' => array(
                            'layoutSize' => array('x' => 320, 'y' => 86),
                            'baselines'  => array(
                                array('firstCharacter' => 0, 'endCharacter' => 14, 'lineHeight' => 115.2, 'lineAscent' => 96, 'position' => array('x' => 0, 'y' => 81.4)),
                            ),
                        ),
                    ),
                    array(
                        'id'         => 'text:derived-hug-copy',
                        'type'       => 'TEXT',
                        'name'       => 'Copy',
                        'characters' => 'Follow-up copy',
                        'width'      => 320,
                        'height'     => 20,
                        'fontSize'   => 16,
                    ),
                ),
            ),
        ),
    ));
    $derivedHugTextHeightCss = $fileContent($derivedHugTextHeightResult, 'style.css');
    $assert(str_contains($derivedHugTextHeightCss, '.figma-node-text-derived-hug-text-height-trimmed-heading{') && str_contains($derivedHugTextHeightCss, 'width:320px;height:86px') && str_contains($derivedHugTextHeightCss, 'font-size:128px') && str_contains($derivedHugTextHeightCss, 'line-height:115.2px'), 'derived-hug-text-height-preserves-measured-box');
    $assert(! str_contains($derivedHugTextHeightCss, '.figma-node-text-derived-hug-text-height-trimmed-heading{width:320px;height:fit-content'), 'derived-hug-text-height-not-fit-content');
    $assert(str_contains($derivedHugTextHeightCss, '.figma-node-frame-derived-hug-text-height-measured-text-stack{width:320px;height:140px;display:flex;flex-direction:column;gap:32px}'), 'derived-hug-text-height-parent-gap-preserved');
    
    $derivedMeasuredLineHeightResult = blocks_engine_figma_transformer_transform_scenegraph(array(
        'name'  => 'Derived Measured Line Height Fixture',
        'nodes' => array(
            array(
                'id'              => 'text:derived-measured-line-height',
                'type'            => 'TEXT',
                'name'            => 'Measured Line Height',
                'characters'      => 'First line Second line',
                'width'           => 120,
                'height'          => 40,
                'lineHeightPx'    => 28,
                'derivedTextData' => array(
                    'layoutSize' => array('x' => 120, 'y' => 40),
                    'baselines'  => array(
                        array('firstCharacter' => 0, 'endCharacter' => 10, 'lineHeight' => 20, 'position' => array('x' => 0, 'y' => 15)),
                        array('firstCharacter' => 11, 'endCharacter' => 22, 'lineHeight' => 20, 'position' => array('x' => 0, 'y' => 38)),
                    ),
                ),
            ),
        ),
    ));
    $derivedMeasuredLineHeightCss = $fileContent($derivedMeasuredLineHeightResult, 'style.css');
    $derivedMeasuredLineHeightDiagnostics = $derivedMeasuredLineHeightResult['source_reports']['figma']['html']['node_style_diagnostics'] ?? array();
    $derivedMeasuredLineHeightDiagnostic = null;
    foreach ( is_array($derivedMeasuredLineHeightDiagnostics) ? $derivedMeasuredLineHeightDiagnostics : array() as $styleDiagnostic ) {
        if ( 'text:derived-measured-line-height' === ($styleDiagnostic['node']['id'] ?? null) ) {
            $derivedMeasuredLineHeightDiagnostic = $styleDiagnostic;
        }
    }
    $assert(str_contains($derivedMeasuredLineHeightCss, '.figma-node-text-derived-measured-line-height-measured-line-height{width:120px;height:40px;line-height:23px;white-space:pre}'), 'derived-baselines-prefer-position-delta-line-height');
    $assert('23px' === ($derivedMeasuredLineHeightDiagnostic['expected']['line_height'] ?? null), 'derived-baselines-measured-line-height-expected-diagnostic');
    $assert('23px' === ($derivedMeasuredLineHeightDiagnostic['emitted']['line_height'] ?? null), 'derived-baselines-measured-line-height-emitted-diagnostic');
    $assert(array() === ($derivedMeasuredLineHeightDiagnostic['mismatches'] ?? null), 'derived-baselines-measured-line-height-no-diagnostic-mismatch');

    $singleLineButtonLabelResult = blocks_engine_figma_transformer_transform_scenegraph(array(
        'name'  => 'Single Line Button Label Fixture',
        'nodes' => array(
            array(
                'id'              => 'text:single-line-button-label',
                'type'            => 'TEXT',
                'name'            => 'Button Label',
                'characters'      => 'Submit',
                'width'           => 60,
                'height'          => 12,
                'fontSize'        => 16,
                'lineHeightPx'    => 24,
                'derivedTextData' => array(
                    'layoutSize' => array('x' => 60, 'y' => 12),
                    'baselines'  => array(
                        array('firstCharacter' => 0, 'endCharacter' => 6, 'lineY' => -6, 'lineHeight' => 24, 'lineAscent' => 18, 'position' => array('x' => 0, 'y' => 10)),
                    ),
                ),
            ),
        ),
    ));
    $singleLineButtonLabelCss = $fileContent($singleLineButtonLabelResult, 'style.css');
    $assert(str_contains($singleLineButtonLabelCss, '.figma-node-text-single-line-button-label-button-label{width:60px;font-size:16px;line-height:24px}'), 'single-line-button-label-avoids-fixed-tiny-height');
    $assert(! str_contains($singleLineButtonLabelCss, '.figma-node-text-single-line-button-label-button-label{width:60px;height:12px'), 'single-line-button-label-no-overflowing-fixed-height');

    $hugButtonLabelResult = blocks_engine_figma_transformer_transform_scenegraph(array(
        'name'  => 'Hug Button Label Fixture',
        'nodes' => array(
            array(
                'id'                    => 'text:hug-button',
                'type'                  => 'FRAME',
                'name'                  => 'Button',
                'width'                 => 73,
                'height'                => 36,
                'layoutMode'            => 'HORIZONTAL',
                'counterAxisAlignItems' => 'CENTER',
                'paddingTop'            => 12,
                'paddingRight'          => 16,
                'paddingBottom'         => 12,
                'paddingLeft'           => 16,
                'itemSpacing'           => 8,
                'cornerRadius'          => 999,
                'fills'                 => array(array('type' => 'SOLID', 'color' => array('r' => 1, 'g' => 1, 'b' => 1, 'a' => 1))),
                'children'              => array(
                    array(
                        'id'              => 'text:hug-button-label',
                        'type'            => 'TEXT',
                        'name'            => 'Reply',
                        'characters'      => 'Reply',
                        'x'               => 16,
                        'y'               => 12,
                        'width'           => 39,
                        'height'          => 10,
                        'fontSize'        => 14,
                        'lineHeightPx'    => 22,
                        'derivedTextData' => array(
                            'layoutSize' => array('x' => 39, 'y' => 10),
                            'baselines'  => array(
                                array('firstCharacter' => 0, 'endCharacter' => 5, 'lineY' => -6.282, 'lineHeight' => 22, 'lineAscent' => 14, 'position' => array('x' => 0, 'y' => 10.43)),
                            ),
                        ),
                    ),
                ),
            ),
        ),
    ));
    $hugButtonLabelCss = $fileContent($hugButtonLabelResult, 'style.css');
    $assert(str_contains($hugButtonLabelCss, '.figma-node-text-hug-button-button{width:73px;height:36px;') && str_contains($hugButtonLabelCss, 'align-items:center'), 'hug-button-label-parent-keeps-figma-height');
    $assert(str_contains($hugButtonLabelCss, '.figma-node-text-hug-button-label-reply{width:39px;font-size:14px;line-height:22px;flex-shrink:0}'), 'centered-button-label-uses-line-box-height');
    $assert(! str_contains($hugButtonLabelCss, '.figma-node-text-hug-button-label-reply{width:39px;height:10px'), 'centered-button-label-no-tiny-measured-height');

    $startAlignedFlexLabelResult = blocks_engine_figma_transformer_transform_scenegraph(array(
        'name'  => 'Start Aligned Flex Label Fixture',
        'nodes' => array(
            array(
                'id'            => 'text:start-flex-button',
                'type'          => 'FRAME',
                'name'          => 'Button',
                'width'         => 73,
                'height'        => 36,
                'layoutMode'    => 'HORIZONTAL',
                'paddingTop'    => 12,
                'paddingRight'  => 16,
                'paddingBottom' => 12,
                'paddingLeft'   => 16,
                'itemSpacing'   => 8,
                'children'      => array(
                    array(
                        'id'              => 'text:start-flex-button-label',
                        'type'            => 'TEXT',
                        'name'            => 'Reply',
                        'characters'      => 'Reply',
                        'width'           => 39,
                        'height'          => 10,
                        'fontSize'        => 14,
                        'lineHeightPx'    => 22,
                        'derivedTextData' => array(
                            'layoutSize' => array('x' => 39, 'y' => 10),
                            'baselines'  => array(
                                array('firstCharacter' => 0, 'endCharacter' => 5, 'lineY' => -6.282, 'lineHeight' => 22, 'lineAscent' => 14, 'position' => array('x' => 0, 'y' => 10.43)),
                            ),
                        ),
                    ),
                ),
            ),
        ),
    ));
    $startAlignedFlexLabelCss = $fileContent($startAlignedFlexLabelResult, 'style.css');
    $assert(str_contains($startAlignedFlexLabelCss, '.figma-node-text-start-flex-button-label-reply{width:39px;height:10px;font-size:14px;line-height:22px;overflow:visible;flex-shrink:0}'), 'non-centered-flex-label-keeps-measured-height');

    $atomicMetadataResult = blocks_engine_figma_transformer_transform_scenegraph(array(
        'name'  => 'Atomic Metadata Text Fixture',
        'nodes' => array(
            array(
                'id'       => 'atomic:row',
                'type'     => 'FRAME',
                'name'     => 'Post metadata row',
                'width'    => 376,
                'height'   => 21,
                'layout'   => array('display' => 'flex', 'flex_direction' => 'row'),
                'children' => array(
                    array(
                        'id'                      => 'atomic:date',
                        'type'                    => 'TEXT',
                        'name'                    => 'Supporting text',
                        'characters'              => 'Dec 9, 2023',
                        'width'                   => 89,
                        'height'                  => 21,
                        'fontSize'                => 14,
                        'fontWeight'              => 800,
                        'lineHeightPx'            => 21,
                        'layout'                  => array('sizing_horizontal' => 'HUG'),
                        'derivedTextData'         => array(
                            'layoutSize' => array('x' => 88.8125, 'y' => 21),
                            'baselines'  => array(array('firstCharacter' => 0, 'endCharacter' => 11, 'lineHeight' => 21, 'position' => array('x' => 0, 'y' => 16))),
                        ),
                    ),
                ),
            ),
        ),
    ));
    $atomicMetadataCss = $fileContent($atomicMetadataResult, 'style.css');
    $assert(str_contains($atomicMetadataCss, '.figma-node-atomic-date-supporting-text{width:88.812px;height:21px;font-size:14px;font-weight:800;line-height:21px;white-space:nowrap'), 'atomic-single-line-metadata-nowrap');

}

function blocks_engine_figma_transformer_run_text_style_contract(callable $assert, callable $fileContent, callable $artifactQualitySignal, callable $artifactQualitySignalCodes): void
{
    $metadataResult = blocks_engine_figma_transformer_transform_scenegraph(array(
        'name'  => 'Text And Paint Metadata',
        'nodes' => array(
            array(
                'id'           => '4:1',
                'type'         => 'FRAME',
                'name'         => 'Metadata frame',
                'opacity'      => 0.75,
                'cornerRadius' => 12,
                'fills'        => array(
                    array('type' => 'SOLID', 'color' => array('r' => 0.2, 'g' => 0.4, 'b' => 0.6), 'opacity' => 0.5),
                    array('type' => 'GRADIENT_LINEAR'),
                ),
                'strokes'      => array(
                    array('type' => 'SOLID', 'color' => array('r' => 0, 'g' => 0, 'b' => 0)),
                ),
                'strokeWeight' => 2,
                'effects'      => array(
                    array('type' => 'DROP_SHADOW'),
                ),
                'children'     => array(
                    array(
                        'id'                 => '4:2',
                        'type'               => 'TEXT',
                        'name'               => 'Mixed text',
                        'characters'         => 'Hello World',
                        'style'              => array(
                            'fontFamily'         => 'Example Sans',
                            'fontSize'           => 20,
                            'fontWeight'         => 600,
                            'fontVariations'     => array(array('axisName' => 'wdth', 'value' => 85)),
                            'fontVariantCommonLigatures' => false,
                            'toggledOnOTFeatures' => array('kern'),
                            'lineHeightPercent'  => 125,
                            'letterSpacing'      => 0.5,
                            'textAlignHorizontal'=> 'CENTER',
                            'textAlignVertical'  => 'TOP',
                            'textDecoration'     => 'UNDERLINE',
                        ),
                        'fills'              => array(
                            array('type' => 'SOLID', 'color' => array('r' => 1, 'g' => 0.5, 'b' => 0), 'opacity' => 0.8),
                        ),
                        'styledTextSegments' => array(
                            array('characters' => 'Hello ', 'style' => array('fontWeight' => 400)),
                            array('characters' => 'World', 'style' => array('fontWeight' => 700, 'textDecoration' => 'UNDERLINE')),
                        ),
                    ),
                    array(
                        'id'                => '4:3',
                        'type'              => 'RECTANGLE',
                        'name'              => 'Uneven radius',
                        'topLeftRadius'     => 4,
                        'topRightRadius'    => 8,
                        'bottomRightRadius' => 12,
                        'bottomLeftRadius'  => 16,
                        'fills'             => array(
                            array('type' => 'GRADIENT_RADIAL'),
                        ),
                    ),
                    array(
                        'id'         => '4:4',
                        'type'       => 'TEXT',
                        'name'       => 'Raw line height text',
                        'characters' => 'Raw line height',
                        'fontName'   => array('family' => 'Example Sans', 'style' => 'SemiBold'),
                        'fontSize'   => 18,
                        'lineHeight' => array('units' => 'RAW', 'value' => 1.15),
                    ),
                    array(
                        'id'            => '4:5',
                        'type'          => 'TEXT',
                        'name'          => 'WP Cloud text metrics',
                        'characters'    => 'WordPress with no worries',
                        'fontName'      => array('family' => 'DM Sans', 'style' => 'Bold'),
                        'fontSize'      => 80,
                        'lineHeight'    => array('units' => 'RAW', 'value' => 1.05),
                        'letterSpacing' => array('units' => 'PERCENT', 'value' => -2),
                    ),
                    array(
                        'id'         => '4:6',
                        'type'       => 'TEXT',
                        'name'       => 'Zero line height text',
                        'characters' => 'Navigation item',
                        'fontName'   => array('family' => 'Example Sans', 'style' => 'Regular'),
                        'fontSize'   => 16,
                        'lineHeight' => array('units' => 'RAW', 'value' => 0),
                    ),
                ),
            ),
        ),
    ));
    $metadataWithFontCssResult = blocks_engine_figma_transformer_transform_scenegraph(array(
        'name'  => 'Font CSS Fixture',
        'nodes' => array(
            array(
                'id'         => 'font:1',
                'type'       => 'TEXT',
                'name'       => 'Font text',
                'characters' => 'Font CSS',
                'style'      => array('fontFamily' => 'Example Sans', 'fontSize' => 20),
            ),
        ),
    ), array('font_css' => '@font-face{font-family:"Example Sans";src:url("assets/example-sans.woff2") format("woff2")}'));
    
    $metadataHtml = $fileContent($metadataResult, 'index.html');
    $metadataCss = $fileContent($metadataResult, 'style.css');
    $metadataWithFontCss = $fileContent($metadataWithFontCssResult, 'style.css');
    $metadataDiagnosticCodes = array_map(
        static fn (array $diagnostic): string => (string) ($diagnostic['code'] ?? ''),
        $metadataResult['diagnostics'] ?? array()
    );
    
    $assert(str_contains($metadataHtml, '<span style="font-weight:400">Hello </span><span style="font-weight:700;text-decoration:underline">World</span>'), 'styled-text-segments-emit');
    $assert(str_contains($metadataCss, 'p,h1,h2,h3,h4,h5,h6{margin:0}'), 'text-elements-reset-default-margins');
    $assert(str_contains($metadataCss, '.figma-node-4-1-metadata-frame{position:relative;background:rgba(51,102,153,0.5);opacity:0.75;border-radius:12px;border:2px solid #000000;box-shadow:0px 0px 0px 0px rgba(0,0,0,0.25)}'), 'normalized-frame-paint-box-style');
    $assert(str_contains($metadataCss, '.figma-node-4-2-mixed-text{position:absolute;font-family:"Example Sans", sans-serif;font-size:20px;font-weight:600;font-variation-settings:"wdth" 85;font-feature-settings:"liga" 0,"kern" 1;line-height:125%;letter-spacing:0.5px;color:rgba(255,128,0,0.8);text-align:center;vertical-align:top;text-decoration:underline}'), 'normalized-text-style');
    $assert(str_contains($metadataCss, '.figma-node-4-3-uneven-radius{position:absolute;border-top-left-radius:4px;border-top-right-radius:8px;border-bottom-right-radius:12px;border-bottom-left-radius:16px}'), 'individual-radius-style');
    $assert(str_contains($metadataCss, '.figma-node-4-4-raw-line-height-text{position:absolute;font-family:"Example Sans", sans-serif;font-size:18px;font-weight:600;line-height:1.15}'), 'font-style-weight-and-raw-line-height');
    $assert(str_contains($metadataCss, '.figma-node-4-5-wp-cloud-text-metrics{position:absolute;font-family:"DM Sans", sans-serif;font-size:80px;font-weight:700;line-height:1.05;letter-spacing:-0.02em}'), 'wp-cloud-text-metrics-style');
    $assert(str_contains($metadataCss, '.figma-node-4-6-zero-line-height-text{position:absolute;font-family:"Example Sans", sans-serif;font-size:16px;font-weight:400}') && ! str_contains($metadataCss, 'line-height:0'), 'zero-line-height-omitted');
    $assert(in_array('unsupported_figma_paint_type', $metadataDiagnosticCodes, true), 'unsupported-paint-diagnostic');
    $assert(! in_array('unsupported_figma_effect_type', $metadataDiagnosticCodes, true), 'supported-effect-no-diagnostic');
    $assert(in_array('font_css_missing_for_source_font', $metadataDiagnosticCodes, true), 'missing-font-css-diagnostic');
    $assert(str_starts_with($metadataWithFontCss, '@font-face{font-family:"Example Sans";src:url("assets/example-sans.woff2") format("woff2")}'), 'font-css-prepended-when-supplied');
    $assert(array('Example Sans') === ($metadataWithFontCssResult['source_reports']['figma']['html']['font_families'] ?? null), 'font-family-inventory-reports-source-fonts');
    $metadataFontUsage = $metadataResult['source_reports']['figma']['html']['font_usage'] ?? array();
    $compiledSiteFontUsage = $metadataResult['source_reports']['compiled_site']['theme']['font_usage'] ?? array();
    $assert(array('DM Sans', 'Example Sans') === array_column(is_array($metadataFontUsage) ? $metadataFontUsage : array(), 'family'), 'font-usage-reports-source-families');
    $assert(array(700) === ($metadataFontUsage[0]['weights'] ?? null), 'font-usage-reports-source-dm-sans-weights');
    $assert(array(400, 600) === ($metadataFontUsage[1]['weights'] ?? null), 'font-usage-reports-source-example-sans-weights');
    $assert(1 === ($metadataFontUsage[0]['text_node_count'] ?? null), 'font-usage-reports-source-dm-sans-node-count');
    $assert(3 === ($metadataFontUsage[1]['text_node_count'] ?? null), 'font-usage-reports-source-example-sans-node-count');
    $assert(2 === ($metadataFontUsage[1]['weight_counts']['600'] ?? null), 'font-usage-reports-source-example-sans-weight-count');
    $assert(array_column(is_array($metadataFontUsage) ? $metadataFontUsage : array(), 'family') === array_column(is_array($compiledSiteFontUsage) ? $compiledSiteFontUsage : array(), 'family'), 'compiled-site-theme-promotes-figma-font-usage-families');
    $assert(true === ($metadataWithFontCssResult['source_reports']['figma']['html']['font_css_supplied'] ?? null), 'font-css-supplied-report');
    $metadataTransformDiagnostics = $metadataResult['source_reports']['figma']['html']['transform_diagnostics'] ?? array();
    $metadataWithFontCssTransformDiagnostics = $metadataWithFontCssResult['source_reports']['figma']['html']['transform_diagnostics'] ?? array();
    $assert(array('DM Sans', 'Example Sans') === ($metadataTransformDiagnostics['fonts']['families'] ?? null), 'transform-diagnostics-font-families');
    // DM Sans is a known Google Fonts family so it resolves to a CDN @font-face import,
    // while the fictional "Example Sans" stays unresolved and actionable for an operator.
    $assert(true === ($metadataTransformDiagnostics['fonts']['materialized'] ?? null), 'transform-diagnostics-font-materialized-via-cdn');
    $assert(str_contains($metadataCss, "@import url('https://fonts.googleapis.com/css2?family=DM+Sans:wght@700&display=swap');"), 'cdn-font-import-emitted');
    $assert(str_starts_with(ltrim($metadataCss), '/*') || str_starts_with($metadataCss, '@import'), 'cdn-font-import-hoisted-to-top');
    $assert(array('Example Sans') === ($metadataTransformDiagnostics['fonts']['missing_css'] ?? null), 'transform-diagnostics-font-missing-css');
    $assert(array('DM Sans') === ($metadataTransformDiagnostics['fonts']['resolved_css'] ?? null), 'transform-diagnostics-font-resolved-css');
    $assert(array('DM Sans') === ($metadataTransformDiagnostics['fonts']['cdn_families'] ?? null), 'transform-diagnostics-font-cdn-families');
    $fontCoverage = $metadataTransformDiagnostics['fonts']['coverage'] ?? array();
    $coverageByFamily = array();
    foreach ( is_array($fontCoverage) ? $fontCoverage : array() as $coverageEntry ) {
        $coverageByFamily[(string) ($coverageEntry['family'] ?? '')] = $coverageEntry;
    }
    $assert('cdn_google_fonts' === ($coverageByFamily['DM Sans']['resolution'] ?? null) && true === ($coverageByFamily['DM Sans']['resolved'] ?? null), 'font-coverage-dm-sans-resolved-via-cdn');
    $assert(false === ($coverageByFamily['DM Sans']['needs_operator_font'] ?? null) && str_contains((string) ($coverageByFamily['DM Sans']['source_url'] ?? ''), 'fonts.googleapis.com'), 'font-coverage-dm-sans-cdn-source-url');
    $assert('unresolved' === ($coverageByFamily['Example Sans']['resolution'] ?? null) && true === ($coverageByFamily['Example Sans']['needs_operator_font'] ?? null), 'font-coverage-example-sans-needs-operator-font');
    $assert('"Example Sans", sans-serif' === ($coverageByFamily['Example Sans']['fallback_stack'] ?? null), 'font-coverage-example-sans-fallback-stack');
    $missingFontCssSignal = $artifactQualitySignal($metadataResult, 'font_css_missing');
    $assert('warning' === ($missingFontCssSignal['severity'] ?? null), 'font-css-missing-quality-warning');
    $assert('needs_review' === ($metadataTransformDiagnostics['artifact_quality']['status'] ?? null), 'font-css-missing-quality-needs-review');
    $assert('warn' === ($metadataTransformDiagnostics['artifact_quality']['quality_status'] ?? null), 'font-css-missing-quality-status-warn');
    $assert(1 === ($missingFontCssSignal['count'] ?? null), 'font-css-missing-quality-count');
    $assert(! in_array('font_css_missing', $artifactQualitySignalCodes($metadataWithFontCssResult), true), 'font-css-supplied-suppresses-quality-warning');
    $assert(true === ($metadataWithFontCssTransformDiagnostics['fonts']['materialized'] ?? null), 'transform-diagnostics-font-materialized-with-css');
    $styleDiagnostics = $metadataResult['source_reports']['figma']['html']['node_style_diagnostics'] ?? array();
    $mixedTextStyleDiagnostic = null;
    $frameStyleDiagnostic = null;
    foreach ( $styleDiagnostics as $styleDiagnostic ) {
        if ( '4:2' === ($styleDiagnostic['node']['id'] ?? null) ) {
            $mixedTextStyleDiagnostic = $styleDiagnostic;
        }
        if ( '4:1' === ($styleDiagnostic['node']['id'] ?? null) ) {
            $frameStyleDiagnostic = $styleDiagnostic;
        }
    }
    $assert(null !== $mixedTextStyleDiagnostic, 'node-style-diagnostics-text-node-present');
    $assert('"Example Sans", sans-serif' === ($mixedTextStyleDiagnostic['expected']['font_family'] ?? null), 'node-style-diagnostics-expected-font-family');
    $assert('"Example Sans", sans-serif' === ($mixedTextStyleDiagnostic['emitted']['font_family'] ?? null), 'node-style-diagnostics-emitted-font-family');
    $assert('20px' === ($mixedTextStyleDiagnostic['expected']['font_size'] ?? null), 'node-style-diagnostics-expected-font-size');
    $assert('20px' === ($mixedTextStyleDiagnostic['emitted']['font_size'] ?? null), 'node-style-diagnostics-emitted-font-size');
    $assert('rgba(255,128,0,0.8)' === ($mixedTextStyleDiagnostic['expected']['text_color'] ?? null), 'node-style-diagnostics-expected-text-color');
    $assert('rgba(255,128,0,0.8)' === ($mixedTextStyleDiagnostic['emitted']['text_color'] ?? null), 'node-style-diagnostics-emitted-text-color');
    $assert(null !== $frameStyleDiagnostic, 'node-style-diagnostics-frame-node-present');
    $assert('rgba(51,102,153,0.5)' === ($frameStyleDiagnostic['expected']['background'] ?? null), 'node-style-diagnostics-expected-background');
    $assert('rgba(51,102,153,0.5)' === ($frameStyleDiagnostic['emitted']['background'] ?? null), 'node-style-diagnostics-emitted-background');
    
    // Kiwi-format per-corner radii (the `.fig` ingestion path). Decoded archives carry
    // the Kiwi field names (`rectangleTopLeftCornerRadius`) alongside a uniform
    // `cornerRadius`, where the REST scenegraph would carry `topLeftRadius`. The
    // normalizer must read the Kiwi names and let per-corner values win over the
    // uniform radius so a mixed node (top rounded, bottom square) keeps its shape
    // instead of collapsing to a uniform radius or a square.
    $kiwiRadiusResult = blocks_engine_figma_transformer_transform_scenegraph(array(
        'name'  => 'Kiwi Corner Radius',
        'nodes' => array(
            array(
                'id'       => '5:1',
                'type'     => 'FRAME',
                'name'     => 'Kiwi radius frame',
                'width'    => 200,
                'height'   => 200,
                'children' => array(
                    array(
                        'id'                               => '5:2',
                        'type'                             => 'RECTANGLE',
                        'name'                             => 'Kiwi corner radius',
                        'width'                            => 160,
                        'height'                           => 90,
                        // Uniform value is intentionally wrong; per-corner must override it.
                        'cornerRadius'                     => 99,
                        'rectangleCornerRadiiIndependent'  => true,
                        'rectangleTopLeftCornerRadius'     => 8,
                        'rectangleTopRightCornerRadius'    => 8,
                        'rectangleBottomRightCornerRadius' => 0,
                        'rectangleBottomLeftCornerRadius'  => 0,
                    ),
                ),
            ),
        ),
    ));
    
    // Figma `textCase` enum → CSS text-transform / font-variant, and `paragraphSpacing`
    // surfaced as an info diagnostic because a single-element text node cannot carry
    // per-paragraph margins.
    $textCaseResult = blocks_engine_figma_transformer_transform_scenegraph(array(
        'name'  => 'Text Case And Paragraph Spacing',
        'nodes' => array(
            array(
                'id'       => 'tc:1',
                'type'     => 'FRAME',
                'name'     => 'Text case frame',
                'children' => array(
                    array(
                        'id'         => 'tc:2',
                        'type'       => 'TEXT',
                        'name'       => 'Upper text',
                        'characters' => 'shout this',
                        'style'      => array(
                            'fontFamily' => 'Example Sans',
                            'fontSize'   => 18,
                            'textCase'   => 'UPPER',
                        ),
                    ),
                    array(
                        'id'         => 'tc:3',
                        'type'       => 'TEXT',
                        'name'       => 'Lower text',
                        'characters' => 'QUIET This',
                        'style'      => array(
                            'fontFamily' => 'Example Sans',
                            'fontSize'   => 18,
                            'textCase'   => 'LOWER',
                        ),
                    ),
                    array(
                        'id'         => 'tc:4',
                        'type'       => 'TEXT',
                        'name'       => 'Title text',
                        'characters' => 'a nice heading',
                        'style'      => array(
                            'fontFamily' => 'Example Sans',
                            'fontSize'   => 18,
                            'textCase'   => 'TITLE',
                        ),
                    ),
                    array(
                        'id'         => 'tc:5',
                        'type'       => 'TEXT',
                        'name'       => 'Small caps forced text',
                        'characters' => 'small caps forced',
                        'style'      => array(
                            'fontFamily' => 'Example Sans',
                            'fontSize'   => 18,
                            'textCase'   => 'SMALL_CAPS_FORCED',
                        ),
                    ),
                    array(
                        'id'         => 'tc:6',
                        'type'       => 'TEXT',
                        'name'       => 'Original case text',
                        'characters' => 'Leave Me Alone',
                        'style'      => array(
                            'fontFamily' => 'Example Sans',
                            'fontSize'   => 18,
                            'textCase'   => 'ORIGINAL',
                        ),
                    ),
                    array(
                        'id'         => 'tc:7',
                        'type'       => 'TEXT',
                        'name'       => 'Multi paragraph text',
                        'characters' => "First paragraph.\nSecond paragraph.",
                        'style'      => array(
                            'fontFamily'        => 'Example Sans',
                            'fontSize'          => 18,
                            'paragraphSpacing'  => 24,
                        ),
                    ),
                    array(
                        'id'                              => 'tc:8',
                        'type'                            => 'TEXT',
                        'name'                            => 'Mixed case instance override text',
                        'characters'                      => 'Comprehensive News Coverage',
                        '_figma_instance_override_applied' => true,
                        'style'                           => array(
                            'fontFamily' => 'Example Sans',
                            'fontSize'   => 18,
                            'textCase'   => 'UPPER',
                        ),
                    ),
                ),
            ),
        ),
    ));
    $kiwiRadiusCss = $fileContent($kiwiRadiusResult, 'style.css');
    $assert(str_contains($kiwiRadiusCss, '.figma-node-5-2-kiwi-corner-radius{width:160px;height:90px;border-top-left-radius:8px;border-top-right-radius:8px;border-bottom-right-radius:0px;border-bottom-left-radius:0px}'), 'kiwi-per-corner-radius-style');
    $assert(! str_contains($kiwiRadiusCss, 'border-radius:99px'), 'kiwi-per-corner-radius-overrides-uniform');
    $kiwiRadiusNormalized = ( new Automattic\BlocksEngine\FigmaTransformer\Scenegraph\ScenegraphNormalizer() )->normalize(array(
        'name'  => 'Kiwi radius normalization',
        'nodes' => array(
            array(
                'id'                              => '5:2',
                'type'                            => 'RECTANGLE',
                'name'                            => 'Kiwi corner radius',
                'rectangleCornerRadiiIndependent' => true,
                'rectangleTopLeftCornerRadius'    => 8,
            ),
        ),
    ));
    $kiwiRadiusBox = $kiwiRadiusNormalized['nodes'][0]['figma_box'] ?? array();
    $assert(true === ($kiwiRadiusBox['corner_radii_independent'] ?? null), 'kiwi-corner-radii-independent-normalizes');
    $kiwiRadiusOverrideResolver = new Automattic\BlocksEngine\FigmaTransformer\Scenegraph\InstanceResolver();
    $kiwiRadiusOverrideDiagnostics = array();
    $kiwiRadiusOverrides = $kiwiRadiusOverrideResolver->normalizeInstanceOverrides(array(
        'id'         => '5:instance',
        'symbolData' => array(
            'symbolOverrides' => array(
                array(
                    'nodeId'                          => '5:2',
                    'rectangleCornerRadiiIndependent' => true,
                    'rectangleTopLeftCornerRadius'    => 8,
                ),
            ),
        ),
    ), '5:instance', $kiwiRadiusOverrideDiagnostics);
    $assert(true === ($kiwiRadiusOverrides['5:2']['rectangleCornerRadiiIndependent'] ?? null), 'kiwi-corner-radii-independent-override-preserved');
    
    $textCaseCss = $fileContent($textCaseResult, 'style.css');
    $textCaseDiagnosticCodes = array_map(
        static fn (array $diagnostic): string => (string) ($diagnostic['code'] ?? ''),
        $textCaseResult['diagnostics'] ?? array()
    );
    $assert(str_contains($textCaseCss, '.figma-node-tc-2-upper-text{position:absolute;font-family:"Example Sans", sans-serif;font-size:18px;text-transform:uppercase}'), 'text-case-upper-text-transform');
    $assert(str_contains($textCaseCss, 'text-transform:lowercase'), 'text-case-lower-text-transform');
    $assert(str_contains($textCaseCss, 'text-transform:capitalize'), 'text-case-title-text-transform');
    $assert(str_contains($textCaseCss, '.figma-node-tc-5-small-caps-forced-text{position:absolute;font-family:"Example Sans", sans-serif;font-size:18px;text-transform:uppercase;font-variant:small-caps}'), 'text-case-small-caps-forced');
    $assert(1 !== preg_match('/\.figma-node-tc-8-mixed-case-instance-override-text\{[^}]*text-transform:uppercase/s', $textCaseCss), 'text-case-mixed-case-instance-override-drops-inherited-uppercase');
    // ORIGINAL text case emits no text-transform. With paragraphSpacing now applied
    // by splitting (no white-space:pre-line), tc:7's box style matches tc:6's, so the
    // emitter dedupes them into one shared rule — assert on the un-transformed
    // declaration body and that tc:6 carries no transform-bearing rule of its own.
    $assert(str_contains($textCaseCss, '{position:absolute;font-family:"Example Sans", sans-serif;font-size:18px}'), 'text-case-original-no-transform');
    $assert(! str_contains($textCaseCss, '.figma-node-tc-6-original-case-text{'), 'text-case-original-deduped-into-shared-rule');
    // `paragraphSpacing` is now applied by splitting the multi-paragraph node into
    // per-paragraph boxes (completing the path started in #318), so the
    // `paragraph_spacing_not_applied` diagnostic must no longer fire for tc:7.
    $textCaseParagraphDiagnostic = null;
    foreach ( $textCaseResult['diagnostics'] ?? array() as $diagnostic ) {
        if ( 'paragraph_spacing_not_applied' === ($diagnostic['code'] ?? null) ) {
            $textCaseParagraphDiagnostic = $diagnostic;
            break;
        }
    }
    $assert(null === $textCaseParagraphDiagnostic, 'paragraph-spacing-diagnostic-dropped-when-applied');
    $assert(! str_contains($textCaseCss, 'paragraph-spacing') && ! str_contains($textCaseCss, 'paragraph_spacing'), 'paragraph-spacing-not-emitted-as-css');
    
    // The two paragraphs render as separate block boxes; the first carries the
    // 24px paragraph spacing as a margin-bottom, the last carries none. The split
    // node also drops the single-element white-space:pre-line fallback.
    $textCaseHtml = $fileContent($textCaseResult, 'index.html');
    $assert(str_contains($textCaseHtml, '<span style="display:block;margin-bottom:24px">First paragraph.</span><span style="display:block">Second paragraph.</span>'), 'paragraph-spacing-split-into-margin-boxes');
    $assert(! str_contains($textCaseCss, 'white-space:pre-line'), 'paragraph-spacing-split-drops-pre-line');

    $textCaseInstanceOverrideResult = blocks_engine_figma_transformer_transform_scenegraph(array(
        'name'  => 'Text Case Instance Override Fixture',
        'nodes' => array(
            array(
                'id'       => 'tcio:page',
                'type'     => 'FRAME',
                'name'     => 'Page',
                'children' => array(
                    array(
                        'id'         => 'tcio:instance',
                        'type'       => 'INSTANCE',
                        'name'       => 'Text instance',
                        'componentId' => 'tcio:component',
                        'symbolData' => array(
                            'symbolOverrides' => array(
                                array(
                                    'nodeId'     => 'tcio:source-text',
                                    'characters' => 'Mixed Case Override',
                                    'textCase'   => 'ORIGINAL',
                                ),
                            ),
                        ),
                    ),
                ),
            ),
            array(
                'id'          => 'tcio:component',
                'type'        => 'COMPONENT',
                'name'        => 'Text component',
                'componentId' => 'tcio:component',
                'children'    => array(
                    array(
                        'id'         => 'tcio:source-text',
                        'type'       => 'TEXT',
                        'name'       => 'Source text',
                        'characters' => 'SOURCE TEXT',
                        'style'      => array(
                            'fontFamily' => 'Example Sans',
                            'fontSize'   => 18,
                            'textCase'   => 'UPPER',
                        ),
                    ),
                ),
            ),
        ),
    ), array('frame_id' => 'tcio:page'));
    $textCaseInstanceOverrideCss = $fileContent($textCaseInstanceOverrideResult, 'style.css');
    $assert(str_contains($textCaseInstanceOverrideCss, '.figma-node-tcio-instance-tcio-source-text-source-text{'), 'text-case-instance-override-rule-emitted');
    $assert(str_contains($textCaseInstanceOverrideCss, 'text-transform:none'), 'text-case-instance-original-cancels-component-uppercase');
    $assert(1 !== preg_match('/\.figma-node-tcio-instance-tcio-source-text-source-text\{[^}]*text-transform:uppercase/s', $textCaseInstanceOverrideCss), 'text-case-instance-original-no-stale-uppercase');
}

function blocks_engine_figma_transformer_run_inline_text_style_contract(callable $assert, callable $fileContent): void
{
    // Inline character-level style overrides (characterStyleOverrides + styleOverrideTable).
    //
    // The Figma API encodes per-character style overrides as a parallel array of integer
    // IDs (`characterStyleOverrides`) and a lookup table (`styleOverrideTable`). When
    // characters share the same override ID they collapse into one run. The normalizer
    // converts these into the same `segments` contract used by `styledTextSegments` so
    // the emitter can wrap differing characters in minimal `<span style="...">` tags.
    $inlineTextStyleResult = blocks_engine_figma_transformer_transform_scenegraph(array(
        'name'  => 'Inline Text Style Fixture',
        'nodes' => array(
            array(
                'id'       => 'its:1',
                'type'     => 'FRAME',
                'name'     => 'Inline text frame',
                'width'    => 1200,
                'height'   => 400,
                'children' => array(
                    // All overrides are 0 — no inline spans expected.
                    array(
                        'id'                      => 'its:2',
                        'type'                    => 'TEXT',
                        'name'                    => 'Single style text',
                        'characters'              => 'Plain text node',
                        'style'                   => array(
                            'fontFamily' => 'Inter',
                            'fontWeight' => 400,
                            'fontSize'   => 16,
                            'fills'      => array(array('type' => 'SOLID', 'color' => array('r' => 0, 'g' => 0, 'b' => 0, 'a' => 1))),
                        ),
                        // 15 characters all mapping to base style.
                        'characterStyleOverrides' => array(0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0),
                        'styleOverrideTable'      => array(
                            '1' => array('fills' => array(array('type' => 'SOLID', 'color' => array('r' => 0, 'g' => 0, 'b' => 1, 'a' => 1)))),
                        ),
                    ),
                    // "Hello blue " (11 chars) in base black, "world" (5 chars) in blue.
                    array(
                        'id'                      => 'its:3',
                        'type'                    => 'TEXT',
                        'name'                    => 'Two color text',
                        'characters'              => 'Hello blue world',
                        'style'                   => array(
                            'fontFamily' => 'Inter',
                            'fontWeight' => 400,
                            'fontSize'   => 16,
                            'fills'      => array(array('type' => 'SOLID', 'color' => array('r' => 0, 'g' => 0, 'b' => 0, 'a' => 1))),
                        ),
                        'characterStyleOverrides' => array(0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 1, 1, 1, 1, 1),
                        'styleOverrideTable'      => array(
                            '1' => array('fills' => array(array('type' => 'SOLID', 'color' => array('r' => 0, 'g' => 0, 'b' => 1, 'a' => 1)))),
                        ),
                    ),
                    // "Bold" (4 chars) at weight 700, " plain text" (11 chars) at base weight 400.
                    array(
                        'id'                      => 'its:4',
                        'type'                    => 'TEXT',
                        'name'                    => 'Mixed weight text',
                        'characters'              => 'Bold plain text',
                        'style'                   => array(
                            'fontFamily' => 'Inter',
                            'fontWeight' => 400,
                            'fontSize'   => 16,
                        ),
                        'characterStyleOverrides' => array(1, 1, 1, 1, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0),
                        'styleOverrideTable'      => array(
                            '1' => array('fontWeight' => 700),
                        ),
                    ),
                ),
            ),
        ),
    ));
    $inlineTextStyleHtml = $fileContent($inlineTextStyleResult, 'index.html');
    
    // Single-style: all overrides resolve to 0 so no <span> wrapper is emitted.
    $assert(str_contains($inlineTextStyleHtml, '>Plain text node</p>'), 'inline-style-single-style-no-span');
    
    // Two-color: only the "world" run differs in fill color — it gets a color span.
    $assert(str_contains($inlineTextStyleHtml, 'Hello blue <span style="color:#0000ff">world</span>'), 'inline-style-two-color-spans');
    
    // Mixed-weight: only "Bold" differs in font-weight — it gets a font-weight span.
    $assert(str_contains($inlineTextStyleHtml, '<span style="font-weight:700">Bold</span> plain text'), 'inline-style-mixed-weight-spans');

    // Some decoded .fig/Yotako text segment payloads carry only Unicode start/end
    // offsets plus style metadata. The normalizer hydrates those ranges from the
    // node/TextData characters so the emitter does not drop the styled text runs.
    $offsetOnlySegmentResult = blocks_engine_figma_transformer_transform_scenegraph(array(
        'name'  => 'Offset Only Segment Fixture',
        'nodes' => array(
            array(
                'id'                 => 'seg:offset-node',
                'type'               => 'TEXT',
                'name'               => 'Offset segment text',
                'characters'         => 'Alpha Beta',
                'fontSize'           => 16,
                'styledTextSegments' => array(
                    array('start' => 0, 'end' => 6, 'style' => array('fontWeight' => 400)),
                    array('start' => 6, 'end' => 10, 'style' => array('fontWeight' => 700)),
                ),
            ),
            array(
                'id'       => 'seg:kiwi-textdata-node',
                'type'     => 'TEXT',
                'name'     => 'Kiwi TextData segment text',
                'fontSize' => 16,
                'textData' => array(
                    'characters' => 'Café Blue',
                    'segments'   => array(
                        array('start' => 0, 'end' => 5),
                        array('start' => 5, 'end' => 9, 'fillPaints' => array(array('type' => 'SOLID', 'color' => array('r' => 0, 'g' => 0, 'b' => 1, 'a' => 1)))),
                    ),
                ),
            ),
        ),
    ));
    $offsetOnlySegmentHtml = $fileContent($offsetOnlySegmentResult, 'index.html');
    $offsetOnlySegmentNormalized = ( new Automattic\BlocksEngine\FigmaTransformer\Scenegraph\ScenegraphNormalizer() )->normalize(array(
        'name'  => 'Offset Only Segment Normalization Fixture',
        'nodes' => array(
            array(
                'id'                 => 'seg:offset-node',
                'type'               => 'TEXT',
                'characters'         => 'Alpha Beta',
                'styledTextSegments' => array(
                    array('start' => 0, 'end' => 6, 'style' => array('fontWeight' => 400)),
                    array('start' => 6, 'end' => 10, 'style' => array('fontWeight' => 700)),
                ),
            ),
            array(
                'id'       => 'seg:kiwi-textdata-node',
                'type'     => 'TEXT',
                'textData' => array(
                    'characters' => 'Café Blue',
                    'segments'   => array(
                        array('start' => 0, 'end' => 5),
                        array('start' => 5, 'end' => 9, 'fillPaints' => array(array('type' => 'SOLID', 'color' => array('r' => 0, 'g' => 0, 'b' => 1, 'a' => 1)))),
                    ),
                ),
            ),
        ),
    ));
    $offsetOnlySegmentNormalizedById = array();
    foreach ( $offsetOnlySegmentNormalized['nodes'] ?? array() as $normalizedNode ) {
        if ( is_array($normalizedNode) && isset($normalizedNode['id']) ) {
            $offsetOnlySegmentNormalizedById[(string) $normalizedNode['id']] = $normalizedNode;
        }
    }
    $normalizedOffsetNode = $offsetOnlySegmentNormalizedById['seg:offset-node']['figma_text']['segments'] ?? array();
    $normalizedKiwiTextDataNode = $offsetOnlySegmentNormalizedById['seg:kiwi-textdata-node']['figma_text']['segments'] ?? array();
    $assert('Alpha ' === ($normalizedOffsetNode[0]['characters'] ?? null), 'offset-only-segment-normalizes-first-run-text');
    $assert('Beta' === ($normalizedOffsetNode[1]['characters'] ?? null), 'offset-only-segment-normalizes-second-run-text');
    $assert('Café ' === ($normalizedKiwiTextDataNode[0]['characters'] ?? null), 'kiwi-textdata-offset-segment-normalizes-unicode-first-run');
    $assert('Blue' === ($normalizedKiwiTextDataNode[1]['characters'] ?? null), 'kiwi-textdata-offset-segment-normalizes-unicode-second-run');
    $assert(str_contains($offsetOnlySegmentHtml, '<span style="font-weight:400">Alpha </span><span style="font-weight:700">Beta</span>'), 'offset-only-segment-emits-hydrated-spans');
    $assert(str_contains($offsetOnlySegmentHtml, 'Café <span style="color:#0000ff">Blue</span>'), 'kiwi-textdata-offset-segment-emits-hydrated-color-span');

    // Paragraph splitting must preserve inline override spans inside the correct
    // paragraph, and single-paragraph nodes must not gain a wrapper (#318 follow-up).
    //
    // "Bold intro\nplain rest" (21 chars): the first 4 characters ("Bold") map to a
    // weight-700 override, the rest to the base style. The `\n` (index 10) is the
    // paragraph boundary, so the bold span belongs to the first paragraph and only
    // the first paragraph carries the 12px margin-bottom.
    $paragraphSplitResult = blocks_engine_figma_transformer_transform_scenegraph(array(
        'name'  => 'Paragraph Split Fixture',
        'nodes' => array(
            array(
                'id'       => 'psplit:1',
                'type'     => 'FRAME',
                'name'     => 'Paragraph split frame',
                'width'    => 1200,
                'height'   => 400,
                'children' => array(
                    // Multi-paragraph node with an inline weight override in paragraph 1.
                    array(
                        'id'                      => 'psplit:2',
                        'type'                    => 'TEXT',
                        'name'                    => 'Styled multi paragraph',
                        'characters'              => "Bold intro\nplain rest",
                        'style'                   => array(
                            'fontFamily'       => 'Inter',
                            'fontWeight'       => 400,
                            'fontSize'         => 16,
                            'paragraphSpacing' => 12,
                        ),
                        'characterStyleOverrides' => array(1, 1, 1, 1, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0),
                        'styleOverrideTable'      => array(
                            '1' => array('fontWeight' => 700),
                        ),
                    ),
                    // Single-paragraph node that also declares paragraphSpacing: there
                    // is no paragraph boundary, so it must render exactly as before —
                    // no per-paragraph wrapper, no margin, no diagnostic.
                    array(
                        'id'         => 'psplit:3',
                        'type'       => 'TEXT',
                        'name'       => 'Single paragraph spacing',
                        'characters' => 'Only one paragraph here',
                        'style'      => array(
                            'fontFamily'       => 'Inter',
                            'fontSize'         => 16,
                            'paragraphSpacing' => 18,
                        ),
                    ),
                ),
            ),
        ),
    ));
    $paragraphSplitHtml = $fileContent($paragraphSplitResult, 'index.html');
    $paragraphSplitDiagnostics = array_map(
        static fn (array $diagnostic): string => (string) ($diagnostic['code'] ?? ''),
        $paragraphSplitResult['diagnostics'] ?? array()
    );
    
    // The bold override span survives, nested inside the first paragraph box, and
    // the second paragraph is its own box with no margin.
    $assert(str_contains($paragraphSplitHtml, '<span style="display:block;margin-bottom:12px"><span style="font-weight:700">Bold</span> intro</span><span style="display:block">plain rest</span>'), 'paragraph-split-preserves-inline-span-in-correct-paragraph');
    
    // Single-paragraph node: no per-paragraph wrapper and no diagnostic.
    $assert(str_contains($paragraphSplitHtml, '>Only one paragraph here</p>'), 'single-paragraph-spacing-no-wrapper');
    $assert(! in_array('paragraph_spacing_not_applied', $paragraphSplitDiagnostics, true), 'single-paragraph-spacing-no-diagnostic');
    
    // Kiwi (.fig) inline style overrides (#328).
    //
    // The REST API fixture above passes `characters` / `characterStyleOverrides` /
    // `styleOverrideTable` (an id-keyed map) flat on the node. Real `.fig` (Kiwi) files
    // encode the same data differently, and the figma-transformer must decode and
    // bridge that shape into the same `segments` contract:
    //   - text lives under `textData.characters`
    //   - per-character run IDs live under `textData.characterStyleIDs`
    //   - the override table is `textData.styleOverrideTable`, a `NodeChange[]` where
    //     each entry carries a `styleID` plus the overriding properties, and override
    //     text color rides on `fillPaints` (not REST `fills`).
    // This fixture mirrors that .fig shape so the bridge is exercised end-to-end:
    // decode -> normalize (id-keyed + fillPaints color + fontName→weight) -> emit span.
    $kiwiInlineTextStyleResult = blocks_engine_figma_transformer_transform_scenegraph(array(
        'name'  => 'Kiwi Inline Text Style Fixture',
        'nodes' => array(
            array(
                'id'       => 'kts:1',
                'type'     => 'FRAME',
                'name'     => 'Kiwi inline text frame',
                'width'    => 1200,
                'height'   => 400,
                'children' => array(
                    // "Hello blue " (11 chars) in base black, "world" (5 chars) in blue
                    // via a NodeChange-shaped override entry carrying `fillPaints`.
                    array(
                        'guid'       => array('sessionID' => 3267, 'localID' => 11418),
                        'type'       => 'ROUNDED_RECTANGLE',
                        'name'       => 'Lego Blue',
                        'styleType'  => 'FILL',
                        'fillPaints' => array(array('type' => 'SOLID', 'color' => array('r' => 0, 'g' => 109, 'b' => 183, 'a' => 1))),
                    ),
                    array(
                        'id'         => 'kts:2',
                        'type'       => 'TEXT',
                        'name'       => 'Kiwi two color text',
                        'fontName'   => array('family' => 'Inter', 'style' => 'Regular'),
                        'fontSize'   => 16,
                        'fillPaints' => array(array('type' => 'SOLID', 'color' => array('r' => 0, 'g' => 0, 'b' => 0, 'a' => 1))),
                        'textData'   => array(
                            'characters'        => 'Hello blue world',
                            'characterStyleIDs' => array(0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 1, 1, 1, 1, 1),
                            'styleOverrideTable' => array(
                                array(
                                    'styleID'    => 1,
                                    'fillPaints' => array(array('type' => 'SOLID', 'color' => array('r' => 0, 'g' => 0, 'b' => 1, 'a' => 1))),
                                ),
                            ),
                        ),
                    ),
                    // "Bold" (4 chars) at weight 700 via a NodeChange-shaped override
                    // entry carrying a bold `fontName`, " plain text" (11 chars) at base.
                    array(
                        'id'         => 'kts:4',
                        'type'       => 'TEXT',
                        'name'       => 'Kiwi style-referenced text color',
                        'fontName'   => array('family' => 'Inter', 'style' => 'Regular'),
                        'fontSize'   => 16,
                        'fillPaints' => array(array('type' => 'SOLID', 'color' => array('r' => 0, 'g' => 0, 'b' => 0, 'a' => 1))),
                        'textData'   => array(
                            'characters'        => 'We\'re all about Lego',
                            'characterStyleIDs' => array(0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 38, 38, 38, 38),
                            'styleOverrideTable' => array(
                                array(
                                    'styleID'        => 38,
                                    'styleIdForFill' => array('guid' => array('sessionID' => 3267, 'localID' => 11418)),
                                ),
                            ),
                        ),
                    ),
                    array(
                        'id'         => 'kts:3',
                        'type'       => 'TEXT',
                        'name'       => 'Kiwi mixed weight text',
                        'fontName'   => array('family' => 'Inter', 'style' => 'Regular'),
                        'fontSize'   => 16,
                        'textData'   => array(
                            'characters'        => 'Bold plain text',
                            'characterStyleIDs' => array(1, 1, 1, 1, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0),
                            'styleOverrideTable' => array(
                                array(
                                    'styleID'  => 1,
                                    'fontName' => array('family' => 'Inter', 'style' => 'Bold'),
                                ),
                            ),
                        ),
                    ),
                ),
            ),
        ),
    ));
    $kiwiInlineTextStyleHtml = $fileContent($kiwiInlineTextStyleResult, 'index.html');
    
    // Kiwi two-color: only the "world" run differs in fill color — it gets a color span,
    // and the non-overridden "Hello blue " text is emitted unwrapped.
    $assert(str_contains($kiwiInlineTextStyleHtml, 'Hello blue <span style="color:#0000ff">world</span>'), 'kiwi-inline-style-two-color-spans');
    // Kiwi style-referenced text fill: the Lego run resolves through styleIdForFill
    // to the FILL style node instead of requiring duplicate inline fillPaints.
    $assert(str_contains($kiwiInlineTextStyleHtml, 'We&#039;re all about <span style="color:#006db7">Lego</span>'), 'kiwi-inline-style-fill-reference-spans');
    // Kiwi mixed-weight: only "Bold" differs in font-weight — derived from the override
    // entry's bold `fontName` — and " plain text" stays unwrapped.
    $assert(str_contains($kiwiInlineTextStyleHtml, '<span style="font-weight:700">Bold</span> plain text'), 'kiwi-inline-style-mixed-weight-spans');

    $tokenOnlyFontResult = ( new Automattic\BlocksEngine\FigmaTransformer\Html\StaticHtmlEmitter() )->emitSite(array(
        'name'  => 'Token Only Font Fixture',
        'nodes' => array(
            array(
                'id'       => 'tok:system',
                'type'     => 'FRAME',
                'name'     => 'Typography tokens',
                'children' => array(
                    array(
                        'id'         => 'tok:display',
                        'type'       => 'TEXT',
                        'name'       => 'Display token',
                        'figma_text' => array(
                            'characters' => 'Display',
                            'style'      => array('font_family' => 'Token Only Sans', 'font_size' => 48, 'font_weight' => 700),
                        ),
                    ),
                ),
            ),
            array(
                'id'       => 'tok:page',
                'type'     => 'FRAME',
                'name'     => 'Rendered page',
                'children' => array(),
            ),
        ),
    ), array(array('frame_id' => 'tok:page', 'name' => 'Rendered page', 'path' => 'index.html', 'entrypoint' => true)));
    $tokenOnlyFontCss = $fileContent($tokenOnlyFontResult, 'style.css');
    $tokenOnlyFontDiagnostics = $tokenOnlyFontResult['source_report']['transform_diagnostics'] ?? array();
    $assert(str_contains($tokenOnlyFontCss, 'font-family:"Token Only Sans", sans-serif'), 'token-only-font-family-css-emitted');
    $assert(array() === ($tokenOnlyFontDiagnostics['fonts']['missing_css'] ?? null), 'token-only-font-family-not-missing-css-diagnostic');
    $assert(array('Token Only Sans') === array_column($tokenOnlyFontResult['source_report']['font_usage'] ?? array(), 'family'), 'token-only-font-family-usage-materialized');

    $inlineRunFontResult = ( new Automattic\BlocksEngine\FigmaTransformer\Html\StaticHtmlEmitter() )->emit(array(
        'name'  => 'Inline Run Font Fixture',
        'nodes' => array(
            array(
                'id'         => 'run:1',
                'type'       => 'TEXT',
                'name'       => 'Inline run family text',
                'figma_text' => array(
                    'segments' => array(
                        array('characters' => 'Base ', 'style' => null),
                        array('characters' => 'custom', 'style' => array('font_family' => 'Run Only Sans', 'font_weight' => 600)),
                    ),
                    'style'    => array('font_family' => 'Inter', 'font_size' => 16, 'font_weight' => 400),
                ),
            ),
        ),
    ));
    $inlineRunFontHtml = $fileContent($inlineRunFontResult, 'index.html');
    $inlineRunFontDiagnostics = $inlineRunFontResult['source_report']['transform_diagnostics'] ?? array();
    $assert(str_contains($inlineRunFontHtml, '<span style="font-family:&quot;Run Only Sans&quot;, sans-serif;font-weight:600">custom</span>'), 'inline-run-font-family-span-emitted');
    $assert(in_array('Run Only Sans', $inlineRunFontDiagnostics['fonts']['missing_css'] ?? array(), true), 'inline-run-font-family-missing-css-diagnostic');

    // Kiwi derived rich text spans: production .fig payloads can put the character
    // range IDs and NodeChange-shaped override table under `derivedTextData` while
    // the root text node still carries stale uppercase/heading-sized styling from a
    // component instance. The range overrides are authoritative for the rich text:
    // each repeated item starts with a bold lead statement and then returns to normal
    // sentence case/size. Repeated sibling item frames should still emit as a
    // semantic unordered list.
    $kiwiDerivedRichTextResult = blocks_engine_figma_transformer_transform_scenegraph(array(
        'name'  => 'Kiwi Derived Rich Text List Fixture',
        'nodes' => array(
            array(
                'id'       => 'krt:1',
                'type'     => 'FRAME',
                'name'     => 'What sets us apart',
                'width'    => 1200,
                'height'   => 600,
                'children' => array(
                    array(
                        'id'       => 'krt:list',
                        'type'     => 'FRAME',
                        'name'     => 'Apart list',
                        'width'    => 720,
                        'height'   => 240,
                        'children' => array(
                            array(
                                'id'       => 'krt:item-1',
                                'type'     => 'FRAME',
                                'name'     => 'List item 1',
                                'width'    => 720,
                                'height'   => 56,
                                'children' => array(
                                    array(
                                        'id'              => 'krt:text-1',
                                        'type'            => 'TEXT',
                                        'name'            => 'Movement item',
                                        'fontName'        => array('family' => 'Inter', 'style' => 'Bold'),
                                        'fontSize'        => 32,
                                        'textCase'        => 'UPPER',
                                        'textData'        => array('characters' => 'Movement made gentle: Tips fit your day.'),
                                        'derivedTextData' => array(
                                            'characterStyleIDs' => array_merge(array_fill(0, 21, 1), array_fill(0, 19, 2)),
                                            'styleOverrideTable' => array(
                                                array(
                                                    'styleID'  => 1,
                                                    'fontName' => array('family' => 'Inter', 'style' => 'Bold'),
                                                    'fontSize' => 16,
                                                    'textCase' => 'ORIGINAL',
                                                ),
                                                array(
                                                    'styleID'  => 2,
                                                    'fontName' => array('family' => 'Inter', 'style' => 'Regular'),
                                                    'fontSize' => 16,
                                                    'textCase' => 'ORIGINAL',
                                                ),
                                            ),
                                        ),
                                    ),
                                ),
                            ),
                            array(
                                'id'       => 'krt:item-2',
                                'type'     => 'FRAME',
                                'name'     => 'List item 2',
                                'width'    => 720,
                                'height'   => 56,
                                'children' => array(
                                    array(
                                        'id'              => 'krt:text-2',
                                        'type'            => 'TEXT',
                                        'name'            => 'Recovery item',
                                        'fontName'        => array('family' => 'Inter', 'style' => 'Bold'),
                                        'fontSize'        => 32,
                                        'textCase'        => 'UPPER',
                                        'textData'        => array('characters' => 'Recovery that lasts: Build steady habits.'),
                                        'derivedTextData' => array(
                                            'characterStyleIDs' => array_merge(array_fill(0, 20, 1), array_fill(0, 21, 2)),
                                            'styleOverrideTable' => array(
                                                array('styleID' => 1, 'fontName' => array('family' => 'Inter', 'style' => 'Bold'), 'fontSize' => 16, 'textCase' => 'ORIGINAL'),
                                                array('styleID' => 2, 'fontName' => array('family' => 'Inter', 'style' => 'Regular'), 'fontSize' => 16, 'textCase' => 'ORIGINAL'),
                                            ),
                                        ),
                                    ),
                                ),
                            ),
                            array(
                                'id'       => 'krt:item-3',
                                'type'     => 'FRAME',
                                'name'     => 'List item 3',
                                'width'    => 720,
                                'height'   => 56,
                                'children' => array(
                                    array(
                                        'id'              => 'krt:text-3',
                                        'type'            => 'TEXT',
                                        'name'            => 'Support item',
                                        'fontName'        => array('family' => 'Inter', 'style' => 'Bold'),
                                        'fontSize'        => 32,
                                        'textCase'        => 'UPPER',
                                        'textData'        => array('characters' => 'Support between visits: Keep moving safely.'),
                                        'derivedTextData' => array(
                                            'characterStyleIDs' => array_merge(array_fill(0, 24, 1), array_fill(0, 20, 2)),
                                            'styleOverrideTable' => array(
                                                array('styleID' => 1, 'fontName' => array('family' => 'Inter', 'style' => 'Bold'), 'fontSize' => 16, 'textCase' => 'ORIGINAL'),
                                                array('styleID' => 2, 'fontName' => array('family' => 'Inter', 'style' => 'Regular'), 'fontSize' => 16, 'textCase' => 'ORIGINAL'),
                                            ),
                                        ),
                                    ),
                                ),
                            ),
                        ),
                    ),
                ),
            ),
        ),
    ));
    $kiwiDerivedRichTextHtml = $fileContent($kiwiDerivedRichTextResult, 'index.html');
    $kiwiDerivedRichTextCss = $fileContent($kiwiDerivedRichTextResult, 'style.css');
    $assert(str_contains($kiwiDerivedRichTextHtml, '<ul'), 'kiwi-derived-rich-text-list-container');
    $assert(3 === substr_count($kiwiDerivedRichTextHtml, '<li '), 'kiwi-derived-rich-text-list-items');
    blocks_engine_figma_transformer_contract_assert_tag_count($assert, $kiwiDerivedRichTextHtml, 'ul', 1, 'kiwi-derived-rich-text-single-semantic-list');
    blocks_engine_figma_transformer_contract_assert_css_rule_contains($assert, $kiwiDerivedRichTextCss, '.figma-node-krt-list-apart-list', array('list-style:disc', 'padding-left:1.5em'), 'kiwi-derived-rich-text-list-restores-marker-css');
    $assert(str_contains($kiwiDerivedRichTextHtml, '<span style="font-size:16px;text-transform:none">Movement made gentle:</span><span style="font-size:16px;font-weight:400;text-transform:none"> Tips fit your day.</span>'), 'kiwi-derived-rich-text-bold-lead-and-normal-tip');
    $assert(! str_contains($kiwiDerivedRichTextHtml, 'MOVEMENT MADE GENTLE'), 'kiwi-derived-rich-text-no-baked-uppercase');
    $assert(1 !== preg_match('/\.figma-node-krt-text-1-movement-item\{[^}]*text-transform:uppercase/s', $kiwiDerivedRichTextCss), 'kiwi-derived-rich-text-root-uppercase-not-emitted');

    // Visual ordered-list rows often split the marker ("1.") and rich text body
    // into sibling text nodes. The marker should select <ol> semantics without
    // being emitted as duplicate content, while the body keeps Kiwi inline spans.
    $kiwiOrderedMarkerRichTextResult = blocks_engine_figma_transformer_transform_scenegraph(array(
        'name'  => 'Kiwi Ordered Marker Rich Text Fixture',
        'nodes' => array(
            array(
                'id'       => 'kom:1',
                'type'     => 'FRAME',
                'name'     => 'Ordered list frame',
                'width'    => 1200,
                'height'   => 600,
                'children' => array(
                    array(
                        'id'       => 'kom:list',
                        'type'     => 'FRAME',
                        'name'     => 'Ordered apart list',
                        'width'    => 720,
                        'height'   => 240,
                        'children' => array(
                            array(
                                'id'       => 'kom:item-1',
                                'type'     => 'FRAME',
                                'name'     => 'Numbered list item',
                                'width'    => 720,
                                'height'   => 56,
                                'children' => array(
                                    array('id' => 'kom:marker-1', 'type' => 'TEXT', 'name' => 'Marker', 'characters' => '1.', 'fontSize' => 32, 'fontWeight' => 700),
                                    array(
                                        'id'              => 'kom:text-1',
                                        'type'            => 'TEXT',
                                        'name'            => 'Body',
                                        'fontName'        => array('family' => 'Inter', 'style' => 'Bold'),
                                        'fontSize'        => 32,
                                        'textCase'        => 'UPPER',
                                        'textData'        => array('characters' => 'Movement made gentle: Tips fit your day.'),
                                        'derivedTextData' => array(
                                            'characterStyleIDs' => array_merge(array_fill(0, 21, 1), array_fill(0, 19, 2)),
                                            'styleOverrideTable' => array(
                                                array('styleID' => 1, 'fontName' => array('family' => 'Inter', 'style' => 'Bold'), 'fontSize' => 16, 'textCase' => 'ORIGINAL'),
                                                array('styleID' => 2, 'fontName' => array('family' => 'Inter', 'style' => 'Regular'), 'fontSize' => 16, 'textCase' => 'ORIGINAL'),
                                            ),
                                        ),
                                    ),
                                ),
                            ),
                            array(
                                'id'       => 'kom:item-2',
                                'type'     => 'FRAME',
                                'name'     => 'Numbered list item',
                                'width'    => 720,
                                'height'   => 56,
                                'children' => array(
                                    array('id' => 'kom:marker-2', 'type' => 'TEXT', 'name' => 'Marker', 'characters' => '2.', 'fontSize' => 32, 'fontWeight' => 700),
                                    array('id' => 'kom:text-2', 'type' => 'TEXT', 'name' => 'Body', 'characters' => 'Recovery that lasts: Build steady habits.', 'fontSize' => 16),
                                ),
                            ),
                            array(
                                'id'       => 'kom:item-3',
                                'type'     => 'FRAME',
                                'name'     => 'Numbered list item',
                                'width'    => 720,
                                'height'   => 56,
                                'children' => array(
                                    array('id' => 'kom:marker-3', 'type' => 'TEXT', 'name' => 'Marker', 'characters' => '3.', 'fontSize' => 32, 'fontWeight' => 700),
                                    array('id' => 'kom:text-3', 'type' => 'TEXT', 'name' => 'Body', 'characters' => 'Support between visits: Keep moving safely.', 'fontSize' => 16),
                                ),
                            ),
                        ),
                    ),
                ),
            ),
        ),
    ));
    $kiwiOrderedMarkerRichTextHtml = $fileContent($kiwiOrderedMarkerRichTextResult, 'index.html');
    $kiwiOrderedMarkerRichTextCss = $fileContent($kiwiOrderedMarkerRichTextResult, 'style.css');
    $assert(str_contains($kiwiOrderedMarkerRichTextHtml, '<ol'), 'kiwi-ordered-marker-rich-text-list-container');
    $assert(3 === substr_count($kiwiOrderedMarkerRichTextHtml, '<li '), 'kiwi-ordered-marker-rich-text-list-items');
    blocks_engine_figma_transformer_contract_assert_tag_count($assert, $kiwiOrderedMarkerRichTextHtml, 'ol', 1, 'kiwi-ordered-marker-rich-text-single-semantic-list');
    blocks_engine_figma_transformer_contract_assert_css_rule_contains($assert, $kiwiOrderedMarkerRichTextCss, '.figma-node-kom-list-ordered-apart-list', array('list-style:decimal', 'padding-left:1.5em'), 'kiwi-ordered-marker-rich-text-list-markers-preserved');
    $assert(! str_contains($kiwiOrderedMarkerRichTextHtml, '>1.<'), 'kiwi-ordered-marker-rich-text-marker-suppressed');
    $assert(str_contains($kiwiOrderedMarkerRichTextHtml, '<p class="figma-node-kom-text-1-body"'), 'kiwi-ordered-marker-rich-text-body-paragraph');
    $assert(str_contains($kiwiOrderedMarkerRichTextHtml, '<span style="font-size:16px;text-transform:none">Movement made gentle:</span><span style="font-size:16px;font-weight:400;text-transform:none"> Tips fit your day.</span>'), 'kiwi-ordered-marker-rich-text-body-spans');
    $assert(! str_contains($kiwiOrderedMarkerRichTextHtml, '<h3 class="figma-node-kom-text-1-body"'), 'kiwi-ordered-marker-rich-text-body-not-heading');
     
    // Kiwi text style references: production .fig payloads can carry stale inline
    // `fontName` data on a text node while `styleIdForText` points at the canonical
    // text style and `derivedTextData.fontMetaData` matches that style. Prefer the
    // referenced text style so cloned component text preserves direct Figma parity.
    $kiwiTextStyleReferenceResult = blocks_engine_figma_transformer_transform_scenegraph(array(
        'name'  => 'Kiwi Text Style Reference Fixture',
        'nodes' => array(
            array(
                'id'       => 'kref:1',
                'type'     => 'FRAME',
                'name'     => 'Kiwi text style reference frame',
                'width'    => 1200,
                'height'   => 400,
                'children' => array(
                    array(
                        'id'        => '4166:11869',
                        'type'      => 'TEXT',
                        'styleType' => 'TEXT',
                        'name'      => 'Desktop/Headings/H2',
                        'fontName'  => array('family' => 'Barlow Condensed', 'style' => 'Bold'),
                        'fontSize'  => 48,
                        'textCase'  => 'UPPER',
                        'lineHeight' => array('units' => 'PERCENT', 'value' => 120),
                        'textData'  => array('characters' => 'Rag 123'),
                    ),
                    array(
                        'id'             => 'kref:2',
                        'type'           => 'TEXT',
                        'name'           => 'Newsletter heading with stale inline font',
                        'width'          => 544,
                        'height'         => 48,
                        'fontName'       => array('family' => 'Helvetica Neue', 'style' => 'Bold', 'postscript' => 'HelveticaNeue-Bold'),
                        'fontSize'       => 24,
                        'textCase'       => 'ORIGINAL',
                        'styleIdForText' => array('guid' => array('sessionID' => 4166, 'localID' => 11869)),
                        'textData'       => array('characters' => 'Get the newsletter!'),
                    ),
                ),
            ),
        ),
    ));
    $kiwiTextStyleReferenceCss = $fileContent($kiwiTextStyleReferenceResult, 'style.css');
    $assert(str_contains($kiwiTextStyleReferenceCss, '.figma-node-kref-2-newsletter-heading-with-stale-inline-font{'), 'kiwi-text-style-reference-rule-emitted');
    $assert(str_contains($kiwiTextStyleReferenceCss, 'font-family:"Barlow Condensed", sans-serif'), 'kiwi-text-style-reference-font-family');
    $assert(str_contains($kiwiTextStyleReferenceCss, 'font-size:48px'), 'kiwi-text-style-reference-font-size');
    $assert(str_contains($kiwiTextStyleReferenceCss, 'font-weight:700'), 'kiwi-text-style-reference-font-weight');
    $assert(str_contains($kiwiTextStyleReferenceCss, 'text-transform:none'), 'kiwi-text-style-reference-original-cancels-uppercase-style-ref');
    $assert(! str_contains($kiwiTextStyleReferenceCss, 'font-family:"Helvetica Neue", Helvetica, Arial, sans-serif'), 'kiwi-text-style-reference-stale-inline-font-not-emitted');
    
    // Kiwi text can carry the rendered font through derivedTextData.fontMetaData.
    // Preserve that concrete glyph font instead of retaining a stale/default text style.
    $kiwiInstanceTextDerivedFontResult = blocks_engine_figma_transformer_transform_scenegraph(array(
        'name'  => 'Kiwi Text Derived Font Fixture',
        'nodes' => array(
            array(
                'id'       => 'kitdf:frame',
                'type'     => 'FRAME',
                'name'     => 'Frame',
                'children' => array(
                    array(
                        'id'              => 'kitdf:text',
                        'type'            => 'TEXT',
                        'name'            => 'Paragraph',
                        'textData'        => array('characters' => 'Overridden paragraph'),
                        'fontName'        => array('family' => 'Plus Jakarta Sans', 'style' => 'Bold'),
                        'fontSize'        => 24,
                        'derivedTextData' => array(
                            'fontMetaData' => array(
                                array(
                                    'key'            => array('family' => 'Plus Jakarta Sans', 'style' => 'Medium'),
                                    'fontWeight'     => 500,
                                    'fontLineHeight' => 1.26,
                                ),
                            ),
                        ),
                    ),
                ),
            ),
        ),
    ));
    $kiwiInstanceTextDerivedFontHtml = $fileContent($kiwiInstanceTextDerivedFontResult, 'index.html');
    $assert(str_contains($kiwiInstanceTextDerivedFontHtml, 'font-family:"Plus Jakarta Sans", sans-serif;font-size:24px;font-weight:500'), 'kiwi-instance-text-derived-font-overrides-component-weight');
}
