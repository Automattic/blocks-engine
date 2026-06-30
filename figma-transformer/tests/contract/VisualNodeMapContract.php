<?php

declare(strict_types=1);

/**
 * @param callable(bool, string): void $assert
 * @param callable(array<string, mixed>, string): ?array<string, mixed> $findVisualNode
 * @param callable(array<string, mixed>, string): string $fileContent
 */
function blocks_engine_figma_transformer_run_visual_node_map_contract(callable $assert, callable $findVisualNode, callable $fileContent): void
{
    $visualFlexAlignmentResult = blocks_engine_figma_transformer_transform_scenegraph(array(
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
    $visualFlexFirst = $findVisualNode($visualFlexAlignmentResult, 'visual-flex:first');
    $visualFlexSecond = $findVisualNode($visualFlexAlignmentResult, 'visual-flex:second');
    $visualFlexCentered = $findVisualNode($visualFlexAlignmentResult, 'visual-flex:centered');
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
    $visualFlexCrossOverflowWide = null;
    foreach ( $visualFlexCrossOverflowMap as $visualNode ) {
        if ( is_array($visualNode) && 'visual-cross:wide' === ($visualNode['id'] ?? null) ) {
            $visualFlexCrossOverflowWide = $visualNode;
            break;
        }
    }
    $assert(-40.0 === ($visualFlexCrossOverflowWide['rect']['x'] ?? null), 'visual-map-column-overflow-center-child-x');

    $visualFlexOverflowResult = blocks_engine_figma_transformer_transform_scenegraph(array(
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
    $visualOverflowEndFirst = $findVisualNode($visualFlexOverflowResult, 'visual-overflow:end-first');
    $visualOverflowEndSecond = $findVisualNode($visualFlexOverflowResult, 'visual-overflow:end-second');
    $visualOverflowCenterFirst = $findVisualNode($visualFlexOverflowResult, 'visual-overflow:center-first');
    $visualOverflowCenterSecond = $findVisualNode($visualFlexOverflowResult, 'visual-overflow:center-second');
    $assert(-28.0 === ($visualOverflowEndFirst['rect']['x'] ?? null), 'visual-map-overflow-flex-end-first-x');
    $assert(30.0 === ($visualOverflowEndSecond['rect']['x'] ?? null), 'visual-map-overflow-flex-end-second-x');
    $assert(-14.0 === ($visualOverflowCenterFirst['rect']['x'] ?? null), 'visual-map-overflow-center-first-x');
    $assert(44.0 === ($visualOverflowCenterSecond['rect']['x'] ?? null), 'visual-map-overflow-center-second-x');

    $visualFlexWrapResult = blocks_engine_figma_transformer_transform_scenegraph(array(
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
    $visualFlexWrapCss = $fileContent($visualFlexWrapResult, 'style.css');
    $visualFlexWrapFirst = $findVisualNode($visualFlexWrapResult, 'visual-wrap:first');
    $visualFlexWrapSecond = $findVisualNode($visualFlexWrapResult, 'visual-wrap:second');
    $visualFlexWrapThird = $findVisualNode($visualFlexWrapResult, 'visual-wrap:third');
    $assert(str_contains($visualFlexWrapCss, 'flex-wrap:wrap;align-content:flex-start'), 'visual-map-flex-wrap-align-content-packed');
    $assert(array('x' => 0.0, 'y' => 0.0, 'width' => 100.0, 'height' => 40.0) === ($visualFlexWrapFirst['rect'] ?? null), 'visual-map-flex-wrap-first-line-first-card');
    $assert(array('x' => 110.0, 'y' => 0.0, 'width' => 100.0, 'height' => 60.0) === ($visualFlexWrapSecond['rect'] ?? null), 'visual-map-flex-wrap-first-line-second-card');
    $assert(array('x' => 0.0, 'y' => 70.0, 'width' => 100.0, 'height' => 30.0) === ($visualFlexWrapThird['rect'] ?? null), 'visual-map-flex-wrap-second-line-card');

    $visualCrossAxisFillResult = blocks_engine_figma_transformer_transform_scenegraph(array(
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
    $visualCrossAxisFillCss = $fileContent($visualCrossAxisFillResult, 'style.css');
    $visualCrossAxisFillTall = $findVisualNode($visualCrossAxisFillResult, 'visual-cross-fill:tall');
    $visualCrossAxisFillNext = $findVisualNode($visualCrossAxisFillResult, 'visual-cross-fill:next');
    $assert(str_contains($visualCrossAxisFillCss, '.figma-node-visual-cross-fill-tall-tall-fill-child{width:50px;height:100%;flex-shrink:0}'), 'visual-map-cross-axis-fill-does-not-grow-main-axis-css');
    $assert(! str_contains($visualCrossAxisFillCss, '.figma-node-visual-cross-fill-tall-tall-fill-child{width:50px;height:100%;flex-grow:1'), 'visual-map-cross-axis-fill-no-flex-grow-css');
    $assert(array('x' => 100.0, 'y' => 0.0, 'width' => 50.0, 'height' => 100.0) === ($visualCrossAxisFillTall['rect'] ?? null), 'visual-map-cross-axis-fill-source-width-preserved');
    $assert(array('x' => 0.0, 'y' => 0.0, 'width' => 80.0, 'height' => 40.0) === ($visualCrossAxisFillNext['rect'] ?? null), 'visual-map-cross-axis-fill-next-child-not-pushed');
}
