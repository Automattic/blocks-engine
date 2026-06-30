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
}
