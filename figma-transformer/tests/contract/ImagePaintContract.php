<?php

declare(strict_types=1);

/**
 * @param callable(bool, string): void $assert
 * @param callable(array, string): string $fileContent
 */
function blocks_engine_figma_transformer_run_image_paint_contract(callable $assert, array $result, string $css, callable $fileContent): void
{
    $assert(2 === ($result['metrics']['asset_count'] ?? null), 'asset-count');
    $assert(str_contains($css, '.figma-node-1-4-hero-image-rectangle{width:320px;height:180px;position:absolute;left:10px;top:20px;background:#ff0000;background-image:url("assets/hero-image.svg")'), 'css-rectangle-asset-style');
    $assert(str_contains($css, '.figma-node-1-5-nested-image-paint{') && str_contains($css, 'background-image:url("assets/fixture-photo.jpg")'), 'css-nested-image-hash-asset-style');
    $assert('fixture image bytes' === $fileContent($result, 'assets/fixture-photo.jpg'), 'asset-content-preserved');

    $imageUnderlayGuardResult = blocks_engine_figma_transformer_transform_scenegraph(array(
        'name'   => 'Image Underlay Guard Fixture',
        'assets' => array(
            'guard-image' => array(
                'name'      => 'Guard Image',
                'mime_type' => 'image/svg+xml',
                'content'   => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 1 1"></svg>',
            ),
        ),
        'nodes'  => array(
            array(
                'id'         => 'imageguard:parent',
                'type'       => 'FRAME',
                'name'       => 'Image Parent',
                'width'      => 1000,
                'height'     => 600,
                'layoutMode' => 'HORIZONTAL',
                'children'   => array(
                    array(
                        'id'       => 'imageguard:photo',
                        'type'     => 'RECTANGLE',
                        'name'     => 'Large Photo',
                        'width'    => 900,
                        'height'   => 520,
                        'asset_id' => 'guard-image',
                    ),
                    array('id' => 'imageguard:title', 'type' => 'TEXT', 'name' => 'Hero title', 'text' => 'Photo should stay in flow'),
                ),
            ),
        ),
    ));
    $imageUnderlayGuardCss = $fileContent($imageUnderlayGuardResult, 'style.css');
    $imageUnderlayGuardUnderlays = $imageUnderlayGuardResult['source_reports']['figma']['html']['transform_diagnostics']['layout']['decorative_underlays'] ?? array();
    $assert(str_contains($imageUnderlayGuardCss, '.figma-node-imageguard-photo-large-photo{width:900px;height:520px;background-image:url("assets/guard-image.svg");background-size:cover;background-position:center;flex-shrink:0}'), 'image-backed-child-remains-flex-child');
    $assert(0 === ($imageUnderlayGuardUnderlays['count'] ?? null), 'image-backed-child-not-decorative-underlay-diagnostic');

    $unusedAssetResult = blocks_engine_figma_transformer_transform_scenegraph(array(
        'name'   => 'Unused Asset Fixture',
        'assets' => array(
            'used-image' => array('mime_type' => 'image/png', 'content' => 'used image'),
            'unused-image' => array('mime_type' => 'image/png', 'content' => 'unused image'),
        ),
        'nodes'  => array(
            array(
                'id'         => 'asset:used',
                'type'       => 'RECTANGLE',
                'name'       => 'Used image',
                'width'      => 10,
                'height'     => 10,
                'fillPaints' => array(array('type' => 'IMAGE', 'imageRef' => 'used-image')),
            ),
        ),
    ));
    $unusedAssetPaths = array_map(static fn (array $asset): string => (string) ($asset['path'] ?? ''), $unusedAssetResult['assets'] ?? array());
    $assert(1 === ($unusedAssetResult['metrics']['asset_count'] ?? null), 'unused-asset-filtered-count');
    $assert(in_array('assets/used-image.png', $unusedAssetPaths, true), 'unused-asset-keeps-referenced');
    $assert(! in_array('assets/unused-image.png', $unusedAssetPaths, true), 'unused-asset-omits-unreferenced');

    $backgroundPaintsResult = blocks_engine_figma_transformer_transform_scenegraph(array(
        'name'  => 'Background Paints Fixture',
        'nodes' => array(
            array(
                'id'               => 'background:paints',
                'type'             => 'FRAME',
                'name'             => 'Background Paints',
                'width'            => 10,
                'height'           => 10,
                'backgroundPaints' => array(array('type' => 'SOLID', 'color' => array('r' => 0, 'g' => 1, 'b' => 0, 'a' => 1))),
            ),
        ),
    ));
    $backgroundPaintsCss = $fileContent($backgroundPaintsResult, 'style.css');
    $assert(str_contains($backgroundPaintsCss, '.figma-node-background-paints-background-paints{width:10px;height:10px;background:#00ff00}'), 'background-paints-emits-background');

    $imageScaleResult = blocks_engine_figma_transformer_transform_scenegraph(array(
        'name'   => 'Image Scale Fixture',
        'assets' => array(
            'fill-image'    => array('mime_type' => 'image/png', 'content' => 'fill image'),
            'stretch-image' => array('mime_type' => 'image/png', 'content' => 'stretch image'),
        ),
        'nodes'  => array(
            array(
                'id'         => 'scale:fill',
                'type'       => 'RECTANGLE',
                'name'       => 'Fill image',
                'width'      => 100,
                'height'     => 80,
                'fillPaints' => array(
                    array('type' => 'IMAGE', 'imageRef' => 'fill-image', 'imageScaleMode' => 'FILL', 'imageShouldColorManage' => true, 'originalImageWidth' => 200, 'originalImageHeight' => 100),
                ),
            ),
            array(
                'id'         => 'scale:stretch',
                'type'       => 'RECTANGLE',
                'name'       => 'Stretch image',
                'width'      => 100,
                'height'     => 80,
                'fillPaints' => array(
                    array('type' => 'IMAGE', 'imageRef' => 'stretch-image', 'imageScaleMode' => 'STRETCH'),
                ),
            ),
        ),
    ));
    $imageScaleCss = $fileContent($imageScaleResult, 'style.css');
    $assert(str_contains($imageScaleCss, '.figma-node-scale-fill-fill-image{width:100px;height:80px;background-image:url("assets/fill-image.png");background-size:cover;background-position:center}'), 'image-fill-emits-cover-background');
    $assert(str_contains($imageScaleCss, '.figma-node-scale-stretch-stretch-image{width:100px;height:80px;background-image:url("assets/stretch-image.png");background-size:100% 100%;background-repeat:no-repeat;background-position:center}'), 'image-stretch-emits-stretch-background');
    $imageScaleVisualNodes = $imageScaleResult['source_reports']['figma']['html']['visual_node_map'] ?? array();
    $imageScaleFillVisualNode = null;
    foreach ( is_array($imageScaleVisualNodes) ? $imageScaleVisualNodes : array() as $visualNode ) {
        if ( is_array($visualNode) && 'scale:fill' === ($visualNode['id'] ?? null) ) {
            $imageScaleFillVisualNode = $visualNode;
            break;
        }
    }
    $assert('FILL' === ($imageScaleFillVisualNode['image']['scale_mode'] ?? null), 'visual-node-image-scale-mode');
    $assert(true === ($imageScaleFillVisualNode['image']['color_managed'] ?? null), 'visual-node-image-color-managed');
    $assert(200.0 === ($imageScaleFillVisualNode['image']['originalImageWidth'] ?? null), 'visual-node-image-original-width');

    $imageTransformResult = blocks_engine_figma_transformer_transform_scenegraph(array(
        'name'   => 'Image Transform Fixture',
        'assets' => array(
            'crop-image' => array('mime_type' => 'image/png', 'content' => 'crop image'),
            'fill-crop'  => array('mime_type' => 'image/png', 'content' => 'fill image'),
        ),
        'nodes'  => array(
            array(
                'id'         => 'image:crop',
                'type'       => 'RECTANGLE',
                'name'       => 'Cropped image',
                'width'      => 100,
                'height'     => 80,
                'fillPaints' => array(
                    array(
                        'type'           => 'IMAGE',
                        'imageRef'       => 'crop-image',
                        'imageScaleMode' => 'STRETCH',
                        'transform'      => array(
                            array(0.5, 0, 0.25),
                            array(0, 0.8, 0.1),
                        ),
                    ),
                ),
            ),
            array(
                'id'         => 'image:fill-crop',
                'type'       => 'RECTANGLE',
                'name'       => 'Fill crop image',
                'width'      => 100,
                'height'     => 80,
                'fillPaints' => array(
                    array(
                        'type'           => 'IMAGE',
                        'imageRef'       => 'fill-crop',
                        'imageScaleMode' => 'FILL',
                        'transform'      => array(
                            array(0.5, 0, 0.25),
                            array(0, 0.8, 0.1),
                        ),
                    ),
                ),
            ),
        ),
    ));
    $imageTransformCss = $fileContent($imageTransformResult, 'style.css');
    $assert(str_contains($imageTransformCss, '.figma-node-image-crop-cropped-image{width:100px;height:80px;background-image:url("assets/crop-image.png");background-size:200px 100px;background-repeat:no-repeat;background-position:-50px -10px}'), 'image-stretch-transform-emits-crop-background');
    $assert(str_contains($imageTransformCss, '.figma-node-image-fill-crop-fill-crop-image{width:100px;height:80px;background-image:url("assets/fill-crop.png");background-size:cover;background-position:center}'), 'image-fill-transform-keeps-cover-background');

    $nestedImageOverrideResult = blocks_engine_figma_transformer_transform_scenegraph(array(
        'name'  => 'Nested Image Override Fixture',
        'nodes' => array(
            array(
                'guid'     => array('sessionID' => 700, 'localID' => 1),
                'type'     => 'COMPONENT',
                'name'     => 'Image component',
                'children' => array(
                    array(
                        'guid'       => array('sessionID' => 700, 'localID' => 2),
                        'type'       => 'RECTANGLE',
                        'name'       => 'Image',
                        'width'      => 100,
                        'height'     => 50,
                        'fillPaints' => array(array('type' => 'IMAGE', 'imageRef' => 'default-image')),
                    ),
                ),
            ),
            array(
                'guid'     => array('sessionID' => 700, 'localID' => 3),
                'type'     => 'COMPONENT',
                'name'     => 'Preview component',
                'children' => array(
                    array(
                        'guid'       => array('sessionID' => 700, 'localID' => 4),
                        'type'       => 'INSTANCE',
                        'name'       => 'Image slot',
                        'symbolData' => array('symbolID' => array('sessionID' => 700, 'localID' => 1)),
                    ),
                ),
            ),
            array(
                'id'         => 'instance:preview',
                'type'       => 'INSTANCE',
                'name'       => 'Preview instance',
                'symbolData' => array(
                    'symbolID' => array('sessionID' => 700, 'localID' => 3),
                    'symbolOverrides' => array(
                        array(
                            'guidPath'   => array('guids' => array(array('sessionID' => 700, 'localID' => 4), array('sessionID' => 700, 'localID' => 2))),
                            'fillPaints' => array(
                                array('type' => 'IMAGE', 'imageRef' => 'default-image'),
                                array('type' => 'IMAGE', 'imageRef' => 'override-image'),
                            ),
                        ),
                    ),
                ),
            ),
        ),
        'assets' => array(
            array('id' => 'default-image', 'content' => 'default'),
            array('id' => 'override-image', 'content' => 'override'),
        ),
    ));
    $nestedImageOverrideCss = $fileContent($nestedImageOverrideResult, 'style.css');
    $assert(str_contains($nestedImageOverrideCss, '.figma-node-instance-preview-700-4-700-2-image'), 'nested-image-override-emits-nested-image-node');
    $assert(str_contains($nestedImageOverrideCss, 'background-image:url("assets/override-image.bin")'), 'nested-image-override-replaces-source-image-paint');
    $assert(! str_contains($nestedImageOverrideCss, 'background-image:url("assets/override-image.bin"),url("assets/default-image.bin")'), 'nested-image-override-drops-stale-source-image-paint');

    $styleBackedImageOverrideResult = blocks_engine_figma_transformer_transform_scenegraph(array(
        'name'  => 'Style Backed Image Override Fixture',
        'nodes' => array(
            array(
                'guid'       => array('sessionID' => 701, 'localID' => 1),
                'type'       => 'RECTANGLE',
                'name'       => 'Image style source',
                'fillPaints' => array(array('type' => 'IMAGE', 'imageRef' => 'styled-default-image')),
            ),
            array(
                'guid'     => array('sessionID' => 701, 'localID' => 2),
                'type'     => 'COMPONENT',
                'name'     => 'Styled image component',
                'children' => array(
                    array(
                        'guid'           => array('sessionID' => 701, 'localID' => 3),
                        'type'           => 'RECTANGLE',
                        'name'           => 'Styled image',
                        'width'          => 100,
                        'height'         => 50,
                        'styleIdForFill' => array('guid' => array('sessionID' => 701, 'localID' => 1)),
                    ),
                ),
            ),
            array(
                'id'         => 'instance:styled-preview',
                'type'       => 'INSTANCE',
                'name'       => 'Styled preview instance',
                'symbolData' => array(
                    'symbolID' => array('sessionID' => 701, 'localID' => 2),
                    'symbolOverrides' => array(
                        array(
                            'guidPath'   => array('guids' => array(array('sessionID' => 701, 'localID' => 3))),
                            'fillPaints' => array(array('type' => 'IMAGE', 'imageRef' => 'styled-override-image')),
                        ),
                    ),
                ),
            ),
        ),
        'assets' => array(
            array('id' => 'styled-default-image', 'content' => 'styled default'),
            array('id' => 'styled-override-image', 'content' => 'styled override'),
        ),
    ));
    $styleBackedImageOverrideCss = $fileContent($styleBackedImageOverrideResult, 'style.css');
    $assert(str_contains($styleBackedImageOverrideCss, 'background-image:url("assets/styled-override-image.bin")'), 'style-backed-image-override-replaces-style-image-paint');
    $assert(! str_contains($styleBackedImageOverrideCss, 'styled-default-image.bin'), 'style-backed-image-override-drops-stale-style-image-paint');
}
