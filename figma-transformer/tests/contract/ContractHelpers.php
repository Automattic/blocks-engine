<?php

declare(strict_types=1);

/**
 * @param array<string, mixed> $scenegraph
 * @param array<string, mixed> $options
 * @return array<string, mixed>
 */
function blocks_engine_figma_transformer_contract_transform(array $scenegraph, array $options = array()): array
{
    return blocks_engine_figma_transformer_transform_scenegraph($scenegraph, $options);
}

/**
 * @param array<string, mixed> $result
 */
function blocks_engine_figma_transformer_contract_file_content(array $result, string $path): string
{
    foreach ( $result['files'] ?? array() as $file ) {
        if ( is_array($file) && $path === ($file['path'] ?? null) ) {
            return (string) ($file['content'] ?? '');
        }
    }

    return '';
}

/**
 * @param array<int, mixed> $visualNodeMap
 * @return array<string, mixed>|null
 */
function blocks_engine_figma_transformer_contract_find_visual_node_in_map(array $visualNodeMap, string $id): ?array
{
    foreach ( $visualNodeMap as $node ) {
        if ( is_array($node) && $id === ($node['id'] ?? null) ) {
            return $node;
        }
    }

    return null;
}

/**
 * @param array<string, mixed> $result
 * @return array<string, mixed>|null
 */
function blocks_engine_figma_transformer_contract_find_visual_node(array $result, string $id): ?array
{
    $visualNodeMap = $result['source_reports']['figma']['html']['visual_node_map'] ?? array();
    return blocks_engine_figma_transformer_contract_find_visual_node_in_map(is_array($visualNodeMap) ? $visualNodeMap : array(), $id);
}

/**
 * @param array<string, mixed> $result
 * @return array<int, string>
 */
function blocks_engine_figma_transformer_contract_artifact_quality_signal_codes(array $result): array
{
    $signals = $result['source_reports']['figma']['html']['transform_diagnostics']['artifact_quality']['signals'] ?? array();
    return array_values(array_map(
        static fn (array $signal): string => (string) ($signal['code'] ?? ''),
        is_array($signals) ? $signals : array()
    ));
}

/**
 * @param array<string, mixed> $result
 * @return array<string, mixed>|null
 */
function blocks_engine_figma_transformer_contract_artifact_quality_signal(array $result, string $code): ?array
{
    $signals = $result['source_reports']['figma']['html']['transform_diagnostics']['artifact_quality']['signals'] ?? array();
    foreach ( is_array($signals) ? $signals : array() as $signal ) {
        if ( is_array($signal) && $code === ($signal['code'] ?? null) ) {
            return $signal;
        }
    }

    return null;
}

/**
 * @param array<string, mixed> $result
 * @return array<string, mixed>
 */
function blocks_engine_figma_transformer_contract_transform_diagnostics(array $result): array
{
    $diagnostics = $result['source_reports']['figma']['html']['transform_diagnostics'] ?? array();
    return is_array($diagnostics) ? $diagnostics : array();
}

/**
 * @param callable(bool, string): void $assert
 * @param array<string, mixed> $diagnostics
 */
function blocks_engine_figma_transformer_contract_assert_diagnostic_envelope(callable $assert, array $diagnostics, string $schema, string $messagePrefix): void
{
    $assert($schema === ($diagnostics['schema'] ?? null), $messagePrefix . '-schema');
    $assert(is_array($diagnostics['artifact_quality'] ?? null), $messagePrefix . '-artifact-quality-envelope');
    $assert(is_array($diagnostics['diagnostic_codes'] ?? null), $messagePrefix . '-diagnostic-codes-envelope');
}

/**
 * @param callable(bool, string): void $assert
 * @param array<string, mixed> $diagnostics
 */
function blocks_engine_figma_transformer_contract_assert_diagnostic_summary_envelope(callable $assert, array $diagnostics, string $schema, string $messagePrefix): void
{
    $assert($schema === ($diagnostics['schema'] ?? null), $messagePrefix . '-schema');
    $assert(is_array($diagnostics['artifact_quality_summary'] ?? null), $messagePrefix . '-artifact-quality-summary-envelope');
}

/**
 * @param array<string, mixed> $payload
 */
function blocks_engine_figma_transformer_contract_json_fixture(string $prefix, array $payload): string
{
    $path = sys_get_temp_dir() . '/' . $prefix . '-' . getmypid() . '-' . bin2hex(random_bytes(4)) . '.json';
    file_put_contents($path, json_encode($payload, JSON_THROW_ON_ERROR));

    return $path;
}

/**
 * @param array<int, string> $args
 * @return array<string, mixed>|null
 */
function blocks_engine_figma_transformer_contract_run_json_script(string $script, string $inputPath, array $args = array()): ?array
{
    $command = escapeshellarg(PHP_BINARY)
        . ' ' . escapeshellarg($script)
        . ' ' . escapeshellarg($inputPath);

    foreach ( $args as $arg ) {
        $command .= ' ' . $arg;
    }

    $output = shell_exec($command);
    $decoded = is_string($output) ? json_decode($output, true) : null;

    return is_array($decoded) ? $decoded : null;
}

/**
 * @param array<string, mixed> $payload
 * @param array<int, string>  $args
 * @return array<string, mixed>|null
 */
function blocks_engine_figma_transformer_contract_run_json_fixture_script(string $prefix, string $script, array $payload, array $args = array()): ?array
{
    $fixturePath = blocks_engine_figma_transformer_contract_json_fixture($prefix, $payload);
    try {
        return blocks_engine_figma_transformer_contract_run_json_script($script, $fixturePath, $args);
    } finally {
        @unlink($fixturePath);
    }
}

/**
 * @param array<int, mixed> $nodeTraces
 * @return array<string, mixed>|null
 */
function blocks_engine_figma_transformer_contract_find_trace_node(array $nodeTraces, string $id): ?array
{
    foreach ( $nodeTraces as $nodeTrace ) {
        if ( is_array($nodeTrace) && $id === ($nodeTrace['id'] ?? null) ) {
            return $nodeTrace;
        }
    }

    return null;
}

/**
 * @param array<string, array<string, mixed>> $fields
 * @return array<string, mixed>
 */
function blocks_engine_figma_transformer_contract_find_named_field(array $fields, string $fieldName): array
{
    foreach ( $fields as $field ) {
        if ( $fieldName === ($field['field'] ?? null) ) {
            return $field;
        }
    }

    return array();
}

/**
 * @param callable(bool, string): void $assert
 * @param array<string, mixed>|null $node
 * @param array<string, float> $expectedRect
 */
function blocks_engine_figma_transformer_contract_assert_node_rect(callable $assert, ?array $node, array $expectedRect, string $message): void
{
    $assert($expectedRect === ($node['rect'] ?? null), $message);
}
