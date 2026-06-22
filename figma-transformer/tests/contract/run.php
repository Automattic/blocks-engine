<?php

declare(strict_types=1);

require_once __DIR__ . '/../../figma-transformer.php';

$failures = array();

$assert = static function (bool $condition, string $message) use (&$failures): void {
    if ( ! $condition ) {
        $failures[] = $message;
    }
};

$result = blocks_engine_figma_transformer_transform_scenegraph(array(
    'name'  => 'Fixture Site',
    'nodes' => array(
        array('id' => '1:2', 'type' => 'TEXT', 'name' => 'Hero title', 'text' => 'Hello Figma'),
        array('id' => '1:3', 'type' => 'FRAME', 'name' => 'Hero section'),
    ),
));

$assert('blocks-engine/figma-transformer/result/v1' === ($result['schema'] ?? null), 'result-schema');
$assert('success' === ($result['status'] ?? null), 'scenegraph-transform-success');
$assert(2 === ($result['metrics']['node_count'] ?? null), 'node-count');
$assert(str_contains((string) ($result['files'][0]['content'] ?? ''), 'Hello Figma'), 'html-contains-text');
$assert('blocks-engine/figma-transformer/parity-report/v1' === ($result['parity']['schema'] ?? null), 'parity-schema');

if ( ! empty($failures) ) {
    fwrite(STDERR, "Figma Transformer contract failures:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

fwrite(STDOUT, "Figma Transformer contract tests passed.\n");
