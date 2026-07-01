<?php

declare(strict_types=1);

/**
 * @param callable(bool, string): void $assert
 */
function blocks_engine_figma_transformer_run_node_trace_contract(callable $assert): void
{
    $trace = blocks_engine_figma_transformer_contract_run_json_fixture_script(
        'figma-node-trace-contract',
        __DIR__ . '/../../scripts/figma-node-trace.php',
        array(
        'name'  => 'Node Trace Fixture',
        'nodes' => array(
            array(
                'id'         => 'trace:frame',
                'type'       => 'FRAME',
                'name'       => 'Trace Frame',
                'width'      => 400,
                'height'     => 240,
                'layoutMode' => 'VERTICAL',
                'children'   => array(
                    array(
                        'id'       => 'trace:title',
                        'type'     => 'TEXT',
                        'name'     => 'Trace Title',
                        'text'     => 'Trace me',
                        'width'    => 120,
                        'height'   => 32,
                        'fontSize' => 24,
                    ),
                ),
            ),
        ),
        ),
        array(
            '--frame-id=' . escapeshellarg('trace:frame'),
            '--node-ids=' . escapeshellarg('trace:frame,trace:title'),
        )
    );
    $assert(is_array($trace), 'node-trace-json-output');
    $assert('blocks-engine/figma-transformer/node-trace/v1' === ($trace['schema'] ?? null), 'node-trace-schema');
    $assert('trace:frame' === ($trace['frame_id'] ?? null), 'node-trace-frame-id');
    $assert(2 === count(is_array($trace['nodes'] ?? null) ? $trace['nodes'] : array()), 'node-trace-node-count');

    $titleTrace = blocks_engine_figma_transformer_contract_find_trace_node(is_array($trace['nodes'] ?? null) ? $trace['nodes'] : array(), 'trace:title');

    $assert(is_array($titleTrace), 'node-trace-title-present');
    $assert('TEXT' === ($titleTrace['raw']['type'] ?? null), 'node-trace-raw-type');
    $assert('Trace me' === ($titleTrace['raw']['text']['characters'] ?? null), 'node-trace-raw-text');
    $assert('TEXT' === ($titleTrace['normalized']['type'] ?? null), 'node-trace-normalized-type');
    $assert(($titleTrace['field_coverage']['raw_count'] ?? 0) > 0, 'node-trace-field-coverage-raw-count');
    $assert('Trace me' === ($titleTrace['field_coverage']['signal']['raw']['text'] ?? null), 'node-trace-field-coverage-raw-signal');
    $assert('TEXT' === ($titleTrace['field_coverage']['signal']['normalized']['type'] ?? null), 'node-trace-field-coverage-normalized-signal');
    $assert('figma-node-trace-title-trace-title' === ($titleTrace['emitted']['class'] ?? null), 'node-trace-emitted-class');
    $assert(str_contains((string) ($titleTrace['emitted']['html'] ?? ''), 'data-figma-node-id="trace:title"'), 'node-trace-emitted-html-snippet');
    $assert(str_contains((string) ($titleTrace['emitted']['css'] ?? ''), '.figma-node-trace-title-trace-title{'), 'node-trace-emitted-css-rule');
    $assert(is_array($titleTrace['visual']['rect'] ?? null), 'node-trace-visual-rect');
    $assert(is_array($trace['diagnostics_sample']['transform'] ?? null), 'node-trace-transform-diagnostics-sample');

    $cloneTrace = blocks_engine_figma_transformer_contract_run_json_fixture_script(
        'figma-node-trace-clone-contract',
        __DIR__ . '/../../scripts/figma-node-trace.php',
        array(
        'name'  => 'Node Trace Clone Fixture',
        'nodes' => array(
            array(
                'id'       => 'trace-clone:component',
                'type'     => 'COMPONENT',
                'name'     => 'Trace Clone Component',
                'width'    => 160,
                'height'   => 60,
                'children' => array(
                    array(
                        'id'       => 'trace-clone:component/title',
                        'type'     => 'TEXT',
                        'name'     => 'Clone Source Title',
                        'text'     => 'Clone source',
                        'width'    => 140,
                        'height'   => 28,
                        'fontSize' => 20,
                    ),
                ),
            ),
            array(
                'id'          => 'trace-clone:frame',
                'type'        => 'FRAME',
                'name'        => 'Trace Clone Frame',
                'width'       => 300,
                'height'      => 140,
                'layoutMode'  => 'VERTICAL',
                'children'    => array(
                    array(
                        'id'          => 'trace-clone:instance',
                        'type'        => 'INSTANCE',
                        'name'        => 'Trace Clone Instance',
                        'componentId' => 'trace-clone:component',
                        'width'       => 160,
                        'height'      => 60,
                    ),
                ),
            ),
        ),
        ),
        array(
            '--frame-id=' . escapeshellarg('trace-clone:frame'),
            '--node-ids=' . escapeshellarg('trace-clone:instance/trace-clone:component/title'),
        )
    );
    $assert(is_array($cloneTrace), 'node-trace-clone-json-output');
    $cloneTitleTrace = is_array($cloneTrace['nodes'][0] ?? null) ? $cloneTrace['nodes'][0] : array();
    $assert('trace-clone:instance/trace-clone:component/title' === ($cloneTitleTrace['id'] ?? null), 'node-trace-clone-generated-id');
    $assert('trace-clone:component/title' === ($cloneTitleTrace['normalized']['source_id'] ?? null), 'node-trace-clone-normalized-source-id');
    $assert('trace-clone:component/title' === ($cloneTitleTrace['source']['id'] ?? null), 'node-trace-clone-source-id');
    $assert('trace-clone:component/title' === ($cloneTitleTrace['raw']['id'] ?? null), 'node-trace-clone-raw-source-id');
    $assert('Clone source' === ($cloneTitleTrace['raw']['text']['characters'] ?? null), 'node-trace-clone-raw-source-text');
}
