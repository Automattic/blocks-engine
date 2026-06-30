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
 * @param callable(bool, string): void $assert
 * @param array<string, mixed>|null $node
 * @param array<string, float> $expectedRect
 */
function blocks_engine_figma_transformer_contract_assert_node_rect(callable $assert, ?array $node, array $expectedRect, string $message): void
{
    $assert($expectedRect === ($node['rect'] ?? null), $message);
}
