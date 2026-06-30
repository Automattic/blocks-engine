<?php

declare(strict_types=1);

/**
 * @param callable(bool, string): void $assert
 */
function blocks_engine_figma_transformer_run_parser_parity_contract(callable $assert): void
{
    $fixturePath = sys_get_temp_dir() . '/figma-parser-parity-contract-' . getmypid() . '-' . bin2hex(random_bytes(4)) . '.json';
    file_put_contents($fixturePath, json_encode(array(
        'name'   => 'Parser Parity Fixture',
        'blobs'  => array(array('bytes' => 'synthetic-vector-blob')),
        'assets' => array(
            'fixture-asset' => array(
                'name'      => 'Fixture Asset',
                'mime_type' => 'image/svg+xml',
                'content'   => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 1 1"></svg>',
            ),
            'paint-asset' => array(
                'name'      => 'Paint Asset',
                'mime_type' => 'image/png',
                'content'   => 'paint asset',
            ),
        ),
        'nodes'  => array(
            array(
                'id'       => 'parity:canvas',
                'type'     => 'CANVAS',
                'name'     => 'Parser Parity Canvas',
                'children' => array(
                    array(
                        'id'                           => 'parity:frame',
                        'type'                         => 'FRAME',
                        'name'                         => 'Parity Frame',
                        'width'                        => 600,
                        'height'                       => 320,
                        'componentPropertyDefinitions' => array('Variant' => array('type' => 'TEXT')),
                        'children'                     => array(
                            array(
                                'id'         => 'parity:text',
                                'type'       => 'TEXT',
                                'name'       => 'Parity Text',
                                'characters' => 'Parity copy',
                                'fontSize'   => 24,
                            ),
                            array(
                                'id'       => 'parity:asset',
                                'type'     => 'RECTANGLE',
                                'name'     => 'Parity Asset',
                                'width'    => 100,
                                'height'   => 80,
                                'asset_id' => 'fixture-asset',
                            ),
                            array(
                                'id'          => 'parity:icon-instance',
                                'type'        => 'INSTANCE',
                                'name'        => 'Parity Icon Instance',
                                'componentId' => 'component:icon',
                                'width'       => 20,
                                'height'      => 20,
                            ),
                            array(
                                'id'         => 'parity:image-ref',
                                'type'       => 'RECTANGLE',
                                'name'       => 'Parity Image Ref',
                                'width'      => 64,
                                'height'     => 48,
                                'fillPaints' => array(array('type' => 'IMAGE', 'imageRef' => 'paint-asset')),
                            ),
                            array(
                                'id'         => 'parity:glow-text',
                                'type'       => 'TEXT',
                                'name'       => 'Glow Text',
                                'characters' => 'Glow copy',
                                'fontSize'   => 18,
                                'effects'    => array(array('type' => 'DROP_SHADOW', 'radius' => 8, 'offset' => array('x' => 0, 'y' => 0), 'color' => array('r' => 1, 'g' => 0, 'b' => 0, 'a' => 0.5))),
                            ),
                            array(
                                'id'       => 'parity:mask-node',
                                'type'     => 'RECTANGLE',
                                'name'     => 'Mask Node',
                                'width'    => 24,
                                'height'   => 24,
                                'isMask'   => true,
                                'maskType' => 'ALPHA',
                            ),
                            array(
                                'id'           => 'parity:clip-effect-frame',
                                'type'         => 'FRAME',
                                'name'         => 'Clip Effect Frame',
                                'width'        => 80,
                                'height'       => 40,
                                'clipsContent' => true,
                                'effects'      => array(array('type' => 'LAYER_BLUR', 'radius' => 2)),
                            ),
                            array(
                                'id'       => 'parity:vector-parent',
                                'type'     => 'VECTOR',
                                'name'     => 'Vector Parent',
                                'width'    => 20,
                                'height'   => 20,
                                'children' => array(
                                    array(
                                        'id'     => 'parity:vector-child',
                                        'type'   => 'VECTOR',
                                        'name'   => 'Vector Child',
                                        'width'  => 10,
                                        'height' => 10,
                                    ),
                                ),
                            ),
                            array(
                                'id'       => 'parity:mixed-stack',
                                'type'     => 'FRAME',
                                'name'     => 'Mixed Stack',
                                'width'    => 100,
                                'height'   => 60,
                                'children' => array(
                                    array('id' => 'parity:mixed-flow', 'type' => 'RECTANGLE', 'name' => 'Flow Child', 'width' => 20, 'height' => 20),
                                    array('id' => 'parity:mixed-absolute', 'type' => 'RECTANGLE', 'name' => 'Absolute Child', 'width' => 20, 'height' => 20, 'layoutPositioning' => 'ABSOLUTE'),
                                ),
                            ),
                        ),
                    ),
                    array(
                        'id'       => 'component:icon',
                        'type'     => 'COMPONENT',
                        'name'     => 'Icon Component',
                        'width'    => 20,
                        'height'   => 20,
                        'children' => array(
                            array(
                                'id'           => 'component:icon/vector',
                                'type'         => 'VECTOR',
                                'name'         => 'Icon Vector',
                                'width'        => 20,
                                'height'       => 20,
                                'fillGeometry' => array(array('commandsBlob' => 0)),
                            ),
                        ),
                    ),
                    array(
                        'id'       => 'parity:outside-frame',
                        'type'     => 'FRAME',
                        'name'     => 'Outside Frame',
                        'width'    => 100,
                        'height'   => 100,
                        'children' => array(
                            array(
                                'id'           => 'parity:outside-vector',
                                'type'         => 'VECTOR',
                                'name'         => 'Outside Vector',
                                'width'        => 10,
                                'height'       => 10,
                                'fillGeometry' => array(array('commandsBlob' => 0)),
                            ),
                        ),
                    ),
                    array(
                        'id'         => 'parity:instance-ref',
                        'type'       => 'INSTANCE',
                        'name'       => 'Parity Instance Ref',
                        'symbolData' => array(
                            'symbolOverrides' => array(
                                array(
                                    'fillPaints' => array(array(
                                        'type'  => 'IMAGE',
                                        'image' => array('hash' => 'instance-paint-asset'),
                                    )),
                                ),
                            ),
                        ),
                        'children'   => array(
                            array(
                                'id'         => 'parity:instance-child-ref',
                                'type'       => 'RECTANGLE',
                                'name'       => 'Parity Instance Child Ref',
                                'width'      => 32,
                                'height'     => 32,
                                'fillPaints' => array(array(
                                    'type'  => 'IMAGE',
                                    'image' => array('hash' => 'instance-paint-asset'),
                                )),
                            ),
                        ),
                    ),
                ),
            ),
        ),
    ), JSON_THROW_ON_ERROR));

    $command = escapeshellarg(PHP_BINARY)
        . ' ' . escapeshellarg(__DIR__ . '/../../scripts/figma-parser-parity.php')
        . ' ' . escapeshellarg($fixturePath)
        . ' --frame-id=' . escapeshellarg('parity:frame')
        . ' --limit=10 --sample-limit=3';
    $output = shell_exec($command);
    @unlink($fixturePath);

    $report = is_string($output) ? json_decode($output, true) : null;
    $assert(is_array($report), 'parser-parity-json-output');
    $assert('blocks-engine/figma-transformer/parser-parity/v1' === ($report['schema'] ?? null), 'parser-parity-schema');
    $assert('parity:frame' === ($report['options']['frame_id'] ?? null), 'parser-parity-frame-option');
    $assert(20 === ($report['raw']['node_count'] ?? null), 'parser-parity-raw-node-count');
    $assert(20 === ($report['normalized']['node_count'] ?? null), 'parser-parity-normalized-node-count');
    $assert(1 === ($report['raw']['blob_count'] ?? null), 'parser-parity-raw-blob-count');
    $assert(2 === ($report['raw']['asset_count'] ?? null), 'parser-parity-raw-asset-count');
    $assert(2 === ($report['raw']['text_node_count'] ?? null), 'parser-parity-raw-text-node-count');
    $assert(4 === ($report['raw']['vector_node_count'] ?? null), 'parser-parity-raw-vector-node-count');
    $assert(2 === ($report['raw']['component_prop_node_count'] ?? null), 'parser-parity-component-prop-node-count');
    $assert(20 === ($report['coverage']['raw_to_normalized_node']['covered_count'] ?? null), 'parser-parity-raw-normalized-node-coverage');
    $assert(1 <= ($report['coverage']['raw_vector_to_emitted']['source_count'] ?? 0), 'parser-parity-vector-emitted-selected-scope');
    $assert(1 <= ($report['coverage']['raw_vector_to_emitted']['covered_count'] ?? 0), 'parser-parity-vector-emitted-clone-suffix-coverage');
    $assert(is_int($report['coverage']['raw_vector_to_emitted']['missing_count'] ?? null), 'parser-parity-vector-emitted-missing-count');
    $assert(4 === ($report['coverage']['raw_asset_refs_to_normalized_asset_refs']['covered_count'] ?? null), 'parser-parity-asset-reference-coverage');
    $assert('blocks-engine/figma-transformer/parser-parity-transform-diagnostics/v1' === ($report['transform_diagnostics']['schema'] ?? null), 'parser-parity-transform-diagnostics-schema');
    $assert(1 <= ($report['transform_diagnostics']['effects']['source_effect_node_count'] ?? 0), 'parser-parity-effect-source-count');
    $assert(1 <= ($report['transform_diagnostics']['mask_effect_clipping']['mask_node_count'] ?? 0), 'parser-parity-mask-count');
    $assert(1 <= ($report['transform_diagnostics']['mask_effect_clipping']['clipped_effect_node_count'] ?? 0), 'parser-parity-clipped-effect-count');
    $assert(1 <= ($report['transform_diagnostics']['vector_child_composition']['vector_child_node_count'] ?? 0), 'parser-parity-vector-child-composition-count');
    $assert(1 <= ($report['transform_diagnostics']['stacking_order']['mixed_positioning_parent_count'] ?? 0), 'parser-parity-stacking-order-count');
    $assert(is_array($report['coverage']['normalized_component_clone_to_emitted'] ?? null), 'parser-parity-component-clone-emitted-coverage');
    $assert(is_array($report['top_missing_field_paths'] ?? null), 'parser-parity-missing-field-paths-present');

    $figPath = SyntheticFigKiwiFixtureBuilder::figArchive(
        SyntheticFigKiwiFixtureBuilder::canvas(array(
            SyntheticFigKiwiFixtureBuilder::jsonZlibChunk(array(
                'nodes' => array(
                    array(
                        'id'       => 'parity:fig-frame',
                        'type'     => 'FRAME',
                        'name'     => 'Parser Parity Fig Frame',
                        'width'    => 320,
                        'height'   => 180,
                        'children' => array(),
                    ),
                ),
            )),
        )),
        array('images/fixture.png' => str_repeat('asset-bytes', 128))
    );

    $figCommand = escapeshellarg(PHP_BINARY)
        . ' ' . escapeshellarg(__DIR__ . '/../../scripts/figma-parser-parity.php')
        . ' ' . escapeshellarg($figPath)
        . ' --limit=1 --sample-limit=1';
    $figOutput = shell_exec($figCommand);
    @unlink($figPath);

    $figReport = is_string($figOutput) ? json_decode($figOutput, true) : null;
    $assert(is_array($figReport), 'parser-parity-fig-json-output');
    $assert(false === ($figReport['input']['archive_options']['include_asset_content'] ?? null), 'parser-parity-fig-omits-asset-content-by-default');
    $assert(1 === ($figReport['input']['archive_options']['max_kiwi_message_decode_bytes'] ?? null), 'parser-parity-fig-forces-selective-kiwi-decode-by-default');
    $assert(1 === ($figReport['raw']['node_count'] ?? null), 'parser-parity-fig-raw-node-count');
}
