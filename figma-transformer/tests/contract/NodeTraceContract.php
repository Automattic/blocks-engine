<?php

declare(strict_types=1);

/**
 * @param callable(bool, string): void $assert
 */
function blocks_engine_figma_transformer_run_node_trace_contract(callable $assert): void
{
    $traceFixturePath = sys_get_temp_dir() . '/figma-node-trace-contract-' . getmypid() . '-' . bin2hex(random_bytes(4)) . '.json';
    file_put_contents($traceFixturePath, json_encode(array(
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
    ), JSON_THROW_ON_ERROR));

    $command = escapeshellarg(PHP_BINARY)
        . ' ' . escapeshellarg(__DIR__ . '/../../scripts/figma-node-trace.php')
        . ' ' . escapeshellarg($traceFixturePath)
        . ' --frame-id=' . escapeshellarg('trace:frame')
        . ' --node-ids=' . escapeshellarg('trace:frame,trace:title');
    $output = shell_exec($command);
    @unlink($traceFixturePath);

    $trace = is_string($output) ? json_decode($output, true) : null;
    $assert(is_array($trace), 'node-trace-json-output');
    $assert('blocks-engine/figma-transformer/node-trace/v1' === ($trace['schema'] ?? null), 'node-trace-schema');
    $assert('trace:frame' === ($trace['frame_id'] ?? null), 'node-trace-frame-id');
    $assert(2 === count(is_array($trace['nodes'] ?? null) ? $trace['nodes'] : array()), 'node-trace-node-count');

    $titleTrace = null;
    foreach ( is_array($trace['nodes'] ?? null) ? $trace['nodes'] : array() as $nodeTrace ) {
        if ( is_array($nodeTrace) && 'trace:title' === ($nodeTrace['id'] ?? null) ) {
            $titleTrace = $nodeTrace;
        }
    }

    $assert(is_array($titleTrace), 'node-trace-title-present');
    $assert('TEXT' === ($titleTrace['raw']['type'] ?? null), 'node-trace-raw-type');
    $assert('Trace me' === ($titleTrace['raw']['text']['characters'] ?? null), 'node-trace-raw-text');
    $assert('TEXT' === ($titleTrace['normalized']['type'] ?? null), 'node-trace-normalized-type');
    $assert('figma-node-trace-title-trace-title' === ($titleTrace['emitted']['class'] ?? null), 'node-trace-emitted-class');
    $assert(str_contains((string) ($titleTrace['emitted']['html'] ?? ''), 'data-figma-node-id="trace:title"'), 'node-trace-emitted-html-snippet');
    $assert(str_contains((string) ($titleTrace['emitted']['css'] ?? ''), '.figma-node-trace-title-trace-title{'), 'node-trace-emitted-css-rule');
    $assert(is_array($titleTrace['visual']['rect'] ?? null), 'node-trace-visual-rect');
    $assert(is_array($trace['diagnostics_sample']['transform'] ?? null), 'node-trace-transform-diagnostics-sample');

    $cloneTraceFixturePath = sys_get_temp_dir() . '/figma-node-trace-clone-contract-' . getmypid() . '-' . bin2hex(random_bytes(4)) . '.json';
    file_put_contents($cloneTraceFixturePath, json_encode(array(
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
    ), JSON_THROW_ON_ERROR));

    $cloneCommand = escapeshellarg(PHP_BINARY)
        . ' ' . escapeshellarg(__DIR__ . '/../../scripts/figma-node-trace.php')
        . ' ' . escapeshellarg($cloneTraceFixturePath)
        . ' --frame-id=' . escapeshellarg('trace-clone:frame')
        . ' --node-ids=' . escapeshellarg('trace-clone:instance/trace-clone:component/title');
    $cloneOutput = shell_exec($cloneCommand);
    @unlink($cloneTraceFixturePath);

    $cloneTrace = is_string($cloneOutput) ? json_decode($cloneOutput, true) : null;
    $assert(is_array($cloneTrace), 'node-trace-clone-json-output');
    $cloneTitleTrace = is_array($cloneTrace['nodes'][0] ?? null) ? $cloneTrace['nodes'][0] : array();
    $assert('trace-clone:instance/trace-clone:component/title' === ($cloneTitleTrace['id'] ?? null), 'node-trace-clone-generated-id');
    $assert('trace-clone:component/title' === ($cloneTitleTrace['normalized']['source_id'] ?? null), 'node-trace-clone-normalized-source-id');
    $assert('trace-clone:component/title' === ($cloneTitleTrace['source']['id'] ?? null), 'node-trace-clone-source-id');
    $assert('trace-clone:component/title' === ($cloneTitleTrace['raw']['id'] ?? null), 'node-trace-clone-raw-source-id');
    $assert('Clone source' === ($cloneTitleTrace['raw']['text']['characters'] ?? null), 'node-trace-clone-raw-source-text');
}
