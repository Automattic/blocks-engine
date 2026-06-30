<?php

declare(strict_types=1);

use Automattic\BlocksEngine\FigmaTransformer\Scenegraph\GeometryBox;
use Automattic\BlocksEngine\FigmaTransformer\Scenegraph\ScenegraphNormalizer;

/**
 * @param callable(bool, string): void $assert
 */
function blocks_engine_figma_transformer_run_geometry_box_contract(callable $assert): void
{
    $normalizer = new ScenegraphNormalizer();

    $absoluteResult = $normalizer->normalize(array(
        'name'  => 'Absolute Bounds Geometry Fixture',
        'nodes' => array(
            array(
                'id'                  => 'geometry:absolute',
                'type'                => 'FRAME',
                'name'                => 'Absolute bounds',
                'absoluteBoundingBox' => array('x' => 100, 'y' => 50, 'width' => 320, 'height' => 200),
            ),
        ),
    ));
    $absoluteBox = $absoluteResult['nodes'][0]['box'] ?? array();
    $assert(GeometryBox::CLASSIFICATION_CANVAS_ABSOLUTE === GeometryBox::classifyNormalizedBox($absoluteBox), 'geometry-box-absolute-bounds-coordinate-space');
    $assert(array('x' => 100.0, 'y' => 50.0, 'width' => 320.0, 'height' => 200.0, 'coordinate_space' => GeometryBox::COORDINATE_SPACE_CANVAS_ABSOLUTE) === $absoluteBox, 'geometry-box-absolute-bounds-values');

    $rawPositionResult = $normalizer->normalize(array(
        'name'  => 'Raw Position Geometry Fixture',
        'nodes' => array(
            array(
                'id'     => 'geometry:raw',
                'type'   => 'FRAME',
                'name'   => 'Raw position',
                'x'      => 12,
                'y'      => 34,
                'width'  => 120,
                'height' => 80,
            ),
        ),
    ));
    $rawPositionBox = $rawPositionResult['nodes'][0]['box'] ?? array();
    $assert(GeometryBox::CLASSIFICATION_PARENT_LOCAL === GeometryBox::classifyNormalizedBox($rawPositionBox), 'geometry-box-raw-xy-coordinate-space');
    $assert(array('x' => 12.0, 'y' => 34.0, 'width' => 120.0, 'height' => 80.0, 'coordinate_space' => GeometryBox::COORDINATE_SPACE_PARENT_LOCAL) === $rawPositionBox, 'geometry-box-raw-xy-values');

    $transformResult = $normalizer->normalize(array(
        'name'  => 'Transform Geometry Fixture',
        'nodes' => array(
            array(
                'id'        => 'geometry:transform',
                'type'      => 'FRAME',
                'name'      => 'Transform position',
                'size'      => array('x' => 90, 'y' => 60),
                'transform' => array('m02' => 7, 'm12' => 11),
            ),
        ),
    ));
    $transformBox = $transformResult['nodes'][0]['box'] ?? array();
    $assert(GeometryBox::CLASSIFICATION_PARENT_LOCAL === GeometryBox::classifyNormalizedBox($transformBox), 'geometry-box-transform-coordinate-space');
    $assert(array('width' => 90.0, 'height' => 60.0, 'x' => 7.0, 'y' => 11.0, 'coordinate_space' => GeometryBox::COORDINATE_SPACE_PARENT_LOCAL) === $transformBox, 'geometry-box-transform-values');

    $selectedFrameResult = $normalizer->normalize(
        array(
            'name'  => 'Selected Frame Rebase Geometry Fixture',
            'nodes' => array(
                array(
                    'id'                  => 'geometry:page',
                    'type'                => 'FRAME',
                    'name'                => 'Page',
                    'absoluteBoundingBox' => array('x' => 1000, 'y' => 500, 'width' => 800, 'height' => 600),
                    'children'            => array(
                        array(
                            'id'                  => 'geometry:selected',
                            'type'                => 'FRAME',
                            'name'                => 'Selected frame',
                            'absoluteBoundingBox' => array('x' => 1200, 'y' => 700, 'width' => 400, 'height' => 300),
                            'children'            => array(
                                array(
                                    'id'                  => 'geometry:selected-child',
                                    'type'                => 'TEXT',
                                    'name'                => 'Selected child',
                                    'characters'          => 'Child',
                                    'absoluteBoundingBox' => array('x' => 1250, 'y' => 740, 'width' => 100, 'height' => 30),
                                ),
                            ),
                        ),
                    ),
                ),
            ),
        ),
        array('frame_id' => 'geometry:selected')
    );
    $selectedFrameBox = $selectedFrameResult['nodes'][0]['box'] ?? array();
    $selectedChildBox = $selectedFrameResult['nodes'][0]['children'][0]['box'] ?? array();
    $assert(GeometryBox::CLASSIFICATION_PAGE_LOCAL === GeometryBox::classifyNormalizedBox($selectedFrameBox, true), 'geometry-box-selected-frame-page-local-coordinate-space');
    $assert(0.0 === ($selectedFrameBox['x'] ?? null) && 0.0 === ($selectedFrameBox['y'] ?? null), 'geometry-box-selected-frame-rebased-root-origin');
    $assert(GeometryBox::CLASSIFICATION_PARENT_LOCAL === GeometryBox::classifyNormalizedBox($selectedChildBox), 'geometry-box-selected-frame-child-local-coordinate-space');
    $assert(50.0 === ($selectedChildBox['x'] ?? null) && 40.0 === ($selectedChildBox['y'] ?? null), 'geometry-box-selected-frame-child-rebased-position');
}
