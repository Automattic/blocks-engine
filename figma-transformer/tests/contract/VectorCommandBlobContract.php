<?php

declare(strict_types=1);

/**
 * @param callable(bool, string): void $assert
 */
function blocks_engine_figma_transformer_run_vector_command_blob_contract(callable $assert, string $oversizedCommandBlob, string $longStrokeCommandBlob): void
{
    $oversizedCommandResult = blocks_engine_figma_transformer_transform_scenegraph(array(
        'name'  => 'Oversized Command Blob Fixture',
        'blobs' => array(array('bytes' => $oversizedCommandBlob)),
        'nodes' => array(
            array(
                'id'       => 'oversized:root',
                'type'     => 'FRAME',
                'name'     => 'Oversized command root',
                'width'    => 100,
                'height'   => 100,
                'children' => array(
                    array(
                        'id'           => 'oversized:vector',
                        'type'         => 'VECTOR',
                        'name'         => 'Oversized vector command blob',
                        'width'        => 10,
                        'height'       => 10,
                        'fillGeometry' => array(array('commandsBlob' => 0)),
                    ),
                ),
            ),
        ),
    ));
    $oversizedCommandDiagnosticCodes = array_map(
        static fn (array $diagnostic): string => (string) ($diagnostic['code'] ?? ''),
        $oversizedCommandResult['diagnostics'] ?? array()
    );
    $assert(in_array('unsupported_vector_command_blob', $oversizedCommandDiagnosticCodes, true), 'oversized-command-blob-diagnostic');
    $oversizedCommandTransformDiagnostics = $oversizedCommandResult['source_reports']['figma']['html']['transform_diagnostics'] ?? array();
    $assert(1 === ($oversizedCommandTransformDiagnostics['vectors']['placeholders'] ?? null), 'oversized-command-blob-placeholder');

    $longStrokeCommandResult = blocks_engine_figma_transformer_transform_scenegraph(array(
        'name'  => 'Long Stroke Command Blob Fixture',
        'blobs' => array(array('bytes' => $longStrokeCommandBlob)),
        'nodes' => array(
            array(
                'id'       => 'long-stroke:root',
                'type'     => 'FRAME',
                'name'     => 'Long stroke root',
                'width'    => 100,
                'height'   => 100,
                'children' => array(
                    array(
                        'id'             => 'long-stroke:vector',
                        'type'           => 'VECTOR',
                        'name'           => 'Long stroke vector command blob',
                        'width'          => 10,
                        'height'         => 10,
                        'strokeGeometry' => array(array('commandsBlob' => 0)),
                        'strokePaints'   => array(array('type' => 'SOLID', 'color' => array('r' => 0, 'g' => 0, 'b' => 0))),
                    ),
                ),
            ),
        ),
    ));
    $longStrokeCommandDiagnosticCodes = array_map(
        static fn (array $diagnostic): string => (string) ($diagnostic['code'] ?? ''),
        $longStrokeCommandResult['diagnostics'] ?? array()
    );
    $longStrokeCommandHtml = blocks_engine_figma_transformer_contract_file_content($longStrokeCommandResult, 'index.html');
    $longStrokeCommandTransformDiagnostics = $longStrokeCommandResult['source_reports']['figma']['html']['transform_diagnostics'] ?? array();
    $assert(! in_array('unsupported_vector_command_blob', $longStrokeCommandDiagnosticCodes, true), 'long-stroke-command-blob-decodes-without-diagnostic');
    $assert(str_contains($longStrokeCommandHtml, 'data-figma-node-id="long-stroke:vector"') && str_contains($longStrokeCommandHtml, 'data-figma-vector="true"'), 'long-stroke-command-blob-renders');
    $assert(0 === ($longStrokeCommandTransformDiagnostics['vectors']['placeholders'] ?? null), 'long-stroke-command-blob-no-placeholder');

    $lineFallbackResult = blocks_engine_figma_transformer_transform_scenegraph(array(
        'name'  => 'Twenty Twenty-Five LINE Fallback Fixture',
        'blobs' => array(array('bytes' => "\xff")),
        'nodes' => array(
            array(
                'id'             => '3137:3138',
                'type'           => 'LINE',
                'name'           => 'Separator',
                'width'          => 1340,
                'height'         => 0,
                'strokeWeight'   => 1,
                'strokeCap'      => 'ROUND',
                'strokeJoin'     => 'BEVEL',
                'dashPattern'    => array(4, 2),
                'strokePaints'   => array(array(
                    'type'    => 'SOLID',
                    'color'   => array('r' => 0.1176470588, 'g' => 0.1176470588, 'b' => 0.1176470588, 'a' => 1),
                    'opacity' => 0.2,
                )),
                'fillPaints'     => array(array('type' => 'SOLID', 'color' => array('r' => 1, 'g' => 0, 'b' => 0))),
                'strokeGeometry' => array(array('commandsBlob' => 0)),
            ),
        ),
    ));
    $lineFallbackHtml = blocks_engine_figma_transformer_contract_file_content($lineFallbackResult, 'index.html');
    $lineFallbackCss = blocks_engine_figma_transformer_contract_file_content($lineFallbackResult, 'style.css');
    $lineFallbackCodes = array_map(
        static fn (array $diagnostic): string => (string) ($diagnostic['code'] ?? ''),
        $lineFallbackResult['diagnostics'] ?? array()
    );
    $assert(str_contains($lineFallbackHtml, 'data-figma-node-id="3137:3138"') && str_contains($lineFallbackHtml, 'viewBox="0 0 1340 1"'), 'unsupported-line-command-blob-preserves-width-and-stroke-height');
    $assert(str_contains($lineFallbackHtml, '<line x1="0" y1="0.5" x2="1340" y2="0.5" stroke="rgba(30,30,30,0.2)" stroke-width="1" stroke-linecap="round" stroke-linejoin="bevel" stroke-dasharray="4 2"/>'), 'unsupported-line-command-blob-preserves-exact-stroke-semantics');
    $assert(! str_contains($lineFallbackHtml, 'fill="#ff0000"') && ! str_contains($lineFallbackCss, 'background:#ff0000'), 'unsupported-line-command-blob-does-not-invent-fill-semantics');
    $assert(in_array('unsupported_vector_command_blob', $lineFallbackCodes, true), 'unsupported-line-command-blob-keeps-conservative-diagnostic');
    $assert(0 === ($lineFallbackResult['source_reports']['figma']['html']['transform_diagnostics']['vectors']['placeholders'] ?? null), 'unsupported-line-command-blob-needs-no-placeholder');

    $malformedLineResult = blocks_engine_figma_transformer_transform_scenegraph(array(
        'name'  => 'Malformed LINE Fallback Fixture',
        'nodes' => array(
            array(
                'id'             => 'line:malformed',
                'type'           => 'LINE',
                'name'           => 'Malformed separator',
                'width'          => 1340,
                'height'         => 0,
                'strokeWeight'   => 1,
                'strokePaints'   => array(array('type' => 'SOLID', 'color' => array('r' => 'invalid'))),
                'strokeGeometry' => array(array('commandsBlob' => 99)),
            ),
        ),
    ));
    $malformedLineHtml = blocks_engine_figma_transformer_contract_file_content($malformedLineResult, 'index.html');
    $malformedLineCodes = array_map(
        static fn (array $diagnostic): string => (string) ($diagnostic['code'] ?? ''),
        $malformedLineResult['diagnostics'] ?? array()
    );
    $assert(str_contains($malformedLineHtml, 'data-figma-node-id="line:malformed"') && str_contains($malformedLineHtml, 'data-figma-unsupported-vector="true"'), 'malformed-line-without-stroke-uses-safe-placeholder');
    $assert(! str_contains($malformedLineHtml, 'stroke="currentColor"') && ! str_contains($malformedLineHtml, '<line '), 'malformed-line-does-not-invent-stroke-or-fill');
    $assert(in_array('figma_vector_command_blob_missing', $malformedLineCodes, true), 'malformed-line-keeps-missing-blob-diagnostic');

    $invisibleLineFallbackResult = blocks_engine_figma_transformer_transform_scenegraph(array(
        'name'  => 'Invisible LINE Fallback Fixture',
        'blobs' => array(array('bytes' => "\xff")),
        'nodes' => array(
            array(
                'id'             => 'line:invisible',
                'type'           => 'LINE',
                'name'           => 'Invisible separator',
                'width'          => 100,
                'height'         => 0,
                'strokePaints'   => array(array('type' => 'SOLID', 'visible' => false, 'color' => array('r' => 0, 'g' => 0, 'b' => 0))),
                'strokeGeometry' => array(array('commandsBlob' => 0)),
            ),
        ),
    ));
    $invisibleLineFallbackHtml = blocks_engine_figma_transformer_contract_file_content($invisibleLineFallbackResult, 'index.html');
    $assert(str_contains($invisibleLineFallbackHtml, 'data-figma-unsupported-vector="true"'), 'hidden-line-stroke-keeps-placeholder');
    $assert(! str_contains($invisibleLineFallbackHtml, '<line ') && ! str_contains($invisibleLineFallbackHtml, 'stroke="currentColor"'), 'hidden-line-stroke-does-not-become-visible');

    $transparentLineFallbackResult = blocks_engine_figma_transformer_transform_scenegraph(array(
        'name'  => 'Transparent LINE Fallback Fixture',
        'blobs' => array(array('bytes' => "\xff")),
        'nodes' => array(
            array(
                'id'             => 'line:transparent',
                'type'           => 'LINE',
                'name'           => 'Transparent separator',
                'width'          => 100,
                'height'         => 0,
                'strokePaints'   => array(array('type' => 'SOLID', 'opacity' => 0, 'color' => array('r' => 0, 'g' => 0, 'b' => 0))),
                'strokeGeometry' => array(array('commandsBlob' => 0)),
            ),
        ),
    ));
    $transparentLineFallbackHtml = blocks_engine_figma_transformer_contract_file_content($transparentLineFallbackResult, 'index.html');
    $assert(str_contains($transparentLineFallbackHtml, 'data-figma-unsupported-vector="true"'), 'zero-opacity-line-stroke-keeps-placeholder');
    $assert(! str_contains($transparentLineFallbackHtml, '<line ') && ! str_contains($transparentLineFallbackHtml, 'stroke="currentColor"'), 'zero-opacity-line-stroke-does-not-become-visible');

    $nonZeroLineFallbackResult = blocks_engine_figma_transformer_transform_scenegraph(array(
        'name'  => 'Non-Zero LINE Fallback Fixture',
        'blobs' => array(array('bytes' => "\xff")),
        'nodes' => array(
            array(
                'id'             => 'line:non-zero',
                'type'           => 'LINE',
                'name'           => 'Diagonal separator',
                'width'          => 100,
                'height'         => 20,
                'strokeWeight'   => 3,
                'strokeCap'      => 'SQUARE',
                'strokeJoin'     => 'MITER',
                'dashPattern'    => array(8, 4),
                'strokePaints'   => array(array('type' => 'SOLID', 'color' => array('r' => 1, 'g' => 0, 'b' => 0))),
                'strokeGeometry' => array(array('commandsBlob' => 0)),
            ),
        ),
    ));
    $nonZeroLineFallbackHtml = blocks_engine_figma_transformer_contract_file_content($nonZeroLineFallbackResult, 'index.html');
    $assert(str_contains($nonZeroLineFallbackHtml, '<line x1="0" y1="0" x2="100" y2="20" fill="none" stroke="#ff0000" stroke-width="3" stroke-linecap="square" stroke-linejoin="miter" stroke-dasharray="8 4"/>'), 'non-zero-line-fallback-preserves-stroke-geometry');
}
