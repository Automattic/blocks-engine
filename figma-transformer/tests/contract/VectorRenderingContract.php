<?php

declare(strict_types=1);

function blocks_engine_figma_transformer_run_vector_rendering_contract(Closure $assert, Closure $fileContent, Closure $findVisualNode, string $vectorCommandBlob, string $externalizedVectorPath, string $simpleRectNetworkBlob): void
{
    $oversizedVectorResult = blocks_engine_figma_transformer_transform_scenegraph(array(
        'name'  => 'Oversized Vector Bounds Fixture',
        'blobs' => array(array('bytes' => $vectorCommandBlob)),
        'nodes' => array(
            array(
                'id'           => 'vector:oversized-bounds',
                'type'         => 'VECTOR',
                'name'         => 'Oversized Bounds',
                'width'        => 5,
                'height'       => 5,
                'fillGeometry' => array(array('commandsBlob' => 0)),
            ),
        ),
    ));
    $oversizedVectorHtml = $fileContent($oversizedVectorResult, 'index.html');
    $oversizedVectorCss = $fileContent($oversizedVectorResult, 'style.css');
    $assert(str_contains($oversizedVectorHtml, 'viewBox="0 0 10 10"'), 'oversized-vector-viewbox-uses-path-bounds');
    $assert(str_contains($oversizedVectorCss, '.figma-node-vector-oversized-bounds-oversized-bounds{width:5px;height:5px'), 'oversized-vector-css-keeps-node-size');
    
    $edgeAlignedFilledVectorResult = blocks_engine_figma_transformer_transform_scenegraph(array(
        'name'  => 'Edge Aligned Filled Vector Fixture',
        'nodes' => array(
            array(
                'id'                 => 'vector:edge-aligned-fill',
                'type'               => 'VECTOR',
                'name'               => 'Edge Aligned Fill',
                'width'              => 10,
                'height'             => 10,
                'fillPaints'         => array(array('type' => 'SOLID', 'color' => array('r' => 0, 'g' => 0, 'b' => 0))),
                'figma_vector_paths' => array(array('data' => 'M0 0L10 0L10 10L0 10Z')),
            ),
        ),
    ));
    $edgeAlignedFilledVectorHtml = $fileContent($edgeAlignedFilledVectorResult, 'index.html');
    $assert(str_contains($edgeAlignedFilledVectorHtml, 'viewBox="0 0 10 10"'), 'edge-aligned-filled-vector-viewbox-keeps-intrinsic-bounds');
    $assert(! str_contains($edgeAlignedFilledVectorHtml, 'viewBox="-0.5 -0.5 11 11"'), 'edge-aligned-filled-vector-no-stroke-padding');

    $arcEllipseResult = blocks_engine_figma_transformer_transform_scenegraph(array(
        'name'  => 'Arc Ellipse Fixture',
        'nodes' => array(
            array(
                'id'         => 'ellipse:arc',
                'type'       => 'ELLIPSE',
                'name'       => 'Progress Ring',
                'width'      => 20,
                'height'     => 20,
                'fillPaints' => array(array('type' => 'SOLID', 'color' => array('r' => 0, 'g' => 0, 'b' => 0))),
                'arcData'    => array('startingAngle' => 0.0, 'endingAngle' => 1.57079632679, 'innerRadius' => 0.5),
            ),
        ),
    ));
    $arcEllipseHtml = $fileContent($arcEllipseResult, 'index.html');
    $assert(str_contains($arcEllipseHtml, 'data-figma-node-id="ellipse:arc"') && str_contains($arcEllipseHtml, '<path d="M 20 10 A 10 10 0 0 1'), 'arc-ellipse-renders-path');
    $assert(! str_contains($arcEllipseHtml, '<ellipse cx="10" cy="10"'), 'arc-ellipse-does-not-render-full-ellipse');

    $strokedInlineVectorResult = blocks_engine_figma_transformer_transform_scenegraph(array(
        'name'  => 'Stroked Inline Vector Fixture',
        'nodes' => array(
            array(
                'id'                 => 'vector:stroked-inline',
                'type'               => 'VECTOR',
                'name'               => 'Stroked Inline Icon',
                'width'              => 16,
                'height'             => 16,
                'strokeWeight'       => 2,
                'strokePaints'       => array(array('type' => 'SOLID', 'color' => array('r' => 0.1215686275, 'g' => 0.1215686275, 'b' => 0.1215686275, 'a' => 1))),
                'figma_vector_paths' => array(array('data' => 'M4 4L12 12')),
            ),
        ),
    ));
    $strokedInlineVectorHtml = $fileContent($strokedInlineVectorResult, 'index.html');
    $strokedInlineVectorCss = $fileContent($strokedInlineVectorResult, 'style.css');
    $assert(str_contains($strokedInlineVectorHtml, 'stroke="#1f1f1f"') && str_contains($strokedInlineVectorHtml, 'stroke-width="2"'), 'stroked-inline-vector-svg-carries-stroke');
    $assert(! str_contains($strokedInlineVectorCss, 'border:2px solid #1f1f1f'), 'stroked-inline-vector-wrapper-no-css-border');

    $strokedGeometryStyleResult = blocks_engine_figma_transformer_transform_scenegraph(array(
        'name'  => 'Stroked Geometry Style Fixture',
        'nodes' => array(
            array(
                'id'             => 'vector:stroke-geometry-style',
                'type'           => 'VECTOR',
                'name'           => 'Stroked Geometry Style',
                'width'          => 16,
                'height'         => 16,
                'strokeWeight'   => 3,
                'strokeCap'      => 'ROUND',
                'strokeJoin'     => 'BEVEL',
                'dashPattern'    => array(4, 2),
                'strokePaints'   => array(array('type' => 'SOLID', 'color' => array('r' => 0, 'g' => 0, 'b' => 0, 'a' => 1))),
                'strokeGeometry' => array(array('path' => 'M 1 1 L 15 15', 'styleID' => 42)),
            ),
        ),
    ));
    $strokedGeometryStyleHtml = $fileContent($strokedGeometryStyleResult, 'index.html');
    $assert(str_contains($strokedGeometryStyleHtml, '<path d="M1 1L15 15" fill="#000000" data-figma-style-id="42"/>'), 'stroke-geometry-renders-expanded-outline-as-fill');
    $assert(! str_contains($strokedGeometryStyleHtml, 'stroke-linecap="round"') && ! str_contains($strokedGeometryStyleHtml, 'stroke-dasharray="4 2"'), 'stroke-geometry-does-not-restroke-expanded-outline');
    $assert(str_contains($strokedGeometryStyleHtml, 'data-figma-style-id="42"'), 'vector-path-style-id-carry-through');

    $mixedGeometryVectorResult = blocks_engine_figma_transformer_transform_scenegraph(array(
        'name'  => 'Mixed Fill And Stroke Geometry Fixture',
        'nodes' => array(
            array(
                'id'             => 'vector:mixed-geometry',
                'type'           => 'VECTOR',
                'name'           => 'Mixed Geometry Icon',
                'width'          => 16,
                'height'         => 16,
                'strokeWeight'   => 2,
                'fillPaints'     => array(array('type' => 'SOLID', 'color' => array('r' => 0, 'g' => 0, 'b' => 0, 'a' => 1))),
                'strokePaints'   => array(array('type' => 'SOLID', 'color' => array('r' => 1, 'g' => 0, 'b' => 0, 'a' => 1))),
                'fillGeometry'   => array(array('path' => 'M 0 0 L 4 0 L 4 4 L 0 4 Z')),
                'strokeGeometry' => array(array('path' => 'M 6 6 L 10 6 L 10 10 L 6 10 Z')),
            ),
        ),
    ));
    $mixedGeometryVectorHtml = $fileContent($mixedGeometryVectorResult, 'index.html');
    $assert(str_contains($mixedGeometryVectorHtml, '<path d="M0 0L4 0 4 4 0 4Z" fill="#000000"/>'), 'fill-geometry-uses-fill-paint-only');
    $assert(str_contains($mixedGeometryVectorHtml, '<path d="M6 6L10 6 10 10 6 10Z" fill="#ff0000"/>'), 'stroke-geometry-uses-stroke-paint-as-fill');
    $assert(! str_contains($mixedGeometryVectorHtml, 'stroke="#ff0000"'), 'mixed-geometry-does-not-restroke-stroke-geometry');

    $vectorNetworkObjectResult = blocks_engine_figma_transformer_transform_scenegraph(array(
        'name'  => 'Vector Network Object Fixture',
        'nodes' => array(
            array(
                'id'         => 'vector:network-object',
                'type'       => 'VECTOR',
                'name'       => 'Network Object',
                'width'      => 20,
                'height'     => 20,
                'fillPaints' => array(array('type' => 'SOLID', 'color' => array('r' => 0, 'g' => 0, 'b' => 0, 'a' => 1))),
                'vectorData' => array(
                    'normalizedSize' => array('x' => 10, 'y' => 10),
                    'vectorNetwork'  => array(
                        'vertices' => array(
                            array('x' => 0, 'y' => 0),
                            array('x' => 10, 'y' => 0),
                            array('x' => 10, 'y' => 10),
                            array('x' => 0, 'y' => 10),
                        ),
                        'segments' => array(
                            array('start' => 0, 'end' => 1),
                            array('start' => 1, 'end' => 2),
                            array('start' => 2, 'end' => 3),
                            array('start' => 3, 'end' => 0),
                        ),
                        'regions' => array(array('segments' => array(0, 1, 2, 3), 'windingRule' => 'EVENODD')),
                    ),
                ),
            ),
        ),
    ));
    $vectorNetworkObjectHtml = $fileContent($vectorNetworkObjectResult, 'index.html');
    $assert(str_contains($vectorNetworkObjectHtml, 'data-figma-node-id="vector:network-object"') && str_contains($vectorNetworkObjectHtml, 'data-figma-vector="true"'), 'vector-network-object-renders');
    $assert(str_contains($vectorNetworkObjectHtml, '<g transform="scale(2 2)"><path d="M0 0L10 0 10 10 0 10 0 0Z"'), 'vector-network-normalized-size-scales-path');
    $assert(str_contains($vectorNetworkObjectHtml, 'fill-rule="evenodd"'), 'vector-network-region-winding-rule-renders');

    $staleScaledIconResult = blocks_engine_figma_transformer_transform_scenegraph(array(
        'name'  => 'Stale Vector Scale Icon Fixture',
        'nodes' => array(
            array(
                'id'                 => 'vector:stale-scale-icon',
                'type'               => 'VECTOR',
                'name'               => 'Comment Icon',
                'width'              => 17.355,
                'height'             => 17.355,
                'strokeWeight'       => 1.5,
                'strokePaints'       => array(array('type' => 'SOLID', 'color' => array('r' => 0, 'g' => 0, 'b' => 0, 'a' => 1))),
                'figma_vector_scale' => array('x' => 0.078, 'y' => 0.078),
                'figma_vector_paths' => array(array('data' => 'M-1.238 -1.116L16.117 -1.116L16.117 16.239L-1.238 16.239Z')),
            ),
        ),
    ));
    $staleScaledIconHtml = $fileContent($staleScaledIconResult, 'index.html');
    $assert(str_contains($staleScaledIconHtml, 'data-figma-node-id="vector:stale-scale-icon"') && str_contains($staleScaledIconHtml, 'data-figma-vector="true"'), 'stale-vector-scale-icon-renders');
    $assert(str_contains($staleScaledIconHtml, 'viewBox="-1.238 -1.116 17.355 17.355"'), 'stale-vector-scale-icon-uses-real-fse-path-bounds');
    $assert(! str_contains($staleScaledIconHtml, 'scale(0.078 0.078)'), 'stale-vector-scale-icon-skips-stale-scale');
    $assert(str_contains($staleScaledIconHtml, 'M-1.238-1.116') && str_contains($staleScaledIconHtml, 'stroke="#000000"'), 'stale-vector-scale-icon-path-remains-visible');
      
    $largeDecodedPath = 'M 0 0' . str_repeat(' L 10 10', 3000) . ' Z';
    $largeDecodedVectorResult = blocks_engine_figma_transformer_transform_scenegraph(array(
        'name'  => 'Large Decoded Vector Fixture',
        'nodes' => array(
            array(
                'id'                 => 'vector:large-decoded',
                'type'               => 'VECTOR',
                'name'               => 'Large Decoded Vector',
                'width'              => 10,
                'height'             => 10,
                'figma_vector_paths' => array(array('data' => $largeDecodedPath, 'source' => 'strokeGeometry')),
            ),
            array(
                'id'       => 'vector:large-raw',
                'type'     => 'VECTOR',
                'name'     => 'Large Raw Vector',
                'width'    => 10,
                'height'   => 10,
                'pathData' => $largeDecodedPath,
            ),
        ),
    ));
    $largeDecodedVectorHtml = $fileContent($largeDecodedVectorResult, 'index.html');
    $largeDecodedVectorDiagnosticCodes = array_map(
        static fn (array $diagnostic): string => (string) ($diagnostic['code'] ?? ''),
        $largeDecodedVectorResult['diagnostics'] ?? array()
    );
    $largeDecodedVectorDiagnostics = $largeDecodedVectorResult['source_reports']['figma']['html']['transform_diagnostics']['vectors'] ?? array();
    $largeRawPlaceholder = null;
    foreach ( $largeDecodedVectorDiagnostics['placeholder_nodes'] ?? array() as $placeholderNode ) {
        if ( is_array($placeholderNode) && 'vector:large-raw' === ($placeholderNode['node_id'] ?? null) ) {
            $largeRawPlaceholder = $placeholderNode;
            break;
        }
    }
    $assert(str_contains($largeDecodedVectorHtml, 'data-figma-node-id="vector:large-decoded"') && str_contains($largeDecodedVectorHtml, 'data-figma-vector="true"'), 'large-decoded-vector-path-renders');
    $assert(str_contains($largeDecodedVectorHtml, 'data-figma-node-id="vector:large-raw"') && str_contains($largeDecodedVectorHtml, 'data-figma-unsupported-vector="true"'), 'large-raw-vector-path-remains-capped');
    $assert(in_array('unsupported_vector_node_placeholder', $largeDecodedVectorDiagnosticCodes, true), 'large-raw-vector-placeholder-diagnostic');
    $assert('oversized_path_data' === ($largeRawPlaceholder['reason'] ?? null), 'large-raw-vector-placeholder-reason');
    $assert(array('pathData') === ($largeRawPlaceholder['source_fields'] ?? null), 'large-raw-vector-placeholder-source-field');
    $assert(1 === ($largeDecodedVectorDiagnostics['placeholder_reasons']['oversized_path_data'] ?? null), 'large-raw-vector-placeholder-reason-count');
    
    // Figma REST/plugin geometry shape: fillGeometry/strokeGeometry carry ready-to-use
    // SVG path strings. They must emit real inline <svg><path> (not placeholders) and be
    // counted by the vector-decode-coverage diagnostic.
    $readyGeometryVectorResult = blocks_engine_figma_transformer_transform_scenegraph(array(
        'name'  => 'Ready Geometry Vector Fixture',
        'nodes' => array(
            array(
                'id'           => 'vector:ready-fill-geometry',
                'type'         => 'VECTOR',
                'name'         => 'Brand Logo',
                'width'        => 24,
                'height'       => 24,
                'fillPaints'   => array(array('type' => 'SOLID', 'color' => array('r' => 0, 'g' => 0, 'b' => 0))),
                'fillGeometry' => array(
                    array('path' => 'M 0 0 L 24 0 L 24 24 L 0 24 Z', 'windingRule' => 'NONZERO'),
                ),
            ),
            array(
                'id'     => 'vector:no-geometry',
                'type'   => 'VECTOR',
                'name'   => 'Geometryless Mark',
                'width'  => 24,
                'height' => 24,
            ),
        ),
    ));
    $readyGeometryVectorHtml = $fileContent($readyGeometryVectorResult, 'index.html');
    $readyGeometryVectors = $readyGeometryVectorResult['source_reports']['figma']['html']['transform_diagnostics']['vectors'] ?? array();
    $readyGeometryCoverage = $readyGeometryVectors['decode_coverage'] ?? array();
    $readyGeometryNode = null;
    foreach ( $readyGeometryVectors['placeholder_nodes'] ?? array() as $placeholderNode ) {
        if ( is_array($placeholderNode) && 'vector:ready-fill-geometry' === ($placeholderNode['node_id'] ?? null) ) {
            $readyGeometryNode = $placeholderNode;
            break;
        }
    }
    $assert(str_contains($readyGeometryVectorHtml, 'data-figma-node-id="vector:ready-fill-geometry"') && str_contains($readyGeometryVectorHtml, 'data-figma-vector="true"'), 'ready-fill-geometry-renders-inline-svg');
    $assert(str_contains($readyGeometryVectorHtml, '<path d="M0 0L24 0 24 24 0 24Z"') && str_contains($readyGeometryVectorHtml, 'fill-rule="nonzero"'), 'ready-fill-geometry-emits-path');
    $assert(null === $readyGeometryNode, 'ready-fill-geometry-is-not-a-placeholder');
    $assert(2 === (int) ($readyGeometryCoverage['vector_nodes'] ?? 0), 'ready-geometry-decode-coverage-node-count');
    $assert(1 === (int) ($readyGeometryCoverage['decoded_to_svg'] ?? 0), 'ready-geometry-decode-coverage-decoded-count');
    $assert(1 === (int) ($readyGeometryCoverage['placeholders'] ?? 0), 'ready-geometry-decode-coverage-placeholder-count');
    $assert(0.5 === ($readyGeometryCoverage['coverage_ratio'] ?? null), 'ready-geometry-decode-coverage-ratio');
    $assert(1 === (int) ($readyGeometryCoverage['placeholder_reason_categories']['no_geometry_available'] ?? 0), 'ready-geometry-decode-coverage-no-geometry-category');

    $localPaintWithStyleResult = blocks_engine_figma_transformer_transform_scenegraph(array(
        'name'  => 'Local Paint With Style Fixture',
        'nodes' => array(
            array(
                'id'         => 'paint-style:white',
                'type'       => 'RECTANGLE',
                'name'       => 'White paint style',
                'styleType'  => 'FILL',
                'fillPaints' => array(array('type' => 'SOLID', 'color' => array('r' => 1, 'g' => 1, 'b' => 1, 'a' => 1))),
            ),
            array(
                'id'             => 'vector:local-paint-with-style',
                'type'           => 'ROUNDED_RECTANGLE',
                'name'           => 'Local Paint With Style',
                'width'          => 28,
                'height'         => 3,
                'styleIdForFill' => 'paint-style:white',
                'fillPaints'     => array(array('type' => 'SOLID', 'color' => array('r' => 0.850980401, 'g' => 0.850980401, 'b' => 0.850980401, 'a' => 1))),
                'fillGeometry'   => array(array('path' => 'M 0 0 L 28 0 L 28 3 L 0 3 Z', 'windingRule' => 'NONZERO')),
            ),
        ),
    ));
    $localPaintWithStyleCss = $fileContent($localPaintWithStyleResult, 'style.css');
    $localPaintWithStyleDiagnostics = array_values(array_filter(
        $localPaintWithStyleResult['diagnostics'] ?? array(),
        static fn (array $diagnostic): bool => 'figma_local_style_paint_conflict' === ($diagnostic['code'] ?? null)
    ));
    $assert(str_contains($localPaintWithStyleCss, '.figma-node-vector-local-paint-with-style-local-paint-with-style{width:28px;height:3px;background:#d9d9d9'), 'local-fill-paint-wins-over-style-fill');
    $assert(! str_contains($localPaintWithStyleCss, '.figma-node-vector-local-paint-with-style-local-paint-with-style{width:28px;height:3px;background:#ffffff'), 'style-fill-does-not-overwrite-local-fill-paint');
    $assert('local' === ($localPaintWithStyleDiagnostics[0]['context']['precedence'] ?? null), 'local-style-fill-conflict-diagnostic-precedence');

    $containerPaintWithStyleResult = blocks_engine_figma_transformer_transform_scenegraph(array(
        'name'  => 'Container Paint With Style Fixture',
        'nodes' => array(
            array(
                'id'         => 'paint-style:yellow',
                'type'       => 'RECTANGLE',
                'name'       => 'Yellow paint style',
                'styleType'  => 'FILL',
                'fillPaints' => array(array('type' => 'SOLID', 'color' => array('r' => 1, 'g' => 0.811764717, 'b' => 0, 'a' => 1))),
            ),
            array(
                'id'             => 'frame:stale-local-with-style',
                'type'           => 'FRAME',
                'name'           => 'Stale Local With Style',
                'width'          => 28,
                'height'         => 3,
                'styleIdForFill' => 'paint-style:yellow',
                'fillPaints'     => array(array('type' => 'SOLID', 'color' => array('r' => 1, 'g' => 1, 'b' => 1, 'a' => 1))),
                'fillGeometry'   => array(array('path' => 'M 0 0 L 28 0 L 28 3 L 0 3 Z', 'windingRule' => 'NONZERO')),
            ),
        ),
    ));
    $containerPaintWithStyleCss = $fileContent($containerPaintWithStyleResult, 'style.css');
    $containerPaintWithStyleDiagnostics = array_values(array_filter(
        $containerPaintWithStyleResult['diagnostics'] ?? array(),
        static fn (array $diagnostic): bool => 'figma_local_style_paint_conflict' === ($diagnostic['code'] ?? null)
    ));
    $assert(str_contains($containerPaintWithStyleCss, '.figma-node-frame-stale-local-with-style-stale-local-with-style{width:28px;height:3px;background:#ffcf00'), 'style-fill-wins-over-container-stale-local-fill');
    $assert('style' === ($containerPaintWithStyleDiagnostics[0]['context']['precedence'] ?? null), 'container-style-fill-conflict-diagnostic-precedence');

    $shapeCommandBlobPaintWithStyleResult = blocks_engine_figma_transformer_transform_scenegraph(array(
        'name'        => 'Shape Command Blob Paint Style Fixture',
        'figma_blobs' => array('M0 0L28 0 28 3 0 3Z'),
        'nodes'       => array(
            array(
                'id'         => 'paint-style:accent-two',
                'type'       => 'RECTANGLE',
                'name'       => 'Accent - Two',
                'styleType'  => 'FILL',
                'fillPaints' => array(array('type' => 'SOLID', 'color' => array('r' => 1, 'g' => 0.811764717, 'b' => 0, 'a' => 1))),
            ),
            array(
                'id'             => 'shape:command-blob-stale-local-with-style',
                'type'           => 'ROUNDED_RECTANGLE',
                'name'           => 'Command Blob Stale Local With Style',
                'width'          => 28,
                'height'         => 3,
                'styleIdForFill' => 'paint-style:accent-two',
                'fillPaints'     => array(array('type' => 'SOLID', 'color' => array('r' => 0.850980401, 'g' => 0.850980401, 'b' => 0.850980401, 'a' => 1))),
                'fillGeometry'   => array(array('commandsBlob' => 0, 'styleID' => 0, 'windingRule' => 'NONZERO')),
            ),
        ),
    ));
    $shapeCommandBlobPaintWithStyleCss = $fileContent($shapeCommandBlobPaintWithStyleResult, 'style.css');
    $shapeCommandBlobPaintWithStyleDiagnostics = array_values(array_filter(
        $shapeCommandBlobPaintWithStyleResult['diagnostics'] ?? array(),
        static fn (array $diagnostic): bool => 'figma_local_style_paint_conflict' === ($diagnostic['code'] ?? null)
    ));
    $assert(str_contains($shapeCommandBlobPaintWithStyleCss, '.figma-node-shape-command-blob-stale-local-with-style-command-blob-stale-local-with-style{width:28px;height:3px;background:#ffcf00'), 'style-fill-wins-over-command-blob-shape-stale-local-fill');
    $assert('style' === ($shapeCommandBlobPaintWithStyleDiagnostics[0]['context']['precedence'] ?? null), 'command-blob-shape-style-fill-conflict-diagnostic-precedence');

    $externalizedEquivalentVectorPath = 'M0,0' . str_repeat('L10,10', 12000) . 'Z';
    $externalizedVectorResult = blocks_engine_figma_transformer_transform_scenegraph(array(
        'name'  => 'Externalized Vector Fixture',
        'nodes' => array(
            array(
                'id'                 => 'vector:externalized-a',
                'type'               => 'VECTOR',
                'name'               => 'Externalized Vector',
                'width'              => 10,
                'height'             => 10,
                'figma_vector_paths' => array(array('data' => $externalizedVectorPath, 'source' => 'strokeGeometry')),
            ),
            array(
                'id'                 => 'vector:externalized-b',
                'type'               => 'VECTOR',
                'name'               => 'Externalized Vector',
                'width'              => 10,
                'height'             => 10,
                'figma_vector_paths' => array(array('data' => $externalizedEquivalentVectorPath, 'source' => 'strokeGeometry')),
            ),
        ),
    ));
    $externalizedVectorHtml = $fileContent($externalizedVectorResult, 'index.html');
    $externalizedVectorCss = $fileContent($externalizedVectorResult, 'style.css');
    $externalizedVectorAssets = array_values(array_filter(
        $externalizedVectorResult['assets'] ?? array(),
        static fn (array $asset): bool => str_starts_with((string) ($asset['path'] ?? ''), 'assets/vector-') && 'image/svg+xml' === ($asset['mime_type'] ?? null)
    ));
    $assert(2 === substr_count($externalizedVectorHtml, 'class="figma-vector-asset"'), 'large-vector-externalized-img-references');
    $assert(1 === count($externalizedVectorAssets), 'large-vector-externalized-deduped-asset');
    $assert(str_contains($externalizedVectorCss, '.figma-vector-asset{display:block;width:100%;height:100%;object-fit:fill}'), 'large-vector-asset-css');
    $externalizedVectorAssetContent = $fileContent($externalizedVectorResult, (string) ($externalizedVectorAssets[0]['path'] ?? ''));
    $assert(str_contains($externalizedVectorAssetContent, '<svg '), 'large-vector-externalized-svg-content');
    $assert(str_contains($externalizedVectorAssetContent, 'd="M0 0L10 10 10 10'), 'large-vector-path-data-canonicalized');
    $assert(! str_contains($externalizedVectorAssetContent, '10.000001'), 'large-vector-path-data-precision-reduced');
    $assert(! str_contains($externalizedVectorAssetContent, 'L10 10L10 10'), 'large-vector-path-data-repeated-commands-elided');
    $externalizedVectorDiagnostics = $externalizedVectorResult['source_reports']['figma']['html']['transform_diagnostics']['generated_svg_assets'] ?? array();
    $assert('blocks-engine/figma-transformer/generated-svg-assets/v1' === ($externalizedVectorDiagnostics['schema'] ?? null), 'generated-svg-assets-diagnostics-schema');
    $assert(1 === ($externalizedVectorDiagnostics['count'] ?? null), 'generated-svg-assets-diagnostics-count');
    $assert(($externalizedVectorDiagnostics['bytes'] ?? 0) > 65536, 'generated-svg-assets-diagnostics-bytes');
    $assert(($externalizedVectorDiagnostics['gzip_bytes'] ?? 0) > 0, 'generated-svg-assets-diagnostics-gzip-bytes');
    $assert(1 === ($externalizedVectorDiagnostics['path_element_count'] ?? null), 'generated-svg-assets-diagnostics-path-element-count');
    $assert(($externalizedVectorDiagnostics['path_data_bytes'] ?? 0) > 65536, 'generated-svg-assets-diagnostics-path-data-bytes');
    $assert(($externalizedVectorDiagnostics['largest_path_data_bytes'] ?? 0) > 65536, 'generated-svg-assets-diagnostics-largest-path-data-bytes');
    $assert(1 === ($externalizedVectorDiagnostics['unique_path_data_count'] ?? null), 'generated-svg-assets-diagnostics-unique-path-data-count');
    $assert(0 === ($externalizedVectorDiagnostics['duplicate_path_data_count'] ?? null), 'generated-svg-assets-diagnostics-duplicate-path-data-count');
    $assert(array((string) ($externalizedVectorAssets[0]['path'] ?? '')) === ($externalizedVectorDiagnostics['paths'] ?? null), 'generated-svg-assets-diagnostics-paths');
    $assert(1 === ($externalizedVectorDiagnostics['assets'][0]['path_element_count'] ?? null), 'generated-svg-assets-diagnostics-asset-path-element-count');
    $assert(($externalizedVectorDiagnostics['assets'][0]['path_data_bytes'] ?? 0) > 65536, 'generated-svg-assets-diagnostics-asset-path-data-bytes');
    $assert(1 === ($externalizedVectorDiagnostics['assets'][0]['unique_path_data_count'] ?? null), 'generated-svg-assets-diagnostics-asset-unique-path-data-count');
    
    $starVectorResult = blocks_engine_figma_transformer_transform_scenegraph(array(
        'name'  => 'Star Vector Fixture',
        'nodes' => array(
            array(
                'id'                 => 'vector:star',
                'type'               => 'STAR',
                'name'               => 'Rating Star',
                'width'              => 20,
                'height'             => 20,
                'figma_vector_paths' => array(array('data' => 'M 10 0 L 12 7 L 20 7 L 14 12 L 16 20 L 10 15 L 4 20 L 6 12 L 0 7 L 8 7 Z', 'source' => 'fillGeometry')),
            ),
            array(
                'id'     => 'vector:primitive-star',
                'type'   => 'STAR',
                'name'   => 'Primitive Rating Star',
                'width'  => 20,
                'height' => 20,
                'fill'   => array('r' => 1, 'g' => 0.5, 'b' => 0),
            ),
            array(
                'id'         => 'vector:primitive-polygon',
                'type'       => 'REGULAR_POLYGON',
                'name'       => 'Primitive Polygon',
                'width'      => 20,
                'height'     => 20,
                'pointCount' => 6,
            ),
        ),
    ));
    $starVectorHtml = $fileContent($starVectorResult, 'index.html');
    $assert(str_contains($starVectorHtml, 'data-figma-node-id="vector:star"') && str_contains($starVectorHtml, 'data-figma-vector="true"'), 'star-vector-path-renders');
    $assert(str_contains($starVectorHtml, 'data-figma-node-id="vector:primitive-star"') && str_contains($starVectorHtml, 'data-figma-vector="true"'), 'primitive-star-vector-renders');
    $assert(str_contains($starVectorHtml, 'data-figma-node-id="vector:primitive-polygon"') && str_contains($starVectorHtml, 'data-figma-vector="true"'), 'primitive-polygon-vector-renders');
    $assert(! str_contains($starVectorHtml, 'data-figma-unsupported-vector="true"'), 'star-vector-path-not-placeholder');
    
    $geometrylessVectorResult = blocks_engine_figma_transformer_transform_scenegraph(array(
        'name'  => 'Geometryless Vector Fixture',
        'nodes' => array(
            array(
                'id'         => 'vector:geometryless-parent',
                'type'       => 'FRAME',
                'name'       => 'Geometryless vector parent',
                'fillPaints' => array(array('type' => 'SOLID', 'color' => array('r' => 0.2, 'g' => 0.4, 'b' => 0.6))),
                'children'   => array(
                    array(
                        'id'     => 'vector:geometryless',
                        'type'   => 'VECTOR',
                        'name'   => 'Geometryless vector bounds',
                        'width'  => 16,
                        'height' => 8,
                    ),
                ),
            ),
        ),
    ));
    $geometrylessVectorHtml = $fileContent($geometrylessVectorResult, 'index.html');
    $geometrylessVectorDiagnosticCodes = array_map(
        static fn (array $diagnostic): string => (string) ($diagnostic['code'] ?? ''),
        $geometrylessVectorResult['diagnostics'] ?? array()
    );
    $assert(str_contains($geometrylessVectorHtml, 'data-figma-node-id="vector:geometryless"') && str_contains($geometrylessVectorHtml, '<rect x="0" y="0" width="16" height="8" fill="#336699"/>'), 'geometryless-vector-renders-inherited-color-bounds');
    $assert(! in_array('unsupported_vector_node_placeholder', $geometrylessVectorDiagnosticCodes, true), 'geometryless-vector-no-placeholder-diagnostic');
    
    $singleLoopNetworkBlob = static function (array $points, array $segments, array $regionEntries, ?int $regionSegmentCount = null): string {
        $vertexCount = count($points);
        $segmentCount = count($segments);
        $blob = pack('V3', $vertexCount, $segmentCount, 1) . str_repeat("\0", ( $vertexCount * 20 ) + ( $segmentCount * 16 ) + 12 + ( $vertexCount * 8 ));
        foreach ( $points as $index => $point ) {
            $blob = substr_replace($blob, pack('g', $point[0]) . pack('g', $point[1]), 12 + ( $index * 20 ) + 4, 8);
        }
    
        $segmentOffset = 12 + ( $vertexCount * 20 );
        foreach ( $segments as $index => $segment ) {
            $blob = substr_replace($blob, pack('V2', $segment[0], $segment[1]), $segmentOffset + ( $index * 16 ), 8);
        }
    
        $regionOffset = $segmentOffset + ( $segmentCount * 16 );
        $blob = substr_replace($blob, pack('V3', $regionSegmentCount ?? count($regionEntries), 0, 0), $regionOffset, 12);
        foreach ( $regionEntries as $index => $entry ) {
            $blob = substr_replace($blob, pack('V2', $entry[0], $entry[1]), $regionOffset + 12 + ( $index * 8 ), 8);
        }
    
        return $blob;
    };
    $closedRectNetworkBlob = pack('V3', 4, 4, 1) . str_repeat("\0", 188);
    foreach ( array(array(0.0, 0.0), array(12.0, 0.0), array(12.0, 6.0), array(0.0, 6.0)) as $index => $point ) {
        $offset = 12 + ( $index * 20 ) + 4;
        $closedRectNetworkBlob = substr_replace($closedRectNetworkBlob, pack('g', $point[0]) . pack('g', $point[1]), $offset, 8);
    }
    $nonRectNetworkBlob = pack('V3', 4, 4, 1) . str_repeat("\0", 188);
    foreach ( array(array(0.0, 0.0), array(12.0, 0.0), array(8.0, 6.0), array(0.0, 6.0)) as $index => $point ) {
        $offset = 12 + ( $index * 20 ) + 4;
        $nonRectNetworkBlob = substr_replace($nonRectNetworkBlob, pack('g', $point[0]) . pack('g', $point[1]), $offset, 8);
    }
    $vectorDataResult = blocks_engine_figma_transformer_transform_scenegraph(array(
        'name'  => 'Vector Data Fixture',
        'blobs' => array(array('bytes' => $vectorCommandBlob), array('bytes' => "\xff"), array('bytes' => $simpleRectNetworkBlob), array('bytes' => $closedRectNetworkBlob), array('bytes' => $nonRectNetworkBlob)),
        'nodes' => array(
            array(
                'id'         => 'vector:data',
                'type'       => 'VECTOR',
                'name'       => 'Vector Data Path',
                'width'      => 10,
                'height'     => 10,
                'fillPaints' => array(array('type' => 'SOLID', 'color' => array('r' => 1, 'g' => 0, 'b' => 0))),
                'vectorData' => array('vectorNetworkBlob' => 0),
            ),
            array(
                'id'         => 'vector:data-malformed',
                'type'       => 'VECTOR',
                'name'       => 'Malformed Vector Data Path',
                'width'      => 10,
                'height'     => 10,
                'vectorData' => array('vectorNetworkBlob' => 1),
            ),
            array(
                'id'         => 'vector:data-painted-fallback',
                'type'       => 'VECTOR',
                'name'       => 'Painted Network Fallback',
                'width'      => 12,
                'height'     => 6,
                'fillPaints' => array(array('type' => 'SOLID', 'color' => array('r' => 0, 'g' => 0, 'b' => 1))),
                'vectorData' => array('vectorNetworkBlob' => 1),
            ),
            array(
                'id'         => 'vector:data-simple-rect-network',
                'type'       => 'VECTOR',
                'name'       => 'Simple Rect Network',
                'width'      => 12,
                'height'     => 6,
                'fillPaints' => array(array('type' => 'SOLID', 'color' => array('r' => 0, 'g' => 0.5, 'b' => 1))),
                'vectorData' => array('vectorNetworkBlob' => 2),
            ),
            array(
                'id'         => 'vector:data-closed-rect-network',
                'type'       => 'VECTOR',
                'name'       => 'Closed Rect Network',
                'width'      => 12,
                'height'     => 6,
                'fillPaints' => array(array('type' => 'SOLID', 'color' => array('r' => 0.2, 'g' => 0.4, 'b' => 0.6))),
                'vectorData' => array('vectorNetworkBlob' => 3),
            ),
            array(
                'id'         => 'vector:data-non-rect-network',
                'type'       => 'VECTOR',
                'name'       => 'Non Rect Network',
                'width'      => 12,
                'height'     => 6,
                'fillPaints' => array(array('type' => 'SOLID', 'color' => array('r' => 0.6, 'g' => 0.4, 'b' => 0.2))),
                'vectorData' => array('vectorNetworkBlob' => 4),
            ),
        ),
    ));
    $vectorDataHtml = $fileContent($vectorDataResult, 'index.html');
    $vectorNetworkDiagnostic = null;
    foreach ( $vectorDataResult['diagnostics'] ?? array() as $diagnostic ) {
        if ( is_array($diagnostic) && 'unsupported_vector_network_blob' === ($diagnostic['code'] ?? null) ) {
            $vectorNetworkDiagnostic = $diagnostic;
            break;
        }
    }
    $vectorDataDiagnosticCodes = array_map(
        static fn (array $diagnostic): string => (string) ($diagnostic['code'] ?? ''),
        $vectorDataResult['diagnostics'] ?? array()
    );
    $assert(str_contains($vectorDataHtml, 'data-figma-node-id="vector:data"') && str_contains($vectorDataHtml, 'data-figma-vector="true"'), 'vector-data-renders-svg');
    $assert(str_contains($vectorDataHtml, 'd="M0 0L10 0 10 10Z"'), 'vector-data-renders-command-blob-path');
    $assert(str_contains($vectorDataHtml, 'data-figma-node-id="vector:data-painted-fallback"') && str_contains($vectorDataHtml, '<rect x="0" y="0" width="12" height="6" fill="#0000ff"/>'), 'vector-data-painted-network-fallback-rect');
    $assert(str_contains($vectorDataHtml, 'data-figma-node-id="vector:data-simple-rect-network"') && str_contains($vectorDataHtml, 'd="M0 0L12 0 12 6 0 6Z"') && str_contains($vectorDataHtml, 'fill="#0080ff"'), 'vector-data-simple-rect-network-renders-bounded-path');
    $assert(str_contains($vectorDataHtml, 'data-figma-node-id="vector:data-closed-rect-network"') && str_contains($vectorDataHtml, 'd="M0 0L12 0 12 6 0 6Z"') && str_contains($vectorDataHtml, 'fill="#336699"'), 'vector-data-closed-rect-network-renders-bounded-path');
    $assert(str_contains($vectorDataHtml, 'data-figma-node-id="vector:data-non-rect-network"') && str_contains($vectorDataHtml, 'data-figma-unsupported-vector="true"'), 'vector-data-non-rect-network-keeps-placeholder');
    $assert(in_array('unsupported_vector_network_blob', $vectorDataDiagnosticCodes, true), 'vector-data-malformed-network-diagnostic');
    $assert(1 === ($vectorNetworkDiagnostic['context']['byte_length'] ?? null) && 'ff' === ($vectorNetworkDiagnostic['context']['signature_hex'] ?? null), 'vector-network-diagnostic-context');
    $vectorDataPlaceholderDiagnostics = $vectorDataResult['source_reports']['figma']['html']['transform_diagnostics']['vectors'] ?? array();
    $malformedNetworkPlaceholder = null;
    foreach ( $vectorDataPlaceholderDiagnostics['placeholder_nodes'] ?? array() as $placeholderNode ) {
        if ( is_array($placeholderNode) && 'vector:data-malformed' === ($placeholderNode['node_id'] ?? null) ) {
            $malformedNetworkPlaceholder = $placeholderNode;
            break;
        }
    }
    $assert('unsupported_vector_network_blob' === ($malformedNetworkPlaceholder['reason'] ?? null), 'vector-network-placeholder-reason');
    $assert(array('vectorData.vectorNetworkBlob') === ($malformedNetworkPlaceholder['source_fields'] ?? null), 'vector-network-placeholder-source-field');
    $assert(1 === ($vectorDataPlaceholderDiagnostics['placeholder_reasons']['unsupported_vector_network_blob'] ?? null), 'vector-network-placeholder-reason-count');
    $vectorNetworkDiagnostics = array_values(array_filter(
        $vectorDataResult['diagnostics'] ?? array(),
        static fn (array $diagnostic): bool => 'unsupported_vector_network_blob' === ($diagnostic['code'] ?? null)
    ));
    $assert(2 === count($vectorNetworkDiagnostics), 'vector-network-repeated-diagnostics-compacted');
    $assert(2 === ($vectorNetworkDiagnostic['context']['occurrence_count'] ?? null), 'vector-network-diagnostic-occurrence-count');
    $assert(2 === ($vectorNetworkDiagnostic['context']['affected_node_count'] ?? null), 'vector-network-diagnostic-affected-node-count');
    $assert(array('vector:data-malformed', 'vector:data-painted-fallback') === ($vectorNetworkDiagnostic['context']['sample_node_ids'] ?? null), 'vector-network-diagnostic-sample-nodes');
    $assert(array('1') === ($vectorNetworkDiagnostic['context']['sample_blob_refs'] ?? null), 'vector-network-diagnostic-sample-blob-refs');

    // Compact vectorNetwork blob: straight segments can be encoded as just
    // start/end uint32 pairs. This is a generic .fig layout, not a named-icon
    // signature, and should render deterministically with the region fill rule.
    $compactNetworkBlob = pack('V3', 4, 4, 1);
    foreach ( array(array(0.0, 0.0), array(12.0, 0.0), array(12.0, 8.0), array(0.0, 8.0)) as $point ) {
        $compactNetworkBlob .= pack('V', 0) . pack('g', $point[0]) . pack('g', $point[1]) . pack('V2', 0, 0);
    }
    foreach ( array(array(0, 1), array(1, 2), array(2, 3), array(3, 0)) as $segment ) {
        $compactNetworkBlob .= pack('V2', $segment[0], $segment[1]);
    }
    $compactNetworkBlob .= pack('V3', 4, 1, 0);
    foreach ( array(array(0, 0), array(1, 0), array(2, 0), array(3, 0)) as $entry ) {
        $compactNetworkBlob .= pack('V2', $entry[0], $entry[1]);
    }

    $compactNetworkResult = blocks_engine_figma_transformer_transform_scenegraph(array(
        'name'  => 'Compact Vector Network Fixture',
        'blobs' => array(array('bytes' => $compactNetworkBlob)),
        'nodes' => array(
            array(
                'id'         => 'vector:compact-network',
                'type'       => 'VECTOR',
                'name'       => 'Compact Network',
                'width'      => 12,
                'height'     => 8,
                'fillPaints' => array(array('type' => 'SOLID', 'color' => array('r' => 0.2, 'g' => 0.3, 'b' => 0.4))),
                'vectorData' => array('vectorNetworkBlob' => 0),
            ),
        ),
    ));
    $compactNetworkHtml = $fileContent($compactNetworkResult, 'index.html');
    $compactNetworkDiagnosticCodes = array_map(
        static fn (array $diagnostic): string => (string) ($diagnostic['code'] ?? ''),
        $compactNetworkResult['diagnostics'] ?? array()
    );
    $compactNetworkVectors = $compactNetworkResult['source_reports']['figma']['html']['transform_diagnostics']['vectors'] ?? array();
    $assert(str_contains($compactNetworkHtml, 'data-figma-node-id="vector:compact-network"') && str_contains($compactNetworkHtml, 'd="M0 0L12 0 12 8 0 8 0 0Z"'), 'compact-vector-network-renders-path');
    $assert(str_contains($compactNetworkHtml, 'fill="#334d66"') && str_contains($compactNetworkHtml, 'fill-rule="evenodd"'), 'compact-vector-network-preserves-paint-and-fill-rule');
    $assert(! str_contains($compactNetworkHtml, 'data-figma-unsupported-vector="true"'), 'compact-vector-network-not-placeholder');
    $assert(! in_array('unsupported_vector_network_blob', $compactNetworkDiagnosticCodes, true), 'compact-vector-network-no-unsupported-diagnostic');
    $assert(1 === (int) ($compactNetworkVectors['vector_network_decoded'] ?? 0), 'compact-vector-network-counted-decoded');

    // General vectorNetwork decode: 3 vertices, 3 segments (one carrying bezier
    // tangents), one NONZERO region. Stride is 24 bytes (tangent-bearing), so the
    // blob is rejected by the legacy exact-match decoders and handled by the new
    // general decoder, emitting a real cubic-curve path rather than a placeholder.
    $curvedNetworkVertices = array(array(0.0, 0.0), array(10.0, 0.0), array(10.0, 10.0));
    $curvedNetworkSegments = array(
        array('start' => 0, 'end' => 1, 'ts' => array(0.0, 0.0), 'te' => array(0.0, 0.0)),
        array('start' => 1, 'end' => 2, 'ts' => array(2.0, 0.0), 'te' => array(0.0, -2.0)),
        array('start' => 2, 'end' => 0, 'ts' => array(0.0, 0.0), 'te' => array(0.0, 0.0)),
    );
    $curvedNetworkEntries = array(array(0, 0), array(1, 0), array(2, 0));
    $curvedNetworkBlob = pack('V3', count($curvedNetworkVertices), count($curvedNetworkSegments), 1);
    foreach ( $curvedNetworkVertices as $point ) {
        $curvedNetworkBlob .= pack('V', 0) . pack('g', $point[0]) . pack('g', $point[1]) . pack('V2', 0, 0);
    }
    foreach ( $curvedNetworkSegments as $segment ) {
        $curvedNetworkBlob .= pack('V', $segment['start']) . pack('g', $segment['ts'][0]) . pack('g', $segment['ts'][1])
            . pack('V', $segment['end']) . pack('g', $segment['te'][0]) . pack('g', $segment['te'][1]);
    }
    $curvedNetworkBlob .= pack('V3', count($curvedNetworkEntries), 0, 0);
    foreach ( $curvedNetworkEntries as $entry ) {
        $curvedNetworkBlob .= pack('V2', $entry[0], $entry[1]);
    }
    
    $curvedNetworkResult = blocks_engine_figma_transformer_transform_scenegraph(array(
        'name'  => 'Curved Vector Network Fixture',
        'blobs' => array(array('bytes' => $curvedNetworkBlob)),
        'nodes' => array(
            array(
                'id'         => 'vector:curved-network',
                'type'       => 'VECTOR',
                'name'       => 'Curved Network',
                'width'      => 10,
                'height'     => 10,
                'fillPaints' => array(array('type' => 'SOLID', 'color' => array('r' => 0.1, 'g' => 0.2, 'b' => 0.3))),
                'vectorData' => array('vectorNetworkBlob' => 0),
            ),
        ),
    ));
    $curvedNetworkHtml = $fileContent($curvedNetworkResult, 'index.html');
    $curvedNetworkVectors = $curvedNetworkResult['source_reports']['figma']['html']['transform_diagnostics']['vectors'] ?? array();
    $curvedNetworkSummary = $curvedNetworkResult['source_reports']['figma']['html']['transform_diagnostics']['artifact_quality']['summary'] ?? array();
    $assert(str_contains($curvedNetworkHtml, 'data-figma-node-id="vector:curved-network"') && str_contains($curvedNetworkHtml, 'data-figma-vector="true"'), 'curved-network-renders-svg');
    $assert(str_contains($curvedNetworkHtml, 'd="M0 0L10 0C12 0 10 8 10 10L0 0Z"'), 'curved-network-renders-cubic-path');
    $assert(! str_contains($curvedNetworkHtml, 'data-figma-unsupported-vector="true"'), 'curved-network-not-placeholder');
    $assert(1 === (int) ($curvedNetworkVectors['rendered_paths'] ?? 0), 'curved-network-counted-rendered');
    $assert(0 === (int) ($curvedNetworkVectors['placeholders'] ?? 0), 'curved-network-no-placeholder-count');
    $assert(1 === (int) ($curvedNetworkVectors['vector_network_decoded'] ?? 0), 'curved-network-network-decoded-count');
    $assert(1 === (int) ($curvedNetworkVectors['decode_coverage']['vector_network_decoded'] ?? 0), 'curved-network-coverage-network-decoded');
    $assert(1 === (int) ($curvedNetworkSummary['vector_network_decoded'] ?? 0), 'curved-network-summary-network-decoded');
    // Summary rollup reflects rendered vectors instead of only externalized SVG files.
    $assert(1 === (int) ($curvedNetworkSummary['generated_svg_count'] ?? -1), 'curved-network-summary-generated-svg-count');
    $assert(1 === (int) ($curvedNetworkSummary['vector_decoded_to_svg'] ?? -1), 'curved-network-summary-decoded-to-svg');
    $assert(0 === (int) ($curvedNetworkSummary['externalized_svg_asset_count'] ?? -1), 'curved-network-summary-externalized-count');
    
    // Boolean operation: compose two child vector paths into one SVG. The default
    // (UNION) overlays both child paths; the parent is no longer a placeholder.
    $booleanOperationResult = blocks_engine_figma_transformer_transform_scenegraph(array(
        'name'  => 'Boolean Operation Fixture',
        'nodes' => array(
            array(
                'id'       => 'bool:union',
                'type'     => 'BOOLEAN_OPERATION',
                'name'     => 'Union Icon',
                'width'    => 20,
                'height'   => 20,
                'children' => array(
                    array(
                        'id'         => 'bool:child-a',
                        'type'       => 'VECTOR',
                        'name'       => 'A',
                        'width'      => 10,
                        'height'     => 10,
                        'fillPaints' => array(array('type' => 'SOLID', 'color' => array('r' => 1, 'g' => 0, 'b' => 0))),
                        'pathData'   => 'M0 0L10 0L10 10L0 10Z',
                    ),
                    array(
                        'id'         => 'bool:child-b',
                        'type'       => 'VECTOR',
                        'name'       => 'B',
                        'width'      => 10,
                        'height'     => 10,
                        'fillPaints' => array(array('type' => 'SOLID', 'color' => array('r' => 0, 'g' => 0, 'b' => 1))),
                        'pathData'   => 'M5 5L15 5L15 15L5 15Z',
                    ),
                ),
            ),
        ),
    ));
    $booleanOperationHtml = $fileContent($booleanOperationResult, 'index.html');
    $booleanOperationVectors = $booleanOperationResult['source_reports']['figma']['html']['transform_diagnostics']['vectors'] ?? array();
    $assert(str_contains($booleanOperationHtml, 'data-figma-boolean-operation="union"'), 'boolean-operation-marks-union');
    $assert(str_contains($booleanOperationHtml, 'd="M0 0L10 0 10 10 0 10Z" fill="#ff0000"'), 'boolean-operation-includes-child-a-path');
    $assert(str_contains($booleanOperationHtml, 'd="M5 5L15 5 15 15 5 15Z" fill="#0000ff"'), 'boolean-operation-includes-child-b-path');
    $assert(! str_contains($booleanOperationHtml, 'data-figma-unsupported-vector="true"'), 'boolean-operation-not-placeholder');
    $assert(1 === (int) ($booleanOperationVectors['boolean_operations_composed'] ?? 0), 'boolean-operation-composed-count');
    $assert(1 === (int) ($booleanOperationVectors['rendered_paths'] ?? 0), 'boolean-operation-rendered-count');
    $assert(0 === (int) ($booleanOperationVectors['placeholders'] ?? 0), 'boolean-operation-no-placeholder');
    
    // Detailed logo-style booleans can carry an explicit parent vector path plus
    // child vectors with better per-glyph/per-shape geometry. UNION should prefer
    // the child composition rather than collapsing everything to the parent path.
    $booleanUnionWithParentPathResult = blocks_engine_figma_transformer_transform_scenegraph(array(
        'name'  => 'Boolean Union Parent Path Fixture',
        'nodes' => array(
            array(
                'id'               => 'bool:union-parent-path',
                'type'             => 'BOOLEAN_OPERATION',
                'name'             => 'Logo Union',
                'width'            => 30,
                'height'           => 20,
                'booleanOperation' => 'UNION',
                'pathData'         => 'M0 0L30 0L30 20L0 20Z',
                'fillPaints'       => array(array('type' => 'SOLID', 'color' => array('r' => 0, 'g' => 0, 'b' => 0))),
                'children'         => array(
                    array('id' => 'bool:logo-icon', 'type' => 'VECTOR', 'name' => 'Icon', 'width' => 10, 'height' => 10, 'pathData' => 'M1 1L9 1L9 9L1 9Z'),
                    array('id' => 'bool:logo-wordmark', 'type' => 'VECTOR', 'name' => 'Wordmark', 'width' => 14, 'height' => 6, 'x' => 12, 'y' => 4, 'pathData' => 'M12 4L26 4L26 10L12 10Z'),
                ),
            ),
        ),
    ));
    $booleanUnionWithParentPathHtml = $fileContent($booleanUnionWithParentPathResult, 'index.html');
    $assert(str_contains($booleanUnionWithParentPathHtml, 'data-figma-boolean-operation="union"'), 'boolean-union-parent-path-marks-union');
    $assert(str_contains($booleanUnionWithParentPathHtml, 'd="M1 1L9 1 9 9 1 9Z"'), 'boolean-union-parent-path-includes-child-icon');
    $assert(str_contains($booleanUnionWithParentPathHtml, 'd="M12 4L26 4 26 10 12 10Z"'), 'boolean-union-parent-path-includes-child-wordmark');
    $assert(! str_contains($booleanUnionWithParentPathHtml, 'd="M0 0L30 0 30 20 0 20Z"'), 'boolean-union-parent-path-skips-collapsed-parent');

    $booleanUnionTransformOffsetResult = blocks_engine_figma_transformer_transform_scenegraph(array(
        'name'  => 'Boolean Union Transform Offset Fixture',
        'nodes' => array(
            array(
                'id'       => 'bool:union-transform-offset',
                'type'     => 'BOOLEAN_OPERATION',
                'name'     => 'Logo Composition',
                'width'    => 40,
                'height'   => 16,
                'children' => array(
                    array(
                        'id'       => 'bool:transform-offset-icon',
                        'type'     => 'VECTOR',
                        'name'     => 'Icon',
                        'width'    => 10,
                        'height'   => 10,
                        'pathData' => 'M0 0L10 0L10 10L0 10Z',
                    ),
                    array(
                        'id'        => 'bool:transform-offset-wordmark',
                        'type'      => 'VECTOR',
                        'name'      => 'Wordmark',
                        'width'     => 18,
                        'height'    => 6,
                        'transform' => array('m00' => 1, 'm01' => 0, 'm02' => 14, 'm10' => 0, 'm11' => 1, 'm12' => 5),
                        'pathData'  => 'M0 0L18 0L18 6L0 6Z',
                    ),
                ),
            ),
        ),
    ));
    $booleanUnionTransformOffsetHtml = $fileContent($booleanUnionTransformOffsetResult, 'index.html');
    $assert(str_contains($booleanUnionTransformOffsetHtml, '<g transform="translate(14 5)"><path d="M0 0L18 0 18 6 0 6Z"'), 'boolean-union-transform-offset-preserves-child-position');

    // Boolean SUBTRACT over children sharing the operation origin approximates
    // hole-cutting with a single fill-rule:evenodd path.
    $booleanSubtractResult = blocks_engine_figma_transformer_transform_scenegraph(array(
        'name'  => 'Boolean Subtract Fixture',
        'nodes' => array(
            array(
                'id'              => 'bool:subtract',
                'type'            => 'BOOLEAN_OPERATION',
                'name'            => 'Subtract Icon',
                'width'           => 20,
                'height'          => 20,
                'booleanOperation' => 'SUBTRACT',
                'fillPaints'      => array(array('type' => 'SOLID', 'color' => array('r' => 0, 'g' => 0, 'b' => 0))),
                'children'        => array(
                    array('id' => 'bool:outer', 'type' => 'VECTOR', 'name' => 'Outer', 'width' => 20, 'height' => 20, 'pathData' => 'M0 0L20 0L20 20L0 20Z'),
                    array('id' => 'bool:inner', 'type' => 'VECTOR', 'name' => 'Inner', 'width' => 10, 'height' => 10, 'pathData' => 'M5 5L15 5L15 15L5 15Z'),
                ),
            ),
        ),
    ));
    $booleanSubtractHtml = $fileContent($booleanSubtractResult, 'index.html');
    $assert(str_contains($booleanSubtractHtml, 'data-figma-boolean-operation="subtract"'), 'boolean-subtract-marks-subtract');
    $assert(str_contains($booleanSubtractHtml, 'd="M5 5L15 5 15 15 5 15Z M0 0L20 0 20 20 0 20Z" fill="#000000" fill-rule="evenodd"'), 'boolean-subtract-evenodd-composite');
    
    $multiPageVectorPlaceholderResult = blocks_engine_figma_transformer_transform_scenegraph(array(
        'name'  => 'Multi Page Vector Placeholder Fixture',
        'nodes' => array(
            array(
                'id'       => 'page:vector-placeholder',
                'type'     => 'CANVAS',
                'name'     => 'Vector Placeholder Pages',
                'children' => array(
                    array('id' => 'frame:vector-home', 'type' => 'FRAME', 'name' => 'Vector Home', 'width' => 320, 'height' => 240, 'children' => array()),
                    array('id' => 'frame:vector-about', 'type' => 'FRAME', 'name' => 'Vector About', 'width' => 320, 'height' => 240, 'children' => array(
                        array('id' => 'vector:multi-page-placeholder', 'type' => 'VECTOR', 'name' => 'Multi Page Placeholder', 'width' => 16, 'height' => 16, 'pathData' => 'M 0 0' . str_repeat(' L 1 1', 4000) . ' Z'),
                    )),
                ),
            ),
        ),
    ), array(
        'multi_page' => true,
        'frame_ids' => array('frame:vector-home', 'frame:vector-about'),
        'entry_frame_id' => 'frame:vector-home',
    ));
    $multiPageVectorPlaceholderDiagnostics = $multiPageVectorPlaceholderResult['source_reports']['figma']['html']['transform_diagnostics']['vectors'] ?? array();
    $assert(1 === ($multiPageVectorPlaceholderDiagnostics['placeholder_reasons']['oversized_path_data'] ?? null), 'multi-page-vector-placeholder-reason-aggregated');
    
    $nonRectVectorNetworkDiagnostic = null;
    foreach ( $vectorNetworkDiagnostics as $diagnostic ) {
        if ( array(4, 4, 1) === ($diagnostic['context']['network_counts'] ?? null) ) {
            $nonRectVectorNetworkDiagnostic = $diagnostic;
            break;
        }
    }
    $assert(true === ($nonRectVectorNetworkDiagnostic['context']['single_region_loop_candidate'] ?? null), 'vector-network-single-region-candidate-diagnostic');
    $assert(array('vertex_stride' => 20, 'segment_stride' => 16, 'region_bytes' => 44) === ($nonRectVectorNetworkDiagnostic['context']['candidate_layout'] ?? null), 'vector-network-candidate-layout-diagnostic');
    $assert(array(array(0.0, 0.0), array(12.0, 0.0), array(8.0, 6.0), array(0.0, 6.0)) === ($nonRectVectorNetworkDiagnostic['context']['candidate_vertex_points_sample'] ?? null), 'vector-network-candidate-point-sample');
    $assert('Decode only after segment endpoints and region winding/order are validated as one closed non-branching loop.' === ($nonRectVectorNetworkDiagnostic['context']['candidate_decoder_requirement'] ?? null), 'vector-network-candidate-requirement');
    
    $loopDecoderResult = blocks_engine_figma_transformer_transform_scenegraph(array(
        'name'  => 'Vector Network Loop Decoder Fixture',
        'blobs' => array(
            array('bytes' => $singleLoopNetworkBlob(
                array(array(0.0, 0.0), array(12.0, 0.0), array(8.0, 6.0), array(0.0, 6.0)),
                array(array(0, 1), array(1, 2), array(2, 3), array(3, 0)),
                array(array(0, 0), array(1, 0), array(2, 0), array(3, 0))
            )),
            array('bytes' => $singleLoopNetworkBlob(
                array(array(0.0, 0.0), array(12.0, 0.0), array(8.0, 6.0), array(0.0, 6.0)),
                array(array(0, 1), array(1, 2), array(1, 3), array(3, 0)),
                array(array(0, 0), array(1, 0), array(2, 0), array(3, 0))
            )),
            array('bytes' => $singleLoopNetworkBlob(
                array(array(0.0, 0.0), array(12.0, 0.0), array(8.0, 6.0), array(0.0, 6.0)),
                array(array(0, 1), array(1, 2), array(2, 3), array(3, 0)),
                array(array(0, 0), array(1, 0), array(2, 0), array(3, 1))
            )),
            array('bytes' => $singleLoopNetworkBlob(
                array(array(0.0, 0.0), array(12.0, 0.0), array(8.0, 6.0), array(0.0, 6.0)),
                array(array(0, 1), array(1, 2), array(2, 3), array(3, 0)),
                array(array(0, 0), array(1, 0), array(2, 0), array(3, 0)),
                3
            )),
        ),
        'nodes' => array(
            array('id' => 'vector:loop-supported', 'type' => 'VECTOR', 'name' => 'Supported Loop', 'width' => 12, 'height' => 6, 'fillPaints' => array(array('type' => 'SOLID', 'color' => array('r' => 0.1, 'g' => 0.2, 'b' => 0.3))), 'vectorData' => array('vectorNetworkBlob' => 0)),
            array('id' => 'vector:loop-branch', 'type' => 'VECTOR', 'name' => 'Branched Loop', 'width' => 12, 'height' => 6, 'vectorData' => array('vectorNetworkBlob' => 1)),
            array('id' => 'vector:loop-open-order', 'type' => 'VECTOR', 'name' => 'Open Region Order', 'width' => 12, 'height' => 6, 'vectorData' => array('vectorNetworkBlob' => 2)),
            array('id' => 'vector:loop-malformed-region', 'type' => 'VECTOR', 'name' => 'Malformed Region', 'width' => 12, 'height' => 6, 'vectorData' => array('vectorNetworkBlob' => 3)),
        ),
    ));
    $loopDecoderHtml = $fileContent($loopDecoderResult, 'index.html');
    $loopDecoderDiagnosticCodes = array_map(
        static fn (array $diagnostic): string => (string) ($diagnostic['code'] ?? ''),
        $loopDecoderResult['diagnostics'] ?? array()
    );
    $assert(str_contains($loopDecoderHtml, 'data-figma-node-id="vector:loop-supported"') && str_contains($loopDecoderHtml, 'd="M0 0L12 0 8 6 0 6Z"') && str_contains($loopDecoderHtml, 'fill="#1a334d"'), 'vector-network-single-loop-renders-path');
    $assert(str_contains($loopDecoderHtml, 'data-figma-node-id="vector:loop-branch"') && str_contains($loopDecoderHtml, 'data-figma-unsupported-vector="true"'), 'vector-network-branch-keeps-placeholder');
    $assert(str_contains($loopDecoderHtml, 'data-figma-node-id="vector:loop-open-order"') && str_contains($loopDecoderHtml, 'data-figma-unsupported-vector="true"'), 'vector-network-open-order-keeps-placeholder');
    $assert(str_contains($loopDecoderHtml, 'data-figma-node-id="vector:loop-malformed-region"') && str_contains($loopDecoderHtml, 'data-figma-unsupported-vector="true"'), 'vector-network-malformed-region-keeps-placeholder');
    $assert(in_array('unsupported_vector_network_blob', $loopDecoderDiagnosticCodes, true), 'vector-network-unsupported-loop-topology-diagnostic');
    
    $zeroHeightSeparatorResult = blocks_engine_figma_transformer_transform_scenegraph(array(
        'name'  => 'Zero Height Separator Fixture',
        'blobs' => array(array('bytes' => "\xff")),
        'nodes' => array(
            array(
                'id'           => 'vector:zero-height-separator',
                'type'         => 'VECTOR',
                'name'         => 'Wide Zero Height Separator',
                'width'        => 1004,
                'height'       => 0,
                'strokeWeight' => 4,
                'strokePaints' => array(array('type' => 'SOLID', 'color' => array('r' => 0.75, 'g' => 0.75, 'b' => 0.75))),
                'vectorData'   => array('vectorNetworkBlob' => 0),
            ),
        ),
    ));
    $zeroHeightSeparatorHtml = $fileContent($zeroHeightSeparatorResult, 'index.html');
    $zeroHeightSeparatorCss = $fileContent($zeroHeightSeparatorResult, 'style.css');
    $zeroHeightSeparatorDiagnosticCodes = array_map(
        static fn (array $diagnostic): string => (string) ($diagnostic['code'] ?? ''),
        $zeroHeightSeparatorResult['diagnostics'] ?? array()
    );
    $assert(str_contains($zeroHeightSeparatorHtml, 'data-figma-node-id="vector:zero-height-separator"') && str_contains($zeroHeightSeparatorHtml, 'data-figma-vector="true"'), 'zero-height-separator-renders-vector');
    $assert(str_contains($zeroHeightSeparatorHtml, '<line x1="0" y1="2" x2="1004" y2="2" stroke="#bfbfbf" stroke-width="4"/>'), 'zero-height-separator-renders-line');
    $assert(! str_contains($zeroHeightSeparatorHtml, 'data-figma-unsupported-vector="true"'), 'zero-height-separator-not-placeholder');
    $assert(str_contains($zeroHeightSeparatorCss, '.figma-node-vector-zero-height-separator-wide-zero-height-separator{width:1004px;height:4px'), 'zero-height-separator-css-bounded-height');
    $assert(in_array('unsupported_vector_network_blob', $zeroHeightSeparatorDiagnosticCodes, true), 'zero-height-separator-keeps-network-diagnostic');
    
    $nearZeroContainerResult = blocks_engine_figma_transformer_transform_scenegraph(array(
        'name'  => 'Near Zero Container Fixture',
        'nodes' => array(
            array(
                'id'                => 'near-zero:container',
                'type'              => 'FRAME',
                'name'              => 'Decorative zero-height wrapper',
                'width'             => 600,
                'height'            => 0.0002,
                'layoutMode'        => 'VERTICAL',
                'relativeTransform' => array(
                    array(0.8, -0.6, 0),
                    array(0.6, 0.8, 0),
                ),
                'children'          => array(
                    array(
                        'id'     => 'near-zero:child',
                        'type'   => 'RECTANGLE',
                        'name'   => 'Decorative child',
                        'width'  => 600,
                        'height' => 320,
                    ),
                ),
            ),
        ),
    ));
    $nearZeroContainerCss = $fileContent($nearZeroContainerResult, 'style.css');
    $assert(str_contains($nearZeroContainerCss, '.figma-node-near-zero-container-decorative-zero-height-wrapper{width:600px;height:0px;position:relative;display:flex;flex-direction:column}'), 'near-zero-container-keeps-zero-height-layout');
    $assert(! str_contains($nearZeroContainerCss, '.figma-node-near-zero-container-decorative-zero-height-wrapper{width:600px;height:0px;position:relative;transform:'), 'near-zero-container-suppresses-transform-bounds-inflation');
    $assert(str_contains($nearZeroContainerCss, '.figma-node-near-zero-child-decorative-child{width:600px;height:320px;position:absolute;flex-shrink:0}'), 'near-zero-container-keeps-child-rendering');
    
    $agenticChevronLeftPrefix = hex2bin('0600000006000000010000000000000000000041000080410000000000000000');
    $agenticChevronRightPrefix = hex2bin('06000000060000000100000000000000f4fdb43f0000804100000000be9f1641');
    $agenticChevronWrongCountsPrefix = hex2bin('0600000005000000010000000000000000000041000080410000000000000000');
    $agenticChevronUnknownPrefix = hex2bin('060000000600000001000000ffffffff00000041000080410000000000000000');
    $agenticChevronResult = blocks_engine_figma_transformer_transform_scenegraph(array(
        'name'  => 'Agentic Chevron Fixture',
        'blobs' => array(
            array('bytes' => str_pad(false === $agenticChevronLeftPrefix ? '' : $agenticChevronLeftPrefix, 288, "\0")),
            array('bytes' => str_pad(false === $agenticChevronRightPrefix ? '' : $agenticChevronRightPrefix, 288, "\0")),
            array('bytes' => str_pad(false === $agenticChevronLeftPrefix ? '' : $agenticChevronLeftPrefix, 287, "\0")),
            array('bytes' => str_pad(false === $agenticChevronWrongCountsPrefix ? '' : $agenticChevronWrongCountsPrefix, 288, "\0")),
            array('bytes' => str_pad(false === $agenticChevronUnknownPrefix ? '' : $agenticChevronUnknownPrefix, 288, "\0")),
        ),
        'nodes' => array(
            array('id' => 'chevron:left', 'type' => 'VECTOR', 'name' => 'Gridicon / gridicons-chevron-left', 'width' => 10.414, 'height' => 17, 'vectorData' => array('vectorNetworkBlob' => 0)),
            array('id' => 'chevron:right', 'type' => 'VECTOR', 'name' => 'Gridicon / gridicons-chevron-right', 'width' => 10.414, 'height' => 17, 'vectorData' => array('vectorNetworkBlob' => 1)),
            array('id' => 'chevron:bad-length', 'type' => 'VECTOR', 'name' => 'Bad chevron length', 'width' => 10, 'height' => 10, 'vectorData' => array('vectorNetworkBlob' => 2)),
            array('id' => 'chevron:bad-counts', 'type' => 'VECTOR', 'name' => 'Bad chevron counts', 'width' => 10, 'height' => 10, 'vectorData' => array('vectorNetworkBlob' => 3)),
            array('id' => 'chevron:unknown-signature', 'type' => 'VECTOR', 'name' => 'Unknown chevron signature', 'width' => 10, 'height' => 10, 'vectorData' => array('vectorNetworkBlob' => 4)),
        ),
    ));
    $agenticChevronHtml = $fileContent($agenticChevronResult, 'index.html');
    $agenticChevronDiagnosticCodes = array_map(
        static fn (array $diagnostic): string => (string) ($diagnostic['code'] ?? ''),
        $agenticChevronResult['diagnostics'] ?? array()
    );
    $assert(str_contains($agenticChevronHtml, 'data-figma-node-id="chevron:left"') && str_contains($agenticChevronHtml, 'M8 16L0 8 8 0 9.414 1.414 2.828 8 9.414 14.586 8 16Z'), 'agentic-chevron-left-renders');
    $assert(str_contains($agenticChevronHtml, 'data-figma-node-id="chevron:right"') && str_contains($agenticChevronHtml, 'M1.414 16L9.414 8 1.414 0 0 1.414 6.586 8 0 14.586 1.414 16Z'), 'agentic-chevron-right-renders');
    $assert(str_contains($agenticChevronHtml, 'data-figma-node-id="chevron:bad-length"') && str_contains($agenticChevronHtml, 'data-figma-unsupported-vector="true"'), 'agentic-chevron-bad-length-placeholder');
    $assert(str_contains($agenticChevronHtml, 'data-figma-node-id="chevron:bad-counts"') && str_contains($agenticChevronHtml, 'data-figma-unsupported-vector="true"'), 'agentic-chevron-bad-counts-placeholder');
    $assert(str_contains($agenticChevronHtml, 'data-figma-node-id="chevron:unknown-signature"') && str_contains($agenticChevronHtml, 'data-figma-unsupported-vector="true"'), 'agentic-chevron-unknown-signature-placeholder');
    $assert(in_array('unsupported_vector_network_blob', $agenticChevronDiagnosticCodes, true), 'agentic-chevron-guarded-failures-diagnosed');
    
    $vectorChildFallbackResult = blocks_engine_figma_transformer_transform_scenegraph(array(
        'name'  => 'Vector Child Fallback Fixture',
        'blobs' => array(array('bytes' => "\xff")),
        'nodes' => array(
            array(
                'id'         => 'boolean:child-fallback',
                'type'       => 'BOOLEAN_OPERATION',
                'name'       => 'Boolean With Child Fallback',
                'width'      => 20,
                'height'     => 20,
                'vectorData' => array('vectorNetworkBlob' => 0),
                'children'   => array(
                    array(
                        'id'         => 'boolean:child-fallback-ellipse',
                        'type'       => 'ELLIPSE',
                        'name'       => 'Fallback Ellipse',
                        'width'      => 20,
                        'height'     => 20,
                        'fillPaints' => array(array('type' => 'SOLID', 'color' => array('r' => 0, 'g' => 0, 'b' => 1))),
                    ),
                ),
            ),
        ),
    ));
    $vectorChildFallbackHtml = $fileContent($vectorChildFallbackResult, 'index.html');
    $vectorChildFallbackDiagnosticCodes = array_map(
        static fn (array $diagnostic): string => (string) ($diagnostic['code'] ?? ''),
        $vectorChildFallbackResult['diagnostics'] ?? array()
    );
    $assert(str_contains($vectorChildFallbackHtml, 'data-figma-node-id="boolean:child-fallback"'), 'vector-child-fallback-parent-renders');
    $assert(str_contains($vectorChildFallbackHtml, 'data-figma-node-id="boolean:child-fallback-ellipse"') && str_contains($vectorChildFallbackHtml, '<ellipse '), 'vector-child-fallback-child-renders');
    $assert(! str_contains($vectorChildFallbackHtml, 'data-figma-unsupported-vector="true"'), 'vector-child-fallback-not-placeholder');
    $assert(in_array('unsupported_vector_network_blob', $vectorChildFallbackDiagnosticCodes, true), 'vector-child-fallback-network-diagnostic-kept');
    $assert(! in_array('unsupported_vector_node_placeholder', $vectorChildFallbackDiagnosticCodes, true), 'vector-child-fallback-no-placeholder-diagnostic');

    $layeredLogoResult = blocks_engine_figma_transformer_transform_scenegraph(array(
        'name'  => 'Layered Vector Logo Fixture',
        'nodes' => array(
            array(
                'id'       => 'logo:group',
                'type'     => 'GROUP',
                'name'     => 'Newsletter Logo',
                'x'        => 100,
                'y'        => 50,
                'width'    => 40,
                'height'   => 24,
                'children' => array(
                    array(
                        'id'                 => 'logo:back-leaf',
                        'type'               => 'VECTOR',
                        'name'               => 'Back Leaf',
                        'x'                  => 102,
                        'y'                  => 53,
                        'width'              => 14,
                        'height'             => 12,
                        'fillPaints'         => array(array('type' => 'SOLID', 'color' => array('r' => 0.1176470588, 'g' => 0.4862745098, 'b' => 0.2352941176))),
                        'figma_vector_paths' => array(array('data' => 'M0 0L14 0L14 12L0 12Z')),
                    ),
                    array(
                        'id'       => 'logo:nested-mark',
                        'type'     => 'GROUP',
                        'name'     => 'Nested Mark',
                        'x'        => 118,
                        'y'        => 50,
                        'width'    => 18,
                        'height'   => 18,
                        'children' => array(
                            array(
                                'id'                 => 'logo:front-leaf',
                                'type'               => 'VECTOR',
                                'name'               => 'Front Leaf',
                                'x'                  => 118,
                                'y'                  => 50,
                                'width'              => 18,
                                'height'             => 18,
                                'strokeWeight'       => 2,
                                'strokePaints'       => array(array('type' => 'SOLID', 'color' => array('r' => 0.0588235294, 'g' => 0.2823529412, 'b' => 0.137254902))),
                                'figma_vector_paths' => array(array('data' => 'M1 17L17 1')),
                            ),
                            array(
                                'id'                 => 'logo:hidden-leaf',
                                'type'               => 'VECTOR',
                                'name'               => 'Hidden Leaf',
                                'visible'            => false,
                                'x'                  => 120,
                                'y'                  => 52,
                                'width'              => 8,
                                'height'             => 8,
                                'figma_vector_paths' => array(array('data' => 'M0 0L8 8')),
                            ),
                        ),
                    ),
                ),
            ),
        ),
    ));
    $layeredLogoHtml = $fileContent($layeredLogoResult, 'index.html');
    $layeredLogoCss = $fileContent($layeredLogoResult, 'style.css');
    $assert(str_contains($layeredLogoHtml, 'data-figma-node-id="logo:group"') && str_contains($layeredLogoHtml, 'data-figma-vector-composition="group"'), 'layered-vector-logo-composes-container-svg');
    $assert(str_contains($layeredLogoHtml, 'viewBox="0 0 40 24"'), 'layered-vector-logo-uses-container-viewbox');
    $assert(str_contains($layeredLogoHtml, 'transform="translate(2 3)"') && str_contains($layeredLogoHtml, 'transform="translate(18 0)"'), 'layered-vector-logo-preserves-child-offsets');
    $assert(str_contains($layeredLogoHtml, 'fill="#1e7c3c"') && str_contains($layeredLogoHtml, 'stroke="#0f4823"'), 'layered-vector-logo-preserves-child-paints');
    $assert(! str_contains($layeredLogoHtml, 'data-figma-node-id="logo:back-leaf"') && ! str_contains($layeredLogoHtml, 'data-figma-node-id="logo:hidden-leaf"'), 'layered-vector-logo-does-not-duplicate-child-html');
    $assert(! str_contains($layeredLogoCss, '.figma-node-logo-back-leaf-back-leaf'), 'layered-vector-logo-css-omits-child-layer-rules');
}
