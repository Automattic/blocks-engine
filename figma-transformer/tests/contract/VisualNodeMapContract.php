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
    $assert(str_contains($reverseZIndexCss, '.figma-node-reverse-z-row-overlapping-reverse-z-row{width:180px;height:80px;isolation:isolate;display:flex;flex-direction:row;gap:0px}'), 'visual-map-reverse-z-parent-gap-clamped');
    $assert(str_contains($reverseZIndexCss, '.figma-node-reverse-z-first-first-top-child{width:80px;height:80px;z-index:2;flex-shrink:0}'), 'visual-map-reverse-z-first-child-on-top');
    $assert(str_contains($reverseZIndexCss, '.figma-node-reverse-z-second-second-lower-child{width:80px;height:80px;z-index:1;flex-shrink:0}'), 'visual-map-reverse-z-second-child-lower');

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
    $assert(str_contains($isolatedStackCss, '.figma-node-isolated-stack-section-layered-image-section{width:320px;height:180px;isolation:isolate;position:absolute;left:0px;top:0px;display:flex;flex-direction:row;gap:0px}'), 'visual-map-local-stack-clamps-negative-css-gap');

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
    $assert(str_contains($invalidGapCss, '.figma-node-invalid-gap-wrap-wrapping-row-with-invalid-counter-gap{width:300px;height:120px;display:flex;flex-direction:row;flex-wrap:wrap;align-content:flex-start;gap:0px}'), 'visual-map-gap-css-falls-back-to-clamped-main-gap');

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
    blocks_engine_figma_transformer_contract_assert_css_rule_contains($assert, $mixedLayerStackCss, '.figma-node-mixed-layer-image-featured-image-background', array('position:absolute', 'left:0px', 'top:0px', 'z-index:0', 'pointer-events:none', 'background:#ffd900'), 'visual-map-mixed-layer-image-behind');
    blocks_engine_figma_transformer_contract_assert_css_rule_contains($assert, $mixedLayerStackCss, '.figma-node-mixed-layer-title-headline-over-image', array('position:relative', 'z-index:1', 'font-size:32px', 'font-weight:700'), 'visual-map-mixed-layer-title-above');
    $assert(1 === ($mixedLayerStackingOrder['mixed_positioning_parent_count'] ?? null), 'visual-map-mixed-layer-diagnostics-mixed-position-parent-count');
    $assert(1 === ($mixedLayerStackingOrder['absolute_child_count'] ?? null), 'visual-map-mixed-layer-diagnostics-absolute-child-count');
    $assert(1 === ($mixedLayerStackingOrder['flow_child_count'] ?? null), 'visual-map-mixed-layer-diagnostics-flow-child-count');
    $assert('mixed-layer:section' === ($mixedLayerStackingOrder['sample_nodes'][0]['node_id'] ?? null), 'visual-map-mixed-layer-diagnostics-sample-node');
    $assert(1 === ($mixedLayerArtifactSummary['mixed_positioning_parent_count'] ?? null), 'visual-map-mixed-layer-artifact-summary-mixed-position-parent-count');

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
    $assert(str_contains($componentCloneZIndexCss, '.back-panel{width:160px;height:120px;z-index:2;flex-shrink:0}'), 'visual-map-component-clone-preserves-back-z-index');
    $assert(str_contains($componentCloneZIndexCss, '.front-panel{width:160px;height:120px;z-index:1;flex-shrink:0}'), 'visual-map-component-clone-preserves-front-z-index');

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
}
