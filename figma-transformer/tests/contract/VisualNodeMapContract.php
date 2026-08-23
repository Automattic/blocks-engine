<?php

declare(strict_types=1);

/**
 * @param callable(bool, string): void $assert
 */
function blocks_engine_figma_transformer_run_visual_node_map_contract(callable $assert): void
{
    $visualFlexAlignmentResult = blocks_engine_figma_transformer_contract_transform(array(
        'name'  => 'Visual Flex Alignment Fixture',
        'nodes' => array(
            array(
                'id'                    => 'visual-flex:row',
                'type'                  => 'FRAME',
                'name'                  => 'Visual flex row',
                'width'                 => 500,
                'height'                => 100,
                'layoutMode'            => 'HORIZONTAL',
                'primaryAxisAlignItems' => 'MAX',
                'counterAxisAlignItems' => 'CENTER',
                'itemSpacing'           => 20,
                'children'              => array(
                    array('id' => 'visual-flex:first', 'type' => 'RECTANGLE', 'name' => 'First child', 'width' => 100, 'height' => 20),
                    array('id' => 'visual-flex:second', 'type' => 'RECTANGLE', 'name' => 'Second child', 'width' => 50, 'height' => 40),
                ),
            ),
            array(
                'id'                    => 'visual-flex:column',
                'type'                  => 'FRAME',
                'name'                  => 'Visual flex column',
                'width'                 => 300,
                'height'                => 200,
                'layoutMode'            => 'VERTICAL',
                'counterAxisAlignItems' => 'CENTER',
                'paddingLeft'           => 20,
                'paddingRight'          => 20,
                'paddingTop'            => 10,
                'children'              => array(
                    array('id' => 'visual-flex:centered', 'type' => 'RECTANGLE', 'name' => 'Centered child', 'width' => 100, 'height' => 30),
                ),
            ),
        ),
    ));
    $visualFlexFirst = blocks_engine_figma_transformer_contract_find_visual_node($visualFlexAlignmentResult, 'visual-flex:first');
    $visualFlexSecond = blocks_engine_figma_transformer_contract_find_visual_node($visualFlexAlignmentResult, 'visual-flex:second');
    $visualFlexCentered = blocks_engine_figma_transformer_contract_find_visual_node($visualFlexAlignmentResult, 'visual-flex:centered');
    $assert(330.0 === ($visualFlexFirst['rect']['x'] ?? null), 'visual-map-flex-end-first-x');
    $assert(40.0 === ($visualFlexFirst['rect']['y'] ?? null), 'visual-map-flex-center-first-y');
    $assert(450.0 === ($visualFlexSecond['rect']['x'] ?? null), 'visual-map-flex-end-second-x');
    $assert(30.0 === ($visualFlexSecond['rect']['y'] ?? null), 'visual-map-flex-center-second-y');
    $assert(100.0 === ($visualFlexCentered['rect']['x'] ?? null), 'visual-map-column-center-child-x');
    $assert(10.0 === ($visualFlexCentered['rect']['y'] ?? null), 'visual-map-column-padding-child-y');

    $emittedClassResult = blocks_engine_figma_transformer_contract_transform(array(
        'name'  => 'Visual Node Emitted Class Fixture',
        'nodes' => array(
            array(
                'id'       => 'visual-emitted:page',
                'type'     => 'FRAME',
                'name'     => 'Evidence Page',
                'width'    => 320,
                'height'   => 160,
                'children' => array(
                    array(
                        'id'         => 'visual-emitted:title',
                        'type'       => 'TEXT',
                        'name'       => 'Traceable Title',
                        'text'       => 'Traceable title',
                        'width'      => 180,
                        'height'     => 32,
                        'fontSize'   => 24,
                        'fontWeight' => 700,
                    ),
                ),
            ),
        ),
    ));
    $emittedClassNode = blocks_engine_figma_transformer_contract_find_visual_node($emittedClassResult, 'visual-emitted:title');
    $emittedClassHtml = blocks_engine_figma_transformer_contract_file_content($emittedClassResult, 'index.html');
    $emittedClassCss = blocks_engine_figma_transformer_contract_file_content($emittedClassResult, 'style.css');
    $assert('figma-node-visual-emitted-title-traceable-title' === ($emittedClassNode['emitted_class'] ?? null), 'visual-map-emitted-class-json-hook');
    $assert('h2' === ($emittedClassNode['emitted_tag'] ?? null), 'visual-map-emitted-class-json-tag');
    $assert('index.html' === ($emittedClassNode['page_path'] ?? null), 'visual-map-emitted-class-json-page-path');
    $assert(str_contains($emittedClassHtml, 'class="figma-node-visual-emitted-title-traceable-title" data-figma-node-id="visual-emitted:title"'), 'visual-map-emitted-class-html-hook');
    $assert(str_contains($emittedClassCss, '.figma-node-visual-emitted-title-traceable-title{'), 'visual-map-emitted-class-css-hook');

    $multiPageEmittedClassResult = blocks_engine_figma_transformer_transform_scenegraph(array(
        'name'  => 'Visual Node Multi Page Emitted Class Fixture',
        'nodes' => array(
            array(
                'id'       => 'visual-emitted:canvas',
                'type'     => 'CANVAS',
                'name'     => 'Pages',
                'children' => array(
                    array(
                        'id'       => 'visual-emitted:home',
                        'type'     => 'FRAME',
                        'name'     => 'Home Page',
                        'width'    => 320,
                        'height'   => 160,
                        'children' => array(
                            array('id' => 'visual-emitted:home-title', 'type' => 'TEXT', 'name' => 'Home Title', 'text' => 'Home', 'width' => 120, 'height' => 24),
                        ),
                    ),
                    array(
                        'id'       => 'visual-emitted:about',
                        'type'     => 'FRAME',
                        'name'     => 'About Page',
                        'width'    => 320,
                        'height'   => 160,
                        'children' => array(
                            array('id' => 'visual-emitted:about-title', 'type' => 'TEXT', 'name' => 'About Title', 'text' => 'About', 'width' => 120, 'height' => 24),
                        ),
                    ),
                ),
            ),
        ),
    ), array('multi_page' => true, 'frame_ids' => array('visual-emitted:home', 'visual-emitted:about'), 'entry_frame_id' => 'visual-emitted:home'));
    $multiPageAboutNode = blocks_engine_figma_transformer_contract_find_visual_node($multiPageEmittedClassResult, 'visual-emitted:about-title');
    $multiPageAboutHtml = blocks_engine_figma_transformer_contract_file_content($multiPageEmittedClassResult, 'about-page.html');
    $multiPageCss = blocks_engine_figma_transformer_contract_file_content($multiPageEmittedClassResult, 'style.css');
    $multiPageSourceReport = $multiPageEmittedClassResult['source_reports']['figma']['html'] ?? array();
    $multiPagePages = is_array($multiPageSourceReport['pages'] ?? null) ? $multiPageSourceReport['pages'] : array();
    $multiPageAggregateMap = is_array($multiPageSourceReport['visual_node_map'] ?? null) ? $multiPageSourceReport['visual_node_map'] : array();
    $assert('about-page.html' === ($multiPageAboutNode['page_path'] ?? null), 'visual-map-emitted-class-multi-page-path');
    $assert(1 === ($multiPageAboutNode['source_page_index'] ?? null), 'visual-map-aggregate-source-page-index');
    $assert('visual-emitted:about' === ($multiPageAboutNode['source_page_frame_id'] ?? null), 'visual-map-aggregate-source-page-frame-id');
    $assert(2 === count($multiPagePages), 'visual-map-aggregate-page-report-count');
    $assert(4 === count($multiPageAggregateMap), 'visual-map-aggregate-node-count');
    $assert('visual-emitted:about-title' === ($multiPagePages[1]['visual_node_map'][1]['id'] ?? null), 'visual-map-page-report-preserves-node');
    $assert(1 === ($multiPagePages[1]['visual_node_map'][1]['source_page_index'] ?? null), 'visual-map-page-report-preserves-source-page-index');
    $assert('figma-node-visual-emitted-about-title-about-title' === ($multiPageAboutNode['emitted_class'] ?? null), 'visual-map-emitted-class-multi-page-class');
    $assert(str_contains($multiPageAboutHtml, 'class="figma-node-visual-emitted-about-title-about-title" data-figma-node-id="visual-emitted:about-title"'), 'visual-map-emitted-class-multi-page-html-hook');
    $assert(str_contains($multiPageCss, '.figma-node-visual-emitted-about-title-about-title{'), 'visual-map-emitted-class-multi-page-css-hook');

    $responsivePagePathResult = blocks_engine_figma_transformer_transform_scenegraph(array(
        'name'  => 'Responsive Page Path Fixture',
        'nodes' => array(
            array(
                'id'       => 'responsive-path:desktop',
                'type'     => 'FRAME',
                'name'     => 'Article Desktop',
                'width'    => 1024,
                'height'   => 640,
                'children' => array(
                    array('id' => 'responsive-path:desktop-title', 'type' => 'TEXT', 'name' => 'Article Title', 'text' => 'Article', 'width' => 240, 'height' => 36, 'fontSize' => 28),
                ),
            ),
            array(
                'id'       => 'responsive-path:mobile',
                'type'     => 'FRAME',
                'name'     => 'Article Mobile',
                'width'    => 390,
                'height'   => 640,
                'children' => array(
                    array('id' => 'responsive-path:mobile-title', 'type' => 'TEXT', 'name' => 'Article Title', 'text' => 'Article', 'width' => 220, 'height' => 32, 'fontSize' => 24),
                ),
            ),
        ),
    ), array(
        'static_site_page_path' => 'article.html',
        'page_name' => 'Article',
        'responsive_variants' => array(
            array('frame_id' => 'responsive-path:desktop', 'viewport_width' => 1024, 'primary' => true),
            array('frame_id' => 'responsive-path:mobile', 'viewport_width' => 390),
        ),
    ));
    $responsivePagePathHtml = blocks_engine_figma_transformer_contract_file_content($responsivePagePathResult, 'article.html');
    $responsivePagePathNode = blocks_engine_figma_transformer_contract_find_visual_node($responsivePagePathResult, 'responsive-path:desktop-title');
    $assert(str_contains($responsivePagePathHtml, 'data-page-path="article.html"'), 'visual-map-responsive-page-root-uses-static-site-page-path');
    $assert('article.html' === ($responsivePagePathNode['page_path'] ?? null), 'visual-map-responsive-page-node-uses-static-site-page-path');

    $reverseZIndexResult = blocks_engine_figma_transformer_contract_transform(array(
        'name'  => 'Reverse Z Index Auto Layout Fixture',
        'nodes' => array(
            array(
                'id'                 => 'reverse-z:row',
                'type'               => 'FRAME',
                'name'               => 'Overlapping reverse z row',
                'width'              => 180,
                'height'             => 80,
                'layoutMode'         => 'HORIZONTAL',
                'itemSpacing'        => -20,
                'stackReverseZIndex' => true,
                'children'           => array(
                    array('id' => 'reverse-z:first', 'type' => 'RECTANGLE', 'name' => 'First top child', 'width' => 80, 'height' => 80),
                    array('id' => 'reverse-z:second', 'type' => 'RECTANGLE', 'name' => 'Second lower child', 'width' => 80, 'height' => 80),
                ),
            ),
        ),
    ));
    $reverseZIndexCss = blocks_engine_figma_transformer_contract_file_content($reverseZIndexResult, 'style.css');
    blocks_engine_figma_transformer_contract_assert_css_rule_contains($assert, $reverseZIndexCss, '.figma-node-reverse-z-row-overlapping-reverse-z-row', array('width:180px', 'height:80px', 'isolation:isolate', 'display:flex', 'flex-direction:row', 'gap:0px'), 'visual-map-reverse-z-parent-gap-clamped');
    blocks_engine_figma_transformer_contract_assert_css_rule_contains($assert, $reverseZIndexCss, '.figma-node-reverse-z-row-overlapping-reverse-z-row>*+*', array('margin-left:-20px'), 'visual-map-reverse-z-negative-spacing-overlap-margin');
    $assert(! str_contains($reverseZIndexCss, 'gap:-'), 'visual-map-reverse-z-no-negative-gap-css');
    blocks_engine_figma_transformer_contract_assert_css_rule_contains($assert, $reverseZIndexCss, '.figma-node-reverse-z-first-first-top-child', array('width:80px', 'height:80px', 'position:relative', 'z-index:2', 'flex-shrink:0'), 'visual-map-reverse-z-first-child-on-top');
    blocks_engine_figma_transformer_contract_assert_css_rule_contains($assert, $reverseZIndexCss, '.figma-node-reverse-z-second-second-lower-child', array('width:80px', 'height:80px', 'position:relative', 'z-index:1', 'flex-shrink:0'), 'visual-map-reverse-z-second-child-lower');

    $isolatedStackResult = blocks_engine_figma_transformer_contract_transform(array(
        'name'  => 'Isolated Local Stack Fixture',
        'nodes' => array(
            array(
                'id'       => 'isolated-stack:page',
                'type'     => 'FRAME',
                'name'     => 'Page',
                'width'    => 320,
                'height'   => 240,
                'children' => array(
                    array(
                        'id'                 => 'isolated-stack:section',
                        'type'               => 'FRAME',
                        'name'               => 'Layered image section',
                        'x'                  => 0,
                        'y'                  => 0,
                        'width'              => 320,
                        'height'             => 180,
                        'layoutMode'         => 'HORIZONTAL',
                        'itemSpacing'        => -80,
                        'stackReverseZIndex' => true,
                        'children'           => array(
                            array('id' => 'isolated-stack:image', 'type' => 'RECTANGLE', 'name' => 'Image', 'x' => 0, 'y' => 0, 'width' => 320, 'height' => 180),
                            array('id' => 'isolated-stack:badge', 'type' => 'RECTANGLE', 'name' => 'Badge', 'x' => 24, 'y' => 24, 'width' => 80, 'height' => 40),
                        ),
                    ),
                    array('id' => 'isolated-stack:footer', 'type' => 'RECTANGLE', 'name' => 'Footer band', 'x' => 0, 'y' => 180, 'width' => 320, 'height' => 60),
                ),
            ),
        ),
    ));
    $isolatedStackCss = blocks_engine_figma_transformer_contract_file_content($isolatedStackResult, 'style.css');
    blocks_engine_figma_transformer_contract_assert_css_rule_contains($assert, $isolatedStackCss, '.figma-node-isolated-stack-section-layered-image-section', array('width:320px', 'height:180px', 'isolation:isolate', 'position:absolute', 'left:0px', 'top:0px', 'display:flex', 'flex-direction:row', 'gap:0px'), 'visual-map-local-stack-clamps-negative-css-gap');

    $invalidGapResult = blocks_engine_figma_transformer_contract_transform(array(
        'name'  => 'Invalid Auto Layout Gap Fixture',
        'nodes' => array(
            array(
                'id'          => 'invalid-gap:nan',
                'type'        => 'FRAME',
                'name'        => 'NaN gap row',
                'width'       => 300,
                'height'      => 80,
                'layoutMode'  => 'HORIZONTAL',
                'itemSpacing' => NAN,
                'children'    => array(
                    array('id' => 'invalid-gap:nan:first', 'type' => 'RECTANGLE', 'name' => 'First', 'width' => 40, 'height' => 40),
                    array('id' => 'invalid-gap:nan:second', 'type' => 'RECTANGLE', 'name' => 'Second', 'width' => 40, 'height' => 40),
                ),
            ),
            array(
                'id'                 => 'invalid-gap:wrap',
                'type'               => 'FRAME',
                'name'               => 'Wrapping row with invalid counter gap',
                'width'              => 300,
                'height'             => 120,
                'layoutMode'         => 'HORIZONTAL',
                'layoutWrap'         => 'WRAP',
                'itemSpacing'        => -12,
                'counterAxisSpacing' => INF,
                'children'           => array(
                    array('id' => 'invalid-gap:wrap:first', 'type' => 'RECTANGLE', 'name' => 'First', 'width' => 160, 'height' => 40),
                    array('id' => 'invalid-gap:wrap:second', 'type' => 'RECTANGLE', 'name' => 'Second', 'width' => 160, 'height' => 40),
                ),
            ),
        ),
    ));
    $invalidGapCss = blocks_engine_figma_transformer_contract_file_content($invalidGapResult, 'style.css');
    $assert(! str_contains($invalidGapCss, 'NaNpx'), 'visual-map-gap-css-rejects-nan');
    $assert(! str_contains($invalidGapCss, 'INFpx') && ! str_contains($invalidGapCss, 'Infinity'), 'visual-map-gap-css-rejects-infinity');
    $assert(1 !== preg_match('/gap:[^;}]*-[0-9.]+px/', $invalidGapCss), 'visual-map-gap-css-clamps-negative-values');
    blocks_engine_figma_transformer_contract_assert_css_rule_contains($assert, $invalidGapCss, '.figma-node-invalid-gap-wrap-wrapping-row-with-invalid-counter-gap', array('width:300px', 'height:120px', 'display:flex', 'flex-direction:row', 'flex-wrap:wrap', 'align-content:flex-start', 'gap:0px'), 'visual-map-gap-css-falls-back-to-clamped-main-gap');

    $mixedLayerStackResult = blocks_engine_figma_transformer_contract_transform(array(
        'name'  => 'Mixed Layer Stack Fixture',
        'nodes' => array(
            array(
                'id'         => 'mixed-layer:section',
                'type'       => 'FRAME',
                'name'       => 'Feature image section',
                'width'      => 400,
                'height'     => 240,
                'layoutMode' => 'VERTICAL',
                'children'   => array(
                    array(
                        'id'                => 'mixed-layer:image',
                        'type'              => 'RECTANGLE',
                        'name'              => 'Featured image background',
                        'x'                 => 0,
                        'y'                 => 0,
                        'width'             => 400,
                        'height'            => 160,
                        'layoutPositioning' => 'ABSOLUTE',
                        'fill'              => array('r' => 1, 'g' => 0.85, 'b' => 0),
                    ),
                    array(
                        'id'         => 'mixed-layer:title',
                        'type'       => 'TEXT',
                        'name'       => 'Headline over image',
                        'x'          => 24,
                        'y'          => 24,
                        'width'      => 240,
                        'height'     => 48,
                        'characters' => 'Layered headline',
                        'fontSize'   => 32,
                        'fontWeight' => 700,
                    ),
                ),
            ),
        ),
    ));
    $mixedLayerStackCss = blocks_engine_figma_transformer_contract_file_content($mixedLayerStackResult, 'style.css');
    $mixedLayerStackDiagnostics = $mixedLayerStackResult['source_reports']['figma']['html']['transform_diagnostics'] ?? array();
    $mixedLayerStackingOrder = $mixedLayerStackDiagnostics['layout']['stacking_order'] ?? array();
    $mixedLayerArtifactSummary = $mixedLayerStackDiagnostics['artifact_quality']['summary'] ?? array();
    blocks_engine_figma_transformer_contract_assert_css_rule_contains($assert, $mixedLayerStackCss, '.figma-node-mixed-layer-section-feature-image-section', array('position:relative', 'isolation:isolate', 'display:flex', 'flex-direction:column'), 'visual-map-mixed-layer-parent-isolated');
    blocks_engine_figma_transformer_contract_assert_css_rule_contains($assert, $mixedLayerStackCss, '.figma-node-mixed-layer-image-featured-image-background', array('position:absolute', 'left:0px', 'top:0px', 'z-index:1', 'pointer-events:none', 'background:#ffd900'), 'visual-map-mixed-layer-image-behind');
    blocks_engine_figma_transformer_contract_assert_css_rule_contains($assert, $mixedLayerStackCss, '.figma-node-mixed-layer-title-headline-over-image', array('position:relative', 'z-index:2', 'font-size:32px', 'font-weight:700'), 'visual-map-mixed-layer-title-above');
    $assert(1 === ($mixedLayerStackingOrder['mixed_positioning_parent_count'] ?? null), 'visual-map-mixed-layer-diagnostics-mixed-position-parent-count');
    $assert(1 === ($mixedLayerStackingOrder['absolute_child_count'] ?? null), 'visual-map-mixed-layer-diagnostics-absolute-child-count');
    $assert(1 === ($mixedLayerStackingOrder['flow_child_count'] ?? null), 'visual-map-mixed-layer-diagnostics-flow-child-count');
    $assert(1 === ($mixedLayerStackingOrder['sample_nodes'][0]['child_layer_roles']['underlay'] ?? null), 'visual-map-mixed-layer-diagnostics-underlay-role-count');
    $assert(1 === ($mixedLayerStackingOrder['sample_nodes'][0]['child_layer_roles']['content'] ?? null), 'visual-map-mixed-layer-diagnostics-content-role-count');
    $assert(2 === ($mixedLayerStackingOrder['sample_nodes'][0]['child_z_index_reasons']['overlapping_sibling_layer_rank'] ?? null), 'visual-map-mixed-layer-diagnostics-z-index-reason-count');
    $assert(in_array('local_mixed_positioning_children', $mixedLayerStackingOrder['sample_nodes'][0]['local_stacking_reasons'] ?? array(), true), 'visual-map-mixed-layer-diagnostics-local-stack-reason');
    $assert('mixed-layer:section' === ($mixedLayerStackingOrder['sample_nodes'][0]['node_id'] ?? null), 'visual-map-mixed-layer-diagnostics-sample-node');
    $assert(1 === ($mixedLayerArtifactSummary['mixed_positioning_parent_count'] ?? null), 'visual-map-mixed-layer-artifact-summary-mixed-position-parent-count');

    $chromeOverHeroLayerResult = blocks_engine_figma_transformer_contract_transform(array(
        'name'  => 'Chrome Over Hero Layer Fixture',
        'nodes' => array(
            array(
                'id'         => 'layer-role:page',
                'type'       => 'FRAME',
                'name'       => 'Landing page',
                'width'      => 1440,
                'height'     => 720,
                'layoutMode' => 'VERTICAL',
                'children'   => array(
                    array(
                        'id'       => 'layer-role:hero',
                        'type'     => 'FRAME',
                        'name'     => 'Hero background panel',
                        'x'        => 0,
                        'y'        => 0,
                        'width'    => 1440,
                        'height'   => 320,
                        'layout'   => array('z_index' => 39),
                        'children' => array(
                            array('id' => 'layer-role:hero/text', 'type' => 'TEXT', 'name' => 'Hero headline', 'characters' => 'Hero', 'x' => 120, 'y' => 140, 'width' => 200, 'height' => 48, 'fontSize' => 40),
                        ),
                    ),
                    array(
                        'id'       => 'layer-role:header',
                        'type'     => 'FRAME',
                        'name'     => 'Site header navigation',
                        'x'        => 0,
                        'y'        => 0,
                        'width'    => 1440,
                        'height'   => 80,
                        'layout'   => array('z_index' => 16),
                        'children' => array(
                            array('id' => 'layer-role:header/logo', 'type' => 'TEXT', 'name' => 'Logo', 'characters' => 'Logo', 'x' => 32, 'y' => 24, 'width' => 80, 'height' => 24),
                            array('id' => 'layer-role:header/nav', 'type' => 'TEXT', 'name' => 'Navigation links', 'characters' => 'Menu', 'x' => 1200, 'y' => 24, 'width' => 120, 'height' => 24),
                        ),
                    ),
                ),
            ),
        ),
    ));
    $chromeOverHeroLayerCss = blocks_engine_figma_transformer_contract_file_content($chromeOverHeroLayerResult, 'style.css');
    blocks_engine_figma_transformer_contract_assert_css_rule_contains($assert, $chromeOverHeroLayerCss, '.figma-node-layer-role-hero-hero-background-panel', array('position:relative', 'z-index:1'), 'visual-map-layer-role-hero-content-below-chrome');
    blocks_engine_figma_transformer_contract_assert_css_rule_contains($assert, $chromeOverHeroLayerCss, '.figma-node-layer-role-header-site-header-navigation', array('position:relative', 'z-index:2'), 'visual-map-layer-role-header-chrome-above-hero');

    $reverseOrderSectionsResult = blocks_engine_figma_transformer_contract_transform(array(
        'name'  => 'Reverse Order Non Overlapping Sections Fixture',
        'nodes' => array(
            array(
                'id'         => 'reverse-sections:page',
                'type'       => 'FRAME',
                'name'       => 'Reverse ordered page',
                'width'      => 1440,
                'height'     => 720,
                'layoutMode' => 'VERTICAL',
                'layout'     => array('display' => 'flex', 'reverse_z_index' => true),
                'children'   => array(
                    array('id' => 'reverse-sections:header', 'type' => 'FRAME', 'name' => 'Header band', 'x' => 0, 'y' => 0, 'width' => 1440, 'height' => 120),
                    array('id' => 'reverse-sections:content', 'type' => 'FRAME', 'name' => 'Content band', 'x' => 0, 'y' => 240, 'width' => 1440, 'height' => 320),
                ),
            ),
        ),
    ));
    $reverseOrderSectionsCss = blocks_engine_figma_transformer_contract_file_content($reverseOrderSectionsResult, 'style.css');
    blocks_engine_figma_transformer_contract_assert_css_rule_omits($assert, $reverseOrderSectionsCss, '.figma-node-reverse-sections-header-header-band', array('z-index:'), 'visual-map-reverse-order-non-overlap-header-not-z-indexed');
    blocks_engine_figma_transformer_contract_assert_css_rule_omits($assert, $reverseOrderSectionsCss, '.figma-node-reverse-sections-content-content-band', array('z-index:'), 'visual-map-reverse-order-non-overlap-content-not-z-indexed');

    $flippedChromeStripResult = blocks_engine_figma_transformer_contract_transform(array(
        'name'  => 'Flipped Chrome Strip Fixture',
        'nodes' => array(
            array(
                'id'       => 'chrome-strip:page',
                'type'     => 'FRAME',
                'name'     => 'Chrome strip page',
                'width'    => 1440,
                'height'   => 640,
                'children' => array(
                    array(
                        'id'       => 'chrome-strip:footer',
                        'type'     => 'FRAME',
                        'name'     => 'Footer chrome',
                        'x'        => 0,
                        'y'        => 320,
                        'width'    => 1440,
                        'height'   => 251,
                        'children' => array(
                            array('id' => 'chrome-strip:footer/background', 'type' => 'RECTANGLE', 'name' => 'Footer background', 'x' => 0, 'y' => 0, 'width' => 1440, 'height' => 251, 'fill' => array('r' => 0.1, 'g' => 0.5, 'b' => 0.6)),
                            array(
                                'id'        => 'chrome-strip:footer/bottom-strip',
                                'type'      => 'RECTANGLE',
                                'name'      => 'Bottom info strip',
                                'x'         => 0,
                                'y'         => 251,
                                'width'     => 1440,
                                'height'    => 53,
                                'transform' => array(
                                    'm00' => 1,
                                    'm01' => 0,
                                    'm02' => 0,
                                    'm10' => 0,
                                    'm11' => -1,
                                    'm12' => 0,
                                ),
                                'fill'      => array('r' => 0.5, 'g' => 0.85, 'b' => 0.92),
                            ),
                            array('id' => 'chrome-strip:footer/copy', 'type' => 'TEXT', 'name' => 'Footer copy', 'characters' => 'Open: M-F 9am-5pm', 'x' => 440, 'y' => 217, 'width' => 500, 'height' => 14),
                        ),
                    ),
                ),
            ),
        ),
    ));
    $flippedChromeStripCss = blocks_engine_figma_transformer_contract_file_content($flippedChromeStripResult, 'style.css');
    $flippedChromeStripDiagnostics = $flippedChromeStripResult['source_reports']['figma']['html']['transform_diagnostics'] ?? array();
    $flippedChromeStripReserveTraces = array_values(array_filter(
        is_array($flippedChromeStripDiagnostics['decision_traces']['samples'] ?? null) ? $flippedChromeStripDiagnostics['decision_traces']['samples'] : array(),
        static fn (array $trace): bool => 'absolute_child_reserve_height_from_visual_bounds' === ($trace['reason_code'] ?? null)
    ));
    $flippedChromeStripBottomTrace = array_values(array_filter(
        is_array($flippedChromeStripReserveTraces[0]['evidence']['child_bounds'] ?? null) ? $flippedChromeStripReserveTraces[0]['evidence']['child_bounds'] : array(),
        static fn (array $child): bool => 'chrome-strip:footer/bottom-strip' === ($child['node_id'] ?? null)
    ));
    blocks_engine_figma_transformer_contract_assert_css_rule_contains($assert, $flippedChromeStripCss, '.figma-node-chrome-strip-footer-footer-chrome', array('height:251px', 'min-height:251px'), 'visual-map-flipped-chrome-strip-reserves-rendered-height');
    blocks_engine_figma_transformer_contract_assert_css_rule_contains($assert, $flippedChromeStripCss, '.figma-node-chrome-strip-footer-bottom-strip-bottom-info-strip', array('height:53px', 'top:251px', 'transform:matrix(1,0,0,-1,0,0)', 'transform-origin:0 0'), 'visual-map-flipped-chrome-strip-keeps-source-transform');
    $assert(! str_contains($flippedChromeStripCss, '.figma-node-chrome-strip-footer-footer-chrome{width:100%;height:251px;min-height:304px'), 'visual-map-flipped-chrome-strip-no-untransformed-reserve-height');
    $assert(1 === ($flippedChromeStripDiagnostics['decision_traces']['reason_counts']['absolute_child_reserve_height_from_visual_bounds'] ?? null), 'visual-map-flipped-chrome-strip-reserve-height-traced');
    $assert(array('x' => 0.0, 'y' => 251.0, 'width' => 1440.0, 'height' => 53.0) === ($flippedChromeStripBottomTrace[0]['source_box'] ?? null), 'visual-map-flipped-chrome-strip-trace-source-box');
    $assert(array('x' => 0.0, 'y' => 198.0, 'width' => 1440.0, 'height' => 53.0) === ($flippedChromeStripBottomTrace[0]['transformed_visual_box'] ?? null), 'visual-map-flipped-chrome-strip-trace-transformed-box');
    $assert(array('min_height' => 251.0) === ($flippedChromeStripReserveTraces[0]['evidence']['emitted_css_box'] ?? null), 'visual-map-flipped-chrome-strip-trace-emitted-css-box');

    $fullBleedVectorBandResult = blocks_engine_figma_transformer_contract_transform(array(
        'name'  => 'Full Bleed Vector Band Fixture',
        'nodes' => array(
            array(
                'id'       => 'vector-band:page',
                'type'     => 'FRAME',
                'name'     => 'Vector band page',
                'width'    => 1440,
                'height'   => 640,
                'layoutMode' => 'VERTICAL',
                'children' => array(
                    array(
                        'id'                => 'vector-band:diagonal',
                        'type'              => 'VECTOR',
                        'name'              => 'Diagonal rectangle band',
                        'x'                 => 0,
                        'y'                 => 120,
                        'width'             => 1440,
                        'height'            => 220,
                        'layoutPositioning' => 'ABSOLUTE',
                        'opacity'           => 0.5,
                        'transform'         => array(
                            'm00' => 1,
                            'm01' => 0,
                            'm02' => 0,
                            'm10' => 0,
                            'm11' => -1,
                            'm12' => 0,
                        ),
                        'paints'            => array(
                            array('type' => 'SOLID', 'color' => array('r' => 0.9328333139419556, 'g' => 0.9666666984558105, 'b' => 0.7975000143051147, 'a' => 1)),
                        ),
                    ),
                    array('id' => 'vector-band:headline', 'type' => 'TEXT', 'name' => 'Headline', 'characters' => 'Foreground content', 'x' => 480, 'y' => 180, 'width' => 360, 'height' => 64, 'fontSize' => 40),
                ),
            ),
        ),
    ));
    $fullBleedVectorBandCss = blocks_engine_figma_transformer_contract_file_content($fullBleedVectorBandResult, 'style.css');
    $fullBleedVectorBandHtml = blocks_engine_figma_transformer_contract_file_content($fullBleedVectorBandResult, 'index.html');
    blocks_engine_figma_transformer_contract_assert_css_rule_contains($assert, $fullBleedVectorBandCss, '.figma-node-vector-band-diagonal-diagonal-rectangle-band', array('width:100vw', 'left:50%', 'margin-left:-50vw', 'pointer-events:none', 'opacity:0.5', 'transform:matrix(1,0,0,-1,0,0)', 'transform-origin:0 0'), 'visual-map-full-bleed-vector-band-keeps-css-layering');
    $assert(str_contains($fullBleedVectorBandHtml, 'data-figma-node-id="vector-band:diagonal"') && str_contains($fullBleedVectorBandHtml, 'fill="#eef7cb"'), 'visual-map-full-bleed-vector-band-keeps-svg-fill');

    $invalidCssSanitizationResult = blocks_engine_figma_transformer_contract_transform(array(
        'name'  => 'Invalid CSS Sanitization Fixture',
        'nodes' => array(
            array(
                'id'       => 'invalid-css:root',
                'type'     => 'FRAME',
                'name'     => 'NaN Root Shell',
                'width'    => 1440,
                'height'   => 900,
                'children' => array(
                    array('id' => 'invalid-css:child', 'type' => 'RECTANGLE', 'name' => 'Infinity card', 'x' => NAN, 'y' => INF, 'width' => INF, 'height' => NAN),
                ),
            ),
        ),
    ), array('font_css' => '@font-face{font-family:"False Positive";src:url("https://example.com/NaN-INF-Infinity.woff2") format("woff2")}'));
    $invalidCssSanitizationCss = blocks_engine_figma_transformer_contract_file_content($invalidCssSanitizationResult, 'style.css');
    $invalidCssSanitizationDiagnostics = $invalidCssSanitizationResult['source_reports']['figma']['html']['transform_diagnostics']['layout'] ?? array();
    $assert(0 === preg_match('/(?<![A-Za-z0-9_-])(?:NaN|Infinity|INF)(?![A-Za-z0-9_-])|gap:-/', str_replace('https://example.com/NaN-INF-Infinity.woff2', '', $invalidCssSanitizationCss)), 'visual-map-invalid-css-finite-layout-output');
    $assert(0 === ($invalidCssSanitizationDiagnostics['invalid_css_count'] ?? null), 'visual-map-invalid-css-url-false-positive-ignored');

    $invalidCssDetectionResult = blocks_engine_figma_transformer_contract_transform(array(
        'name'  => 'Invalid CSS Detection Fixture',
        'nodes' => array(
            array('id' => 'invalid-css-detect:root', 'type' => 'FRAME', 'name' => 'Root', 'width' => 320, 'height' => 180),
        ),
    ), array('font_css' => 'body{width:NaNpx;background-image:url("https://example.com/Infinity.png")}'));
    $invalidCssDetectionDiagnostics = $invalidCssDetectionResult['source_reports']['figma']['html']['transform_diagnostics']['layout'] ?? array();
    $assert(1 === ($invalidCssDetectionDiagnostics['invalid_css_count'] ?? null), 'visual-map-invalid-css-detects-declaration-token');

    $numericSharedClassResult = blocks_engine_figma_transformer_contract_transform(array(
        'name'  => 'Numeric Shared Class Fixture',
        'nodes' => array(
            array(
                'id'         => 'numeric-shared:root',
                'type'       => 'FRAME',
                'name'       => 'Root',
                'width'      => 120,
                'height'     => 40,
                'layoutMode' => 'HORIZONTAL',
                'children'   => array(
                    array('id' => 'numeric-shared:1', 'type' => 'RECTANGLE', 'name' => '1', 'width' => 16, 'height' => 8, 'fill' => '#ffffff'),
                    array('id' => 'numeric-shared:2', 'type' => 'RECTANGLE', 'name' => '2', 'width' => 16, 'height' => 8, 'fill' => '#ffffff'),
                ),
            ),
        ),
    ));
    $numericSharedClassCss = blocks_engine_figma_transformer_contract_file_content($numericSharedClassResult, 'style.css');
    $numericSharedClassHtml = blocks_engine_figma_transformer_contract_file_content($numericSharedClassResult, 'index.html');
    $assert(! str_contains($numericSharedClassCss, '.1{'), 'visual-map-numeric-shared-class-does-not-emit-invalid-selector');
    $assert(str_contains($numericSharedClassCss, '.style-1{') && str_contains($numericSharedClassHtml, 'class="figma-node-numeric-shared-1-1 style-1"'), 'visual-map-numeric-shared-class-prefixed');

    $componentCloneZIndexResult = blocks_engine_figma_transformer_contract_transform(
        array(
            'name'  => 'Component Clone Z Index Fixture',
            'nodes' => array(
                array(
                    'id'                 => 'clone-z:component',
                    'type'               => 'COMPONENT',
                    'name'               => 'Layered component',
                    'width'              => 240,
                    'height'             => 120,
                    'layoutMode'         => 'HORIZONTAL',
                    'itemSpacing'        => -80,
                    'stackReverseZIndex' => true,
                    'children'           => array(
                        array('id' => 'clone-z:component/front', 'type' => 'RECTANGLE', 'name' => 'Front panel', 'width' => 160, 'height' => 120),
                        array('id' => 'clone-z:component/back', 'type' => 'RECTANGLE', 'name' => 'Back panel', 'width' => 160, 'height' => 120),
                    ),
                ),
                array(
                    'id'       => 'clone-z:page',
                    'type'     => 'FRAME',
                    'name'     => 'Page',
                    'width'    => 320,
                    'height'   => 180,
                    'children' => array(
                        array(
                            'id'          => 'clone-z:instance',
                            'type'        => 'INSTANCE',
                            'name'        => 'Layered instance',
                            'componentId' => 'clone-z:component',
                            'x'           => 20,
                            'y'           => 30,
                            'width'       => 240,
                            'height'      => 120,
                        ),
                    ),
                ),
            ),
        ),
        array('frame_id' => 'clone-z:page')
    );
    $componentCloneZIndexCss = blocks_engine_figma_transformer_contract_file_content($componentCloneZIndexResult, 'style.css');
    $assert(str_contains($componentCloneZIndexCss, '.back-panel{width:160px;height:120px;position:relative;z-index:2;flex-shrink:0}'), 'visual-map-component-clone-preserves-back-z-index');
    $assert(str_contains($componentCloneZIndexCss, '.front-panel{width:160px;height:120px;position:relative;z-index:1;flex-shrink:0}'), 'visual-map-component-clone-preserves-front-z-index');

    $visualFlexOffCanvasResult = blocks_engine_figma_transformer_contract_transform(array(
        'name'  => 'Visual Flex Off Canvas Classification Fixture',
        'nodes' => array(
            array(
                'id'                    => 'visual-flex-off-canvas:row',
                'type'                  => 'FRAME',
                'name'                  => 'Overflowing fixed gap row',
                'width'                 => 200,
                'height'                => 80,
                'layoutMode'            => 'HORIZONTAL',
                'primaryAxisAlignItems' => 'MIN',
                'counterAxisAlignItems' => 'MIN',
                'itemSpacing'           => 500,
                'children'              => array(
                    array('id' => 'visual-flex-off-canvas:first', 'type' => 'RECTANGLE', 'name' => 'First child', 'width' => 80, 'height' => 40),
                    array('id' => 'visual-flex-off-canvas:second', 'type' => 'RECTANGLE', 'name' => 'Second child', 'width' => 80, 'height' => 40),
                ),
            ),
        ),
    ));
    $visualFlexOffCanvasNodes = $visualFlexOffCanvasResult['source_reports']['figma']['html']['transform_diagnostics']['layout']['off_canvas_visual_nodes'] ?? array();
    $assert('flex_flow_overflow' === ($visualFlexOffCanvasNodes[0]['classification'] ?? null), 'visual-map-flex-off-canvas-classification');

    $visualDistributedSpacingResult = blocks_engine_figma_transformer_contract_transform(array(
        'name'  => 'Visual Distributed Spacing Fixture',
        'nodes' => array(
            array(
                'id'                    => 'visual-distributed:row',
                'type'                  => 'FRAME',
                'name'                  => 'Distributed row',
                'width'                 => 1440,
                'height'                => 131,
                'layoutMode'            => 'HORIZONTAL',
                'stackPrimaryAlignItems' => 'SPACE_EVENLY',
                'counterAxisAlignItems' => 'CENTER',
                'paddingLeft'           => 112,
                'paddingRight'          => 112,
                'itemSpacing'           => 920,
                'children'              => array(
                    array('id' => 'visual-distributed:first', 'type' => 'RECTANGLE', 'name' => 'First child', 'width' => 228, 'height' => 35),
                    array('id' => 'visual-distributed:second', 'type' => 'RECTANGLE', 'name' => 'Second child', 'width' => 265, 'height' => 26),
                    array('id' => 'visual-distributed:third', 'type' => 'TEXT', 'name' => 'Third child', 'characters' => 'Proudly powered by WordPress.com', 'width' => 281, 'height' => 26),
                ),
            ),
        ),
    ));
    $visualDistributedThird = blocks_engine_figma_transformer_contract_find_visual_node($visualDistributedSpacingResult, 'visual-distributed:third');
    $visualDistributedOffCanvasNodes = $visualDistributedSpacingResult['source_reports']['figma']['html']['transform_diagnostics']['layout']['off_canvas_visual_nodes'] ?? array();
    $visualDistributedCss = blocks_engine_figma_transformer_contract_file_content($visualDistributedSpacingResult, 'index.html');
    $assert(1047.0 === ($visualDistributedThird['rect']['x'] ?? null), 'visual-map-distributed-spacing-third-x');
    $assert(array() === $visualDistributedOffCanvasNodes, 'visual-map-distributed-spacing-no-off-canvas');
    $assert(str_contains($visualDistributedCss, 'justify-content:space-between'), 'visual-map-distributed-spacing-emits-justify-content');
    $assert(! str_contains($visualDistributedCss, 'gap:920px'), 'visual-map-distributed-spacing-suppresses-packed-gap');

    $visualFlexCrossOverflowMap = (new Automattic\BlocksEngine\FigmaTransformer\Html\VisualNodeMapBuilder())->build(array(
        array(
            'id'       => 'visual-cross:column',
            'type'     => 'FRAME',
            'name'     => 'Overflow centered column',
            'box'      => array('width' => 120, 'height' => 120),
            'layout'   => array('display' => 'flex', 'flex_direction' => 'column', 'align_items' => 'center'),
            'children' => array(
                array('id' => 'visual-cross:wide', 'type' => 'RECTANGLE', 'name' => 'Wide centered child', 'box' => array('width' => 200, 'height' => 30)),
            ),
        ),
    ));
    $visualFlexCrossOverflowWide = blocks_engine_figma_transformer_contract_find_visual_node_in_map($visualFlexCrossOverflowMap, 'visual-cross:wide');
    $assert(-40.0 === ($visualFlexCrossOverflowWide['rect']['x'] ?? null), 'visual-map-column-overflow-center-child-x');

    $visualFlexOverflowResult = blocks_engine_figma_transformer_contract_transform(array(
        'name'  => 'Visual Flex Overflow Alignment Fixture',
        'nodes' => array(
            array(
                'id'                    => 'visual-overflow:flex-end',
                'type'                  => 'FRAME',
                'name'                  => 'Overflow flex end row',
                'width'                 => 80,
                'height'                => 40,
                'layoutMode'            => 'HORIZONTAL',
                'primaryAxisAlignItems' => 'MAX',
                'counterAxisAlignItems' => 'MIN',
                'itemSpacing'           => 8,
                'children'              => array(
                    array('id' => 'visual-overflow:end-first', 'type' => 'RECTANGLE', 'name' => 'End first child', 'width' => 50, 'height' => 20),
                    array('id' => 'visual-overflow:end-second', 'type' => 'RECTANGLE', 'name' => 'End second child', 'width' => 50, 'height' => 20),
                ),
            ),
            array(
                'id'                    => 'visual-overflow:center',
                'type'                  => 'FRAME',
                'name'                  => 'Overflow centered row',
                'width'                 => 80,
                'height'                => 40,
                'layoutMode'            => 'HORIZONTAL',
                'primaryAxisAlignItems' => 'CENTER',
                'counterAxisAlignItems' => 'MIN',
                'itemSpacing'           => 8,
                'children'              => array(
                    array('id' => 'visual-overflow:center-first', 'type' => 'RECTANGLE', 'name' => 'Center first child', 'width' => 50, 'height' => 20),
                    array('id' => 'visual-overflow:center-second', 'type' => 'RECTANGLE', 'name' => 'Center second child', 'width' => 50, 'height' => 20),
                ),
            ),
        ),
    ));
    $visualOverflowEndFirst = blocks_engine_figma_transformer_contract_find_visual_node($visualFlexOverflowResult, 'visual-overflow:end-first');
    $visualOverflowEndSecond = blocks_engine_figma_transformer_contract_find_visual_node($visualFlexOverflowResult, 'visual-overflow:end-second');
    $visualOverflowCenterFirst = blocks_engine_figma_transformer_contract_find_visual_node($visualFlexOverflowResult, 'visual-overflow:center-first');
    $visualOverflowCenterSecond = blocks_engine_figma_transformer_contract_find_visual_node($visualFlexOverflowResult, 'visual-overflow:center-second');
    $assert(-28.0 === ($visualOverflowEndFirst['rect']['x'] ?? null), 'visual-map-overflow-flex-end-first-x');
    $assert(30.0 === ($visualOverflowEndSecond['rect']['x'] ?? null), 'visual-map-overflow-flex-end-second-x');
    $assert(-14.0 === ($visualOverflowCenterFirst['rect']['x'] ?? null), 'visual-map-overflow-center-first-x');
    $assert(44.0 === ($visualOverflowCenterSecond['rect']['x'] ?? null), 'visual-map-overflow-center-second-x');

    $visualFlexWrapResult = blocks_engine_figma_transformer_contract_transform(array(
        'name'  => 'Visual Flex Wrap Fixture',
        'nodes' => array(
            array(
                'id'                    => 'visual-wrap:frame',
                'type'                  => 'FRAME',
                'name'                  => 'Wrapped card row',
                'width'                 => 220,
                'height'                => 200,
                'layoutMode'            => 'HORIZONTAL',
                'layoutWrap'            => 'WRAP',
                'itemSpacing'           => 10,
                'counterAxisAlignItems' => 'MIN',
                'children'              => array(
                    array('id' => 'visual-wrap:first', 'type' => 'RECTANGLE', 'name' => 'First card', 'width' => 100, 'height' => 40),
                    array('id' => 'visual-wrap:second', 'type' => 'RECTANGLE', 'name' => 'Second card', 'width' => 100, 'height' => 60),
                    array('id' => 'visual-wrap:third', 'type' => 'RECTANGLE', 'name' => 'Third card', 'width' => 100, 'height' => 30),
                ),
            ),
        ),
    ));
    $visualFlexWrapCss = blocks_engine_figma_transformer_contract_file_content($visualFlexWrapResult, 'style.css');
    $visualFlexWrapFirst = blocks_engine_figma_transformer_contract_find_visual_node($visualFlexWrapResult, 'visual-wrap:first');
    $visualFlexWrapSecond = blocks_engine_figma_transformer_contract_find_visual_node($visualFlexWrapResult, 'visual-wrap:second');
    $visualFlexWrapThird = blocks_engine_figma_transformer_contract_find_visual_node($visualFlexWrapResult, 'visual-wrap:third');
    $assert(str_contains($visualFlexWrapCss, 'flex-wrap:wrap;align-content:flex-start'), 'visual-map-flex-wrap-align-content-packed');
    blocks_engine_figma_transformer_contract_assert_node_rect($assert, $visualFlexWrapFirst, array('x' => 0.0, 'y' => 0.0, 'width' => 100.0, 'height' => 40.0), 'visual-map-flex-wrap-first-line-first-card');
    blocks_engine_figma_transformer_contract_assert_node_rect($assert, $visualFlexWrapSecond, array('x' => 110.0, 'y' => 0.0, 'width' => 100.0, 'height' => 60.0), 'visual-map-flex-wrap-first-line-second-card');
    blocks_engine_figma_transformer_contract_assert_node_rect($assert, $visualFlexWrapThird, array('x' => 0.0, 'y' => 70.0, 'width' => 100.0, 'height' => 30.0), 'visual-map-flex-wrap-second-line-card');

    $visualIndependentWrapGapResult = blocks_engine_figma_transformer_contract_transform(array(
        'name'  => 'Visual Independent Wrap Gap Fixture',
        'nodes' => array(
            array(
                'id'                 => 'visual-independent-gap:frame',
                'type'               => 'FRAME',
                'name'               => 'Independent wrap gap row',
                'width'              => 796,
                'height'             => 800,
                'layoutMode'         => 'HORIZONTAL',
                'layoutWrap'         => 'WRAP',
                'itemSpacing'        => 44,
                'counterAxisSpacing' => -64,
                'children'           => array(
                    array('id' => 'visual-independent-gap:first', 'type' => 'RECTANGLE', 'name' => 'First card', 'width' => 376, 'height' => 240),
                    array('id' => 'visual-independent-gap:second', 'type' => 'RECTANGLE', 'name' => 'Second card', 'width' => 376, 'height' => 240),
                    array('id' => 'visual-independent-gap:third', 'type' => 'RECTANGLE', 'name' => 'Third card', 'width' => 376, 'height' => 240),
                ),
            ),
        ),
    ));
    $visualIndependentWrapGapCss = blocks_engine_figma_transformer_contract_file_content($visualIndependentWrapGapResult, 'style.css');
    $visualIndependentWrapGapSecond = blocks_engine_figma_transformer_contract_find_visual_node($visualIndependentWrapGapResult, 'visual-independent-gap:second');
    $visualIndependentWrapGapThird = blocks_engine_figma_transformer_contract_find_visual_node($visualIndependentWrapGapResult, 'visual-independent-gap:third');
    blocks_engine_figma_transformer_contract_assert_css_rule_contains($assert, $visualIndependentWrapGapCss, '.figma-node-visual-independent-gap-frame-independent-wrap-gap-row', array('gap:0px 44px'), 'visual-map-independent-wrap-gap-css');
    $assert(420.0 === ($visualIndependentWrapGapSecond['rect']['x'] ?? null), 'visual-map-independent-wrap-column-gap');
    $assert(240.0 === ($visualIndependentWrapGapThird['rect']['y'] ?? null), 'visual-map-independent-wrap-row-gap-clamped');

    $visualIndependentColumnWrapGapResult = blocks_engine_figma_transformer_contract_transform(array(
        'name'  => 'Visual Independent Column Wrap Gap Fixture',
        'nodes' => array(
            array(
                'id'                 => 'visual-independent-column-gap:frame',
                'type'               => 'FRAME',
                'name'               => 'Independent wrap gap column',
                'width'              => 800,
                'height'             => 796,
                'layoutMode'         => 'VERTICAL',
                'layoutWrap'         => 'WRAP',
                'itemSpacing'        => 44,
                'counterAxisSpacing' => -64,
                'children'           => array(
                    array('id' => 'visual-independent-column-gap:first', 'type' => 'RECTANGLE', 'name' => 'First card', 'width' => 240, 'height' => 376),
                    array('id' => 'visual-independent-column-gap:second', 'type' => 'RECTANGLE', 'name' => 'Second card', 'width' => 240, 'height' => 376),
                    array('id' => 'visual-independent-column-gap:third', 'type' => 'RECTANGLE', 'name' => 'Third card', 'width' => 240, 'height' => 376),
                ),
            ),
        ),
    ));
    $visualIndependentColumnWrapGapCss = blocks_engine_figma_transformer_contract_file_content($visualIndependentColumnWrapGapResult, 'style.css');
    $visualIndependentColumnWrapGapThird = blocks_engine_figma_transformer_contract_find_visual_node($visualIndependentColumnWrapGapResult, 'visual-independent-column-gap:third');
    blocks_engine_figma_transformer_contract_assert_css_rule_contains($assert, $visualIndependentColumnWrapGapCss, '.figma-node-visual-independent-column-gap-frame-independent-wrap-gap-column', array('gap:44px 0px'), 'visual-map-independent-column-wrap-gap-css');
    $assert(240.0 === ($visualIndependentColumnWrapGapThird['rect']['x'] ?? null), 'visual-map-independent-column-wrap-column-gap-clamped');

    $componentLocalGeometryMap = (new Automattic\BlocksEngine\FigmaTransformer\Html\VisualNodeMapBuilder())->build(array(
        array(
            'id'       => 'visual-component-local:instance',
            'type'     => 'INSTANCE',
            'name'     => 'Component instance',
            'box'      => array('width' => 120, 'height' => 80, 'coordinate_space' => 'local'),
            'children' => array(
                array(
                    'id'                               => 'visual-component-local:instance/child',
                    'type'                             => 'RECTANGLE',
                    'name'                             => 'Component local child',
                    '_component_source_clone_geometry' => true,
                    'box'                              => array('x' => 12, 'y' => 8, 'width' => 40, 'height' => 24, 'coordinate_space' => 'local'),
                ),
            ),
        ),
    ));
    $componentLocalGeometryChild = blocks_engine_figma_transformer_contract_find_visual_node_in_map($componentLocalGeometryMap, 'visual-component-local:instance/child');
    $assert('local' === ($componentLocalGeometryChild['coordinate_space'] ?? null), 'visual-map-component-local-coordinate-space');
    $assert('unresolved_component_local' === ($componentLocalGeometryChild['geometry_confidence'] ?? null), 'visual-map-component-local-geometry-confidence');

    foreach ( array('transform', 'absolute_transform', 'override_transform') as $sourceKind ) {
        $componentTransformGeometryMap = (new Automattic\BlocksEngine\FigmaTransformer\Html\VisualNodeMapBuilder())->build(array(
            array(
                'id' => 'visual-component-transform:' . $sourceKind,
                'type' => 'RECTANGLE',
                'name' => 'Component transform child',
                '_component_source_clone_geometry' => true,
                'box' => array('x' => 12, 'y' => 8, 'width' => 40, 'height' => 24, 'coordinate_space' => 'local', 'component_clone_source_kind' => $sourceKind),
            ),
        ));
        $componentTransformGeometryChild = blocks_engine_figma_transformer_contract_find_visual_node_in_map($componentTransformGeometryMap, 'visual-component-transform:' . $sourceKind);
        $assert(! isset($componentTransformGeometryChild['geometry_confidence']), 'visual-map-component-' . $sourceKind . '-geometry-remains-comparable');
    }

    $strokeShadowResult = blocks_engine_figma_transformer_contract_transform(array(
        'name'  => 'Stroke Shadow Fixture',
        'nodes' => array(
            array(
                'id'           => 'stroke-shadow:rect',
                'type'         => 'RECTANGLE',
                'name'         => 'Stroke and shadow',
                'width'        => 100,
                'height'       => 100,
                'strokeWeight' => 5,
                'strokeAlign'  => 'OUTSIDE',
                'strokePaints' => array(array('type' => 'SOLID', 'color' => array('r' => 1, 'g' => 0.8117647059, 'b' => 0, 'a' => 1))),
                'effects'      => array(array(
                    'type'    => 'DROP_SHADOW',
                    'offset'  => array('x' => 0, 'y' => 0),
                    'radius'  => 16,
                    'spread'  => 0,
                    'visible' => true,
                    'color'   => array('r' => 1, 'g' => 0.8117647059, 'b' => 0, 'a' => 0.5),
                )),
            ),
        ),
    ));
    $strokeShadowCss = blocks_engine_figma_transformer_contract_file_content($strokeShadowResult, 'style.css');
    $assert(str_contains($strokeShadowCss, 'box-shadow:0 0 0 5px #ffcf00,0px 0px 16px 0px rgba(255,207,0,0.5)'), 'visual-map-stroke-and-effect-box-shadows-merged');
    $assert(1 === substr_count($strokeShadowCss, 'box-shadow:'), 'visual-map-stroke-and-effect-single-box-shadow-declaration');

    $visualCrossAxisFillResult = blocks_engine_figma_transformer_contract_transform(array(
        'name'  => 'Visual Cross Axis Fill Fixture',
        'nodes' => array(
            array(
                'id'                    => 'visual-cross-fill:row',
                'type'                  => 'FRAME',
                'name'                  => 'Cross axis fill row',
                'width'                 => 300,
                'height'                => 100,
                'layoutMode'            => 'HORIZONTAL',
                'primaryAxisAlignItems' => 'MIN',
                'counterAxisAlignItems' => 'MIN',
                'itemSpacing'           => 20,
                'children'              => array(
                    array(
                        'id'                     => 'visual-cross-fill:tall',
                        'type'                   => 'RECTANGLE',
                        'name'                   => 'Tall fill child',
                        'width'                  => 50,
                        'height'                 => 100,
                        'layoutSizingHorizontal' => 'FIXED',
                        'layoutSizingVertical'   => 'FILL',
                    ),
                    array('id' => 'visual-cross-fill:next', 'type' => 'RECTANGLE', 'name' => 'Next child', 'width' => 80, 'height' => 40),
                ),
            ),
        ),
    ));
    $visualCrossAxisFillCss = blocks_engine_figma_transformer_contract_file_content($visualCrossAxisFillResult, 'style.css');
    $visualCrossAxisFillTall = blocks_engine_figma_transformer_contract_find_visual_node($visualCrossAxisFillResult, 'visual-cross-fill:tall');
    $visualCrossAxisFillNext = blocks_engine_figma_transformer_contract_find_visual_node($visualCrossAxisFillResult, 'visual-cross-fill:next');
    $assert(str_contains($visualCrossAxisFillCss, '.figma-node-visual-cross-fill-tall-tall-fill-child{width:50px;height:100%;flex-shrink:0}'), 'visual-map-cross-axis-fill-does-not-grow-main-axis-css');
    $assert(! str_contains($visualCrossAxisFillCss, '.figma-node-visual-cross-fill-tall-tall-fill-child{width:50px;height:100%;flex-grow:1'), 'visual-map-cross-axis-fill-no-flex-grow-css');
    blocks_engine_figma_transformer_contract_assert_node_rect($assert, $visualCrossAxisFillTall, array('x' => 100.0, 'y' => 0.0, 'width' => 50.0, 'height' => 100.0), 'visual-map-cross-axis-fill-source-width-preserved');
    blocks_engine_figma_transformer_contract_assert_node_rect($assert, $visualCrossAxisFillNext, array('x' => 0.0, 'y' => 0.0, 'width' => 80.0, 'height' => 40.0), 'visual-map-cross-axis-fill-next-child-not-pushed');

    $freeformTransitionResult = blocks_engine_figma_transformer_contract_transform(array(
        'name'  => 'Auto Layout Freeform Transition Fixture',
        'nodes' => array(
            array(
                'id'         => 'layout-transition:flex',
                'type'       => 'FRAME',
                'name'       => 'Auto layout shell',
                'width'      => 360,
                'height'     => 180,
                'layoutMode' => 'HORIZONTAL',
                'itemSpacing' => 12,
                'children'   => array(
                    array('id' => 'layout-transition:flow-a', 'type' => 'RECTANGLE', 'name' => 'Flow A', 'width' => 80, 'height' => 40),
                    array('id' => 'layout-transition:absolute', 'type' => 'RECTANGLE', 'name' => 'Pinned badge', 'x' => 250, 'y' => 20, 'width' => 40, 'height' => 24, 'layoutPositioning' => 'ABSOLUTE'),
                    array('id' => 'layout-transition:flow-b', 'type' => 'RECTANGLE', 'name' => 'Flow B', 'width' => 60, 'height' => 40),
                ),
            ),
            array(
                'id'       => 'layout-transition:freeform',
                'type'     => 'FRAME',
                'name'     => 'Freeform board',
                'width'    => 360,
                'height'   => 180,
                'children' => array(
                    array('id' => 'layout-transition:local-card', 'type' => 'RECTANGLE', 'name' => 'Local card', 'x' => 44, 'y' => 66, 'width' => 90, 'height' => 30),
                ),
            ),
        ),
    ));
    $transitionCss = blocks_engine_figma_transformer_contract_file_content($freeformTransitionResult, 'style.css');
    $transitionFlowA = blocks_engine_figma_transformer_contract_find_visual_node($freeformTransitionResult, 'layout-transition:flow-a');
    $transitionAbsolute = blocks_engine_figma_transformer_contract_find_visual_node($freeformTransitionResult, 'layout-transition:absolute');
    $transitionFlowB = blocks_engine_figma_transformer_contract_find_visual_node($freeformTransitionResult, 'layout-transition:flow-b');
    $transitionLocalCard = blocks_engine_figma_transformer_contract_find_visual_node($freeformTransitionResult, 'layout-transition:local-card');
    $assert(str_contains($transitionCss, '.figma-node-layout-transition-flex-auto-layout-shell{width:360px;height:180px;position:relative;isolation:isolate;display:flex;flex-direction:row;gap:12px}'), 'visual-map-layout-transition-flex-css');
    $assert(str_contains($transitionCss, '.figma-node-layout-transition-freeform-freeform-board{width:360px;height:180px;position:relative}'), 'visual-map-layout-transition-freeform-css');
    blocks_engine_figma_transformer_contract_assert_node_rect($assert, $transitionFlowA, array('x' => 0.0, 'y' => 0.0, 'width' => 80.0, 'height' => 40.0), 'visual-map-layout-transition-flow-first-position');
    blocks_engine_figma_transformer_contract_assert_node_rect($assert, $transitionFlowB, array('x' => 92.0, 'y' => 0.0, 'width' => 60.0, 'height' => 40.0), 'visual-map-layout-transition-flow-skips-absolute-position');
    blocks_engine_figma_transformer_contract_assert_node_rect($assert, $transitionAbsolute, array('x' => 250.0, 'y' => 20.0, 'width' => 40.0, 'height' => 24.0), 'visual-map-layout-transition-absolute-position');
    blocks_engine_figma_transformer_contract_assert_node_rect($assert, $transitionLocalCard, array('x' => 44.0, 'y' => 66.0, 'width' => 90.0, 'height' => 30.0), 'visual-map-layout-transition-freeform-local-position');

    $explicitLayerOrderResult = blocks_engine_figma_transformer_contract_transform(array(
        'name'  => 'Explicit Kiwi Layer Order Fixture',
        'nodes' => array(
            array(
                'id'       => 'layer-order:frame',
                'type'     => 'FRAME',
                'name'     => 'Layer order frame',
                'width'    => 320,
                'height'   => 120,
                'children' => array(
                    array('id' => 'layer-order:front', 'type' => 'RECTANGLE', 'name' => 'Front layer', 'x' => 0, 'y' => 80, 'width' => 100, 'height' => 20, 'sortPosition' => 'b'),
                    array('id' => 'layer-order:back', 'type' => 'RECTANGLE', 'name' => 'Back layer', 'x' => 0, 'y' => 0, 'width' => 100, 'height' => 20, 'sortPosition' => 'a'),
                ),
            ),
        ),
    ));
    $explicitLayerOrderHtml = blocks_engine_figma_transformer_contract_file_content($explicitLayerOrderResult, 'index.html');
    $explicitLayerOrderBackPosition = strpos($explicitLayerOrderHtml, 'data-figma-node-id="layer-order:back"');
    $explicitLayerOrderFrontPosition = strpos($explicitLayerOrderHtml, 'data-figma-node-id="layer-order:front"');
    $assert(false !== $explicitLayerOrderBackPosition && false !== $explicitLayerOrderFrontPosition && $explicitLayerOrderBackPosition < $explicitLayerOrderFrontPosition, 'visual-map-explicit-sort-position-overrides-geometry-order');

    $nestedInstanceResult = blocks_engine_figma_transformer_contract_transform(
        array(
            'name'  => 'Nested Instance Transform Override Fixture',
            'nodes' => array(
                array(
                    'id'       => 'instance:canvas',
                    'type'     => 'CANVAS',
                    'name'     => 'Canvas',
                    'children' => array(
                        array(
                            'id'       => 'component:icon',
                            'type'     => 'COMPONENT',
                            'name'     => 'Icon component',
                            'width'    => 16,
                            'height'   => 16,
                            'children' => array(
                                array('id' => 'component:icon/vector', 'type' => 'VECTOR', 'name' => 'Icon vector', 'width' => 16, 'height' => 16, 'pathData' => 'M0 0H16V16Z'),
                            ),
                        ),
                        array(
                            'id'         => 'component:button',
                            'type'       => 'COMPONENT',
                            'name'       => 'Button component',
                            'width'      => 120,
                            'height'     => 44,
                            'layoutMode' => 'HORIZONTAL',
                            'itemSpacing' => 8,
                            'children'   => array(
                                array('id' => 'component:button/icon', 'type' => 'INSTANCE', 'name' => 'Nested icon', 'componentId' => 'component:icon', 'width' => 16, 'height' => 16),
                                array('id' => 'component:button/label', 'type' => 'TEXT', 'name' => 'Button label', 'characters' => 'Default label', 'width' => 80, 'height' => 20),
                            ),
                        ),
                        array(
                            'id'       => 'instance:page',
                            'type'     => 'FRAME',
                            'name'     => 'Page',
                            'width'    => 320,
                            'height'   => 180,
                            'children' => array(
                                array(
                                    'id'          => 'instance:button',
                                    'type'        => 'INSTANCE',
                                    'name'        => 'Buy button',
                                    'componentId' => 'component:button',
                                    'x'           => 30,
                                    'y'           => 40,
                                    'width'       => 160,
                                    'height'      => 60,
                                    'overrides'   => array(
                                        array(
                                            'nodeId'     => 'component:button/label',
                                            'characters' => 'Buy now',
                                            'size'       => array('x' => 90, 'y' => 22),
                                            'transform'  => array('m02' => 48, 'm12' => 18),
                                        ),
                                    ),
                                ),
                            ),
                        ),
                    ),
                ),
            ),
        ),
        array('frame_id' => 'instance:page')
    );
    $nestedInstanceHtml = blocks_engine_figma_transformer_contract_file_content($nestedInstanceResult, 'index.html');
    $nestedInstanceCss = blocks_engine_figma_transformer_contract_file_content($nestedInstanceResult, 'style.css');
    $nestedInstanceRoot = blocks_engine_figma_transformer_contract_find_visual_node($nestedInstanceResult, 'instance:button');
    $nestedInstanceLabel = blocks_engine_figma_transformer_contract_find_visual_node($nestedInstanceResult, 'instance:button/component:button/label');
    $nestedInstanceIconVector = blocks_engine_figma_transformer_contract_find_visual_node($nestedInstanceResult, 'instance:button/component:button/icon/component:icon/vector');
    $assert(str_contains($nestedInstanceHtml, 'Buy now'), 'visual-map-nested-instance-text-override-emits');
    $assert(! str_contains($nestedInstanceHtml, 'Default label'), 'visual-map-nested-instance-text-override-replaces-default');
    $assert(str_contains($nestedInstanceHtml, '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 16 16"'), 'visual-map-nested-instance-vector-emits');
    $assert(str_contains($nestedInstanceCss, '.figma-node-instance-button-buy-button{width:160px;height:60px;position:absolute;left:30px;top:40px}'), 'visual-map-nested-instance-transform-override-freeform-css');
    blocks_engine_figma_transformer_contract_assert_node_rect($assert, $nestedInstanceRoot, array('x' => 30.0, 'y' => 40.0, 'width' => 160.0, 'height' => 60.0), 'visual-map-nested-instance-root-position');
    blocks_engine_figma_transformer_contract_assert_node_rect($assert, $nestedInstanceLabel, array('x' => 78.0, 'y' => 58.0, 'width' => 90.0, 'height' => 22.0), 'visual-map-nested-instance-transform-override-position');
    $assert(null !== $nestedInstanceIconVector, 'visual-map-nested-instance-resolves-nested-instance-vector');

    $nestedVectorSourceResult = blocks_engine_figma_transformer_contract_transform(
        array(
            'name'  => 'Nested Vector Component Source Geometry Fixture',
            'nodes' => array(
                array(
                    'id'                  => 'source:icon',
                    'type'                => 'COMPONENT',
                    'name'                => 'Source icon',
                    'absoluteBoundingBox' => array('x' => 1000, 'y' => 500, 'width' => 40, 'height' => 40),
                    'children'            => array(
						array(
							'id'                  => 'source:icon/vector',
							'type'                => 'VECTOR',
							'name'                => 'Source vector',
							'x'                   => 1012,
							'y'                   => 508,
							'absoluteBoundingBox' => array('x' => 1012, 'y' => 508, 'width' => 16, 'height' => 16),
							'pathData'            => 'M0 0H16V16H0Z',
						),
						array(
							'id'                  => 'source:icon/union',
							'type'                => 'BOOLEAN_OPERATION',
							'name'                => 'Source union',
							'x'                   => 1004,
							'y'                   => 530,
							'absoluteBoundingBox' => array('x' => 1004, 'y' => 530, 'width' => 20, 'height' => 6),
							'children'            => array(
								array(
									'id'                  => 'source:icon/union/part',
									'type'                => 'VECTOR',
									'name'                => 'Union part',
									'x'                   => 1004,
									'y'                   => 530,
									'absoluteBoundingBox' => array('x' => 1004, 'y' => 530, 'width' => 20, 'height' => 6),
									'pathData'            => 'M0 0H20V6H0Z',
								),
                            ),
                        ),
                    ),
                ),
                array(
                    'id'                  => 'source:page',
                    'type'                => 'FRAME',
                    'name'                => 'Page',
                    'absoluteBoundingBox' => array('x' => 300, 'y' => 200, 'width' => 200, 'height' => 120),
                    'children'            => array(
                        array(
                            'id'          => 'instance:icon',
                            'type'        => 'INSTANCE',
                            'name'        => 'Placed icon',
                            'componentId' => 'source:icon',
                            'x'           => 30,
                            'y'           => 40,
                            'width'       => 40,
                            'height'      => 40,
                        ),
                    ),
                ),
            ),
        ),
        array('frame_id' => 'source:page')
    );
    $nestedVectorSourceHtml = blocks_engine_figma_transformer_contract_file_content($nestedVectorSourceResult, 'index.html');
    $nestedVectorSourceCss = blocks_engine_figma_transformer_contract_file_content($nestedVectorSourceResult, 'style.css');
    $nestedVectorSourceVector = blocks_engine_figma_transformer_contract_find_visual_node($nestedVectorSourceResult, 'instance:icon/source:icon/vector');
    $nestedVectorSourceUnion = blocks_engine_figma_transformer_contract_find_visual_node($nestedVectorSourceResult, 'instance:icon/source:icon/union');
    $assert(str_contains($nestedVectorSourceHtml, 'viewBox="0 0 16 16"'), 'visual-map-component-source-vector-viewbox-stays-zero-origin');
    $assert(str_contains($nestedVectorSourceCss, '.source-vector{width:16px;height:16px;position:absolute;left:12px;top:8px}'), 'visual-map-component-source-vector-css-parent-local');
    $assert(str_contains($nestedVectorSourceCss, '.source-union{width:20px;height:6px;position:absolute;left:4px;top:30px}'), 'visual-map-component-source-boolean-css-parent-local');
    blocks_engine_figma_transformer_contract_assert_node_rect($assert, $nestedVectorSourceVector, array('x' => 42.0, 'y' => 48.0, 'width' => 16.0, 'height' => 16.0), 'visual-map-component-source-vector-rect-parent-local');
    blocks_engine_figma_transformer_contract_assert_node_rect($assert, $nestedVectorSourceUnion, array('x' => 34.0, 'y' => 70.0, 'width' => 20.0, 'height' => 6.0), 'visual-map-component-source-boolean-rect-parent-local');

    $staleCanvasTransformResult = blocks_engine_figma_transformer_contract_transform(
        array(
            'name'  => 'Component Clone Stale Canvas Transform Fixture',
            'nodes' => array(
                array(
                    'id'       => 'source:card',
                    'type'     => 'COMPONENT',
                    'name'     => 'Post card',
                    'width'    => 376,
                    'height'   => 477,
                    'children' => array(
                        array(
                            'id'     => 'source:card/image',
                            'type'   => 'RECTANGLE',
                            'name'   => 'Image',
                            'x'      => 0,
                            'y'      => 0,
                            'width'  => 376,
                            'height' => 282,
                        ),
                        array(
                            'id'     => 'source:card/content',
                            'type'   => 'FRAME',
                            'name'   => 'Content',
                            'x'      => 0,
                            'y'      => 314,
                            'width'  => 376,
                            'height' => 163,
                        ),
                    ),
                ),
                array(
                    'id'       => 'source:page',
                    'type'     => 'FRAME',
                    'name'     => 'Page',
                    'width'    => 600,
                    'height'   => 800,
                    'children' => array(
                        array(
                            'id'                => 'instance:card',
                            'type'              => 'INSTANCE',
                            'name'              => 'Placed card',
                            'componentId'       => 'source:card',
                            'x'                 => 112,
                            'y'                 => 198,
                            'width'             => 376,
                            'height'            => 477,
                            'derivedSymbolData' => array(
                                array(
                                    'nodeId'    => 'source:card/image',
                                    'transform' => array('m02' => 128, 'm12' => 678),
                                ),
                            ),
                        ),
                    ),
                ),
            ),
        ),
        array('frame_id' => 'source:page')
    );
    $staleCanvasImageCss = blocks_engine_figma_transformer_contract_file_content($staleCanvasTransformResult, 'style.css');
    $staleCanvasImage = blocks_engine_figma_transformer_contract_find_visual_node($staleCanvasTransformResult, 'instance:card/source:card/image');
    $assert(str_contains($staleCanvasImageCss, '.image{width:376px;height:282px;position:absolute;left:0px;top:0px}'), 'visual-map-component-source-stale-canvas-transform-css-parent-local');
    blocks_engine_figma_transformer_contract_assert_node_rect($assert, $staleCanvasImage, array('x' => 112.0, 'y' => 198.0, 'width' => 376.0, 'height' => 282.0), 'visual-map-component-source-stale-canvas-transform-rect-parent-local');

    $staleNestedInstanceGeometryResult = blocks_engine_figma_transformer_contract_transform(
        array(
            'name'  => 'Nested Instance Stale Definition Geometry Fixture',
            'nodes' => array(
                array(
                    'id'       => 'source:image-component',
                    'type'     => 'COMPONENT',
                    'name'     => 'Aspect Ratio=4:3',
                    'x'        => 16,
                    'y'        => 480,
                    'width'    => 240,
                    'height'   => 180,
                    'children' => array(
                        array(
                            'id'     => 'source:image-component/fill',
                            'type'   => 'RECTANGLE',
                            'name'   => 'Fill',
                            'x'      => 0,
                            'y'      => 0,
                            'width'  => 240,
                            'height' => 180,
                        ),
                    ),
                ),
                array(
                    'id'       => 'source:nested-card',
                    'type'     => 'COMPONENT',
                    'name'     => 'Post card',
                    'width'    => 376,
                    'height'   => 477,
                    'children' => array(
                        array(
                            'id'          => 'source:nested-card/image',
                            'type'        => 'INSTANCE',
                            'name'        => 'Image',
                            'componentId' => 'source:image-component',
                            'x'           => 0,
                            'y'           => 0,
                            'width'       => 376,
                            'height'      => 282,
                        ),
                        array(
                            'id'     => 'source:nested-card/content',
                            'type'   => 'FRAME',
                            'name'   => 'Content',
                            'x'      => 0,
                            'y'      => 314,
                            'width'  => 376,
                            'height' => 163,
                        ),
                    ),
                ),
                array(
                    'id'       => 'source:nested-page',
                    'type'     => 'FRAME',
                    'name'     => 'Page',
                    'width'    => 600,
                    'height'   => 800,
                    'children' => array(
                        array(
                            'id'                => 'instance:nested-card',
                            'type'              => 'INSTANCE',
                            'name'              => 'Placed card',
                            'componentId'       => 'source:nested-card',
                            'x'                 => 112,
                            'y'                 => 198,
                            'width'             => 376,
                            'height'            => 477,
                            'derivedSymbolData' => array(
                                array(
                                    'nodeId'     => 'source:nested-card/image',
                                    'size'       => array('x' => 376, 'y' => 282),
                                    'fillPaints' => array(
                                        array(
                                            'type'    => 'SOLID',
                                            'color'   => array('r' => 1, 'g' => 0, 'b' => 0),
                                            'opacity' => 1,
                                        ),
                                    ),
                                ),
                            ),
                        ),
                    ),
                ),
            ),
        ),
        array('frame_id' => 'source:nested-page')
    );
    $staleNestedInstanceGeometryCss = blocks_engine_figma_transformer_contract_file_content($staleNestedInstanceGeometryResult, 'style.css');
    $staleNestedInstanceImage = blocks_engine_figma_transformer_contract_find_visual_node($staleNestedInstanceGeometryResult, 'instance:nested-card/source:nested-card/image');
    $assert(str_contains($staleNestedInstanceGeometryCss, 'width:376px;height:282px;position:absolute;left:0px;top:0px'), 'visual-map-nested-instance-stale-definition-transform-css-parent-local');
    blocks_engine_figma_transformer_contract_assert_node_rect($assert, $staleNestedInstanceImage, array('x' => 112.0, 'y' => 198.0, 'width' => 376.0, 'height' => 282.0), 'visual-map-nested-instance-stale-definition-transform-rect-parent-local');

    $reusedEmitter = new \Automattic\BlocksEngine\FigmaTransformer\Html\StaticHtmlEmitter();
    $reusedEmitter->emit(array(
        'name' => 'Prior emission',
        'nodes' => array(array('id' => 'reuse:prior', 'type' => 'FRAME', 'name' => 'Prior root', 'width' => 320, 'height' => 160)),
    ));
    $reusedSite = $reusedEmitter->emitSite(array(
        'name' => 'Reused emitter site',
        'nodes' => array(
            array('id' => 'reuse:home', 'type' => 'FRAME', 'name' => 'Home', 'width' => 320, 'height' => 160, 'children' => array(array('id' => 'reuse:home-title', 'type' => 'TEXT', 'name' => 'Home title', 'text' => 'Home'))),
            array('id' => 'reuse:about', 'type' => 'FRAME', 'name' => 'About', 'width' => 320, 'height' => 160, 'children' => array(array('id' => 'reuse:about-title', 'type' => 'TEXT', 'name' => 'About title', 'text' => 'About'))),
        ),
    ), array(
        'pages' => array(
            array('frame_id' => 'reuse:home', 'name' => 'Home', 'path' => 'index.html', 'entrypoint' => true),
            array('frame_id' => 'reuse:about', 'name' => 'About', 'path' => 'about.html'),
        ),
    ));
    $reusedCss = blocks_engine_figma_transformer_contract_file_content($reusedSite, 'style.css');
    $reusedHomeHtml = blocks_engine_figma_transformer_contract_file_content($reusedSite, 'index.html');
    $reusedAboutHtml = blocks_engine_figma_transformer_contract_file_content($reusedSite, 'about.html');
    $assert(! str_contains($reusedCss, 'figma-node-reuse-prior-prior-root'), 'visual-map-emitter-reuse-clears-prior-emission-css');
    $assert(str_contains($reusedHomeHtml, 'data-page-path="index.html"') && str_contains($reusedAboutHtml, 'data-page-path="about.html"'), 'visual-map-emitter-reuse-resets-page-local-state');
    $assert(null === blocks_engine_figma_transformer_contract_find_visual_node_in_map($reusedSite['source_report']['visual_node_map'] ?? array(), 'reuse:prior'), 'visual-map-emitter-reuse-clears-prior-report-state');

    $reusedSinglePage = $reusedEmitter->emit(array(
        'name' => 'Reused single page',
        'nodes' => array(array('id' => 'reuse:single', 'type' => 'FRAME', 'name' => 'Single page', 'width' => 320, 'height' => 160)),
    ), array('static_site_page_path' => 'single.html'));
    $reusedSingleCss = blocks_engine_figma_transformer_contract_file_content($reusedSinglePage, 'style.css');
    $reusedSingleHtml = blocks_engine_figma_transformer_contract_file_content($reusedSinglePage, 'index.html');
    $assert(! str_contains($reusedSingleCss, 'figma-node-reuse-home-home') && ! str_contains($reusedSingleCss, 'figma-node-reuse-about-about'), 'visual-map-emitter-reuse-clears-prior-site-css');
    $assert(str_contains($reusedSingleHtml, 'data-page-path="single.html"') && null === blocks_engine_figma_transformer_contract_find_visual_node_in_map($reusedSinglePage['source_report']['visual_node_map'] ?? array(), 'reuse:home'), 'visual-map-emitter-reuse-clears-prior-site-page-state');

    $inlineSite = (new \Automattic\BlocksEngine\FigmaTransformer\Html\StaticHtmlEmitter())->emitSite(array(
        'name' => 'Inline diagnostics site',
        'nodes' => array(array('id' => 'inline:home', 'type' => 'FRAME', 'name' => 'Inline home', 'width' => 320, 'height' => 160)),
    ), array(
        'pages' => array(array('frame_id' => 'inline:home', 'name' => 'Inline home', 'path' => 'index.html', 'entrypoint' => true)),
    ), array('inline_css' => true));
    $inlineSiteHtml = blocks_engine_figma_transformer_contract_file_content($inlineSite, 'index.html');
    $inlineSiteDiagnostics = $inlineSite['source_report']['transform_diagnostics']['html_artifact'] ?? array();
    $assert(str_contains($inlineSiteHtml, '<style data-figma-transformer-css="true">'), 'visual-map-site-inline-css-injected-before-diagnostics');
    $assert(strlen("\n" . $inlineSiteHtml) === ($inlineSiteDiagnostics['html_bytes'] ?? null), 'visual-map-site-inline-css-diagnostics-use-final-html');
}
