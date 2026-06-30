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
    $assert(str_contains($transitionCss, '.figma-node-layout-transition-flex-auto-layout-shell{width:360px;height:180px;position:relative;display:flex;flex-direction:row;gap:12px}'), 'visual-map-layout-transition-flex-css');
    $assert(str_contains($transitionCss, '.figma-node-layout-transition-freeform-freeform-board{width:360px;height:180px;position:relative}'), 'visual-map-layout-transition-freeform-css');
    blocks_engine_figma_transformer_contract_assert_node_rect($assert, $transitionFlowA, array('x' => 0.0, 'y' => 0.0, 'width' => 80.0, 'height' => 40.0), 'visual-map-layout-transition-flow-first-position');
    blocks_engine_figma_transformer_contract_assert_node_rect($assert, $transitionFlowB, array('x' => 92.0, 'y' => 0.0, 'width' => 60.0, 'height' => 40.0), 'visual-map-layout-transition-flow-skips-absolute-position');
    blocks_engine_figma_transformer_contract_assert_node_rect($assert, $transitionAbsolute, array('x' => 250.0, 'y' => 20.0, 'width' => 40.0, 'height' => 24.0), 'visual-map-layout-transition-absolute-position');
    blocks_engine_figma_transformer_contract_assert_node_rect($assert, $transitionLocalCard, array('x' => 44.0, 'y' => 66.0, 'width' => 90.0, 'height' => 30.0), 'visual-map-layout-transition-freeform-local-position');

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
}
