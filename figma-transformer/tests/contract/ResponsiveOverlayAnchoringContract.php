<?php

declare(strict_types=1);

/**
 * @param callable(bool, string): void $assert
 */
function blocks_engine_figma_transformer_run_responsive_overlay_anchoring_contract(callable $assert): void
{
    $imageHash = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
    $scenegraph = array(
        'name'   => 'Responsive overlay fixture',
        'assets' => array(
            $imageHash => array(
                'name'      => 'Map Image',
                'mime_type' => 'image/png',
                'content'   => 'map image bytes',
            ),
        ),
        'nodes'  => array(
            array(
                'id'         => 'overlay:root',
                'type'       => 'FRAME',
                'name'       => 'Overlay page',
                'width'      => 1200,
                'height'     => 800,
                'layoutMode' => 'VERTICAL',
                'children'   => array(
                    array(
                        'id'       => 'overlay:map',
                        'type'     => 'FRAME',
                        'name'     => 'Clinic map',
                        'width'    => 800,
                        'height'   => 400,
                        'children' => array(
                            array(
                                'id'                => 'overlay:map-image',
                                'type'              => 'RECTANGLE',
                                'name'              => 'Map image',
                                'width'             => 800,
                                'height'            => 400,
                                'layoutPositioning' => 'ABSOLUTE',
                                'fillPaints'        => array(
                                    array(
                                        'type'  => 'IMAGE',
                                        'image' => array('hash' => hex2bin($imageHash)),
                                    ),
                                ),
                            ),
                            array(
                                'id'                => 'overlay:label',
                                'type'              => 'FRAME',
                                'name'              => 'Location label',
                                'x'                 => 200,
                                'y'                 => 100,
                                'width'             => 160,
                                'height'            => 40,
                                'layoutPositioning' => 'ABSOLUTE',
                                'fill'              => array('r' => 0.2, 'g' => 0.2, 'b' => 0.2),
                            ),
                            array(
                                'id'                => 'overlay:pin',
                                'type'              => 'ELLIPSE',
                                'name'              => 'Location pin',
                                'x'                 => 400,
                                'y'                 => 120,
                                'width'             => 24,
                                'height'            => 24,
                                'layoutPositioning' => 'ABSOLUTE',
                                'fill'              => array('r' => 1, 'g' => 0, 'b' => 0),
                            ),
                            array(
                                'id'                => 'overlay:photo',
                                'type'              => 'RECTANGLE',
                                'name'              => 'Large photo replacement',
                                'x'                 => 40,
                                'y'                 => 20,
                                'width'             => 700,
                                'height'            => 340,
                                'layoutPositioning' => 'ABSOLUTE',
                                'fillPaints'        => array(
                                    array(
                                        'type'  => 'IMAGE',
                                        'image' => array('hash' => hex2bin($imageHash)),
                                    ),
                                ),
                            ),
                        ),
                    ),
                ),
            ),
        ),
    );

    $result = blocks_engine_figma_transformer_contract_transform($scenegraph);
    $css = blocks_engine_figma_transformer_contract_file_content($result, 'style.css');

    blocks_engine_figma_transformer_contract_assert_css_rule_contains($assert, $css, '.figma-node-overlay-label-location-label', array('position:absolute', 'left:25%', 'top:25%'), 'responsive-overlay-label-uses-percent-offsets');
    blocks_engine_figma_transformer_contract_assert_css_rule_contains($assert, $css, '.figma-node-overlay-pin-location-pin', array('position:absolute', 'left:50%', 'top:30%'), 'responsive-overlay-pin-uses-percent-offsets');
    blocks_engine_figma_transformer_contract_assert_css_rule_contains($assert, $css, '.figma-node-overlay-photo-large-photo-replacement', array('position:absolute', 'left:40px', 'top:20px'), 'large-image-child-keeps-pixel-offsets');
}
