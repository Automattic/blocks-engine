<?php

declare(strict_types=1);

/**
 * @param callable(bool, string): void $assert
 * @param callable(array<string, mixed>, string): string $fileContent
 * @param callable(array<string, mixed>, string): ?array<string, mixed> $findVisualNode
 */
function blocks_engine_figma_transformer_run_origin_inference_contract(callable $assert, callable $fileContent, callable $findVisualNode): void
{
    $decorativeOriginResult = blocks_engine_figma_transformer_transform_scenegraph(array(
        'name'  => 'Decorative Origin Candidate Fixture',
        'nodes' => array(
            array(
                'id'                  => 'origin-pref:frame',
                'type'                => 'FRAME',
                'name'                => 'Decorative origin frame',
                'absoluteBoundingBox' => array('x' => 0, 'y' => 0, 'width' => 700, 'height' => 360),
                'children'            => array(
                    array(
                        'id'                  => 'origin-pref:underlay',
                        'type'                => 'FRAME',
                        'name'                => 'Decorative glow',
                        'absoluteBoundingBox' => array('x' => -500, 'y' => -200, 'width' => 1100, 'height' => 720),
                        'children'            => array(
                            array(
                                'id'                  => 'origin-pref:vector',
                                'type'                => 'VECTOR',
                                'name'                => 'Glow vector',
                                'absoluteBoundingBox' => array('x' => -500, 'y' => -200, 'width' => 1100, 'height' => 720),
                            ),
                        ),
                    ),
                    array(
                        'id'                  => 'origin-pref:copy',
                        'type'                => 'TEXT',
                        'name'                => 'Headline',
                        'characters'          => 'Content establishes the local origin',
                        'absoluteBoundingBox' => array('x' => 100, 'y' => 80, 'width' => 320, 'height' => 48),
                    ),
                ),
            ),
        ),
    ));
    $decorativeOriginCss = $fileContent($decorativeOriginResult, 'style.css');
    $decorativeOriginCopy = $findVisualNode($decorativeOriginResult, 'origin-pref:copy');
    $assert(str_contains($decorativeOriginCss, '.figma-node-origin-pref-copy-headline{width:320px;height:48px;position:absolute;left:100px;top:80px'), 'origin-inference-ignores-decorative-outlier-css');
    $assert(array('x' => 100.0, 'y' => 80.0, 'width' => 320.0, 'height' => 48.0) === ($decorativeOriginCopy['rect'] ?? null), 'origin-inference-ignores-decorative-outlier-visual-map');

    $shapeOnlyOriginResult = blocks_engine_figma_transformer_transform_scenegraph(array(
        'name'  => 'Shape Only Origin Candidate Fixture',
        'nodes' => array(
            array(
                'id'                  => 'shape-origin:frame',
                'type'                => 'FRAME',
                'name'                => 'Shape only origin frame',
                'absoluteBoundingBox' => array('x' => 0, 'y' => 0, 'width' => 700, 'height' => 360),
                'children'            => array(
                    array(
                        'id'                  => 'shape-origin:first',
                        'type'                => 'RECTANGLE',
                        'name'                => 'First shape',
                        'absoluteBoundingBox' => array('x' => -500, 'y' => -200, 'width' => 200, 'height' => 160),
                    ),
                    array(
                        'id'                  => 'shape-origin:second',
                        'type'                => 'RECTANGLE',
                        'name'                => 'Second shape',
                        'absoluteBoundingBox' => array('x' => -300, 'y' => -120, 'width' => 200, 'height' => 160),
                    ),
                ),
            ),
        ),
    ));
    $shapeOnlyOriginCss = $fileContent($shapeOnlyOriginResult, 'style.css');
    $shapeOnlyFirst = $findVisualNode($shapeOnlyOriginResult, 'shape-origin:first');
    $assert(str_contains($shapeOnlyOriginCss, '.figma-node-shape-origin-first-first-shape{width:200px;height:160px;position:absolute;left:0px;top:0px'), 'origin-inference-shape-only-fallback-css');
    $assert(array('x' => 0.0, 'y' => 0.0, 'width' => 200.0, 'height' => 160.0) === ($shapeOnlyFirst['rect'] ?? null), 'origin-inference-shape-only-fallback-visual-map');
}
