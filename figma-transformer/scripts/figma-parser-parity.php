#!/usr/bin/env php
<?php

declare(strict_types=1);

use Automattic\BlocksEngine\FigmaTransformer\Compression\ZstdCapability;
use Automattic\BlocksEngine\FigmaTransformer\Compression\ZstdCommandDecoder;
use Automattic\BlocksEngine\FigmaTransformer\FigFile\FigArchiveReader;
use Automattic\BlocksEngine\FigmaTransformer\FigFile\FigKiwiParser;
use Automattic\BlocksEngine\FigmaTransformer\Html\StaticHtmlEmitter;
use Automattic\BlocksEngine\FigmaTransformer\Scenegraph\ScenegraphNormalizer;

$autoload = __DIR__ . '/../vendor/autoload.php';
if ( is_readable($autoload) ) {
    require_once $autoload;
} else {
    require_once __DIR__ . '/../figma-transformer.php';
}

$options = blocks_engine_figma_parser_parity_options($argv);
if ( true === ($options['help'] ?? false) ) {
    blocks_engine_figma_parser_parity_usage(STDOUT);
    exit(0);
}

if ( '' === ($options['input'] ?? '') ) {
    blocks_engine_figma_parser_parity_usage(STDERR);
    exit(1);
}

$input = (string) $options['input'];
$zstdCommand = $options['zstd_command'] ?? (getenv('FIGMA_TRANSFORMER_ZSTD_COMMAND') ?: null);
$zstdCommand = is_string($zstdCommand) && '' !== $zstdCommand ? $zstdCommand : null;
$limit = max(1, (int) ($options['limit'] ?? 20));
$sampleLimit = max(1, (int) ($options['sample_limit'] ?? 5));
$maxNodes = isset($options['max_nodes']) ? (int) $options['max_nodes'] : null;
$archiveOptions = blocks_engine_figma_parser_parity_archive_options($options);

$archive = null;
$source = blocks_engine_figma_parser_parity_read_source($input, $zstdCommand, $archiveOptions, $archive);
$scenegraph = is_array($source['scenegraph'] ?? null) ? $source['scenegraph'] : array();
$transformOptions = array();
if ( isset($options['frame_id']) && '' !== (string) $options['frame_id'] ) {
    $transformOptions['frame_id'] = (string) $options['frame_id'];
}
if ( null !== $maxNodes ) {
    $transformOptions['max_nodes'] = max(0, $maxNodes);
}

$archiveReader = blocks_engine_figma_parser_parity_archive_reader($zstdCommand);
$normalizer = new ScenegraphNormalizer();
$transformScenegraph = str_ends_with(strtolower($input), '.json')
    ? $scenegraph
    : blocks_engine_figma_parser_parity_scenegraph_with_archive_assets($scenegraph, $archive);
$normalized = $normalizer->normalize($transformScenegraph, $transformOptions);
unset($transformScenegraph);
$result = blocks_engine_figma_parser_parity_emit_result($normalized, $transformOptions);

$report = blocks_engine_figma_parser_parity_report($input, $source, $archive, $scenegraph, $normalized, $result, $transformOptions, $archiveOptions, $limit, $sampleLimit, $zstdCommand);
$json = blocks_engine_figma_parser_parity_json_encode($report) . "\n";

if ( isset($options['output']) && '' !== (string) $options['output'] ) {
    $output = (string) $options['output'];
    $directory = dirname($output);
    if ( ! is_dir($directory) && ! mkdir($directory, 0777, true) && ! is_dir($directory) ) {
        fwrite(STDERR, "Unable to create output directory: {$directory}\n");
        exit(1);
    }
    file_put_contents($output, $json);
}

fwrite(STDOUT, $json);
exit(0);

/**
 * @return array<string, mixed>
 */
function blocks_engine_figma_parser_parity_options(array $argv): array
{
    $options = array();
    foreach ( array_slice($argv, 1) as $argument ) {
        if ( '--help' === $argument || '-h' === $argument ) {
            $options['help'] = true;
            continue;
        }
        if ( ! str_starts_with($argument, '--') ) {
            $options['input'] ??= $argument;
            continue;
        }

        $parts = explode('=', substr($argument, 2), 2);
        $options[str_replace('-', '_', $parts[0])] = $parts[1] ?? '1';
    }

    return $options;
}

function blocks_engine_figma_parser_parity_usage(mixed $stream): void
{
    fwrite($stream, "Usage: figma-parser-parity.php <path-to-fig-or-scenegraph-json> [--frame-id=<id>] [--zstd-command=/opt/homebrew/bin/zstd] [--max-nodes=5000] [--max-kiwi-message-decode-bytes=1] [--include-asset-content=0] [--limit=20] [--sample-limit=5] [--output=/tmp/parity.json]\n");
}

/**
 * @return array<string, mixed>
 */
function blocks_engine_figma_parser_parity_archive_options(array $options): array
{
    return array(
        'include_asset_content' => blocks_engine_figma_parser_parity_bool_option($options['include_asset_content'] ?? false),
        'max_kiwi_message_decode_bytes' => max(1, (int) ($options['max_kiwi_message_decode_bytes'] ?? 1)),
    );
}

function blocks_engine_figma_parser_parity_bool_option(mixed $value): bool
{
    if ( is_bool($value) ) {
        return $value;
    }

    $normalized = strtolower((string) $value);
    return in_array($normalized, array('1', 'true', 'yes', 'on'), true);
}

/**
 * @return array{scenegraph: array<string, mixed>, shape: string, decoded_scenegraph?: array<string, mixed>}
 */
function blocks_engine_figma_parser_parity_read_source(string $input, ?string $zstdCommand, array $archiveOptions, ?array &$archive): array
{
    if ( str_ends_with(strtolower($input), '.json') ) {
        $decoded = is_readable($input) ? json_decode((string) file_get_contents($input), true) : null;
        return array('scenegraph' => is_array($decoded) ? $decoded : array(), 'shape' => 'json');
    }

    $archiveReader = blocks_engine_figma_parser_parity_archive_reader($zstdCommand);
    $archive = $archiveReader->read($input, $archiveOptions);
    $candidate = blocks_engine_figma_parser_parity_decoded_scenegraph_candidate($archive);
    return array(
        'scenegraph' => is_array($candidate['payload'] ?? null) ? $candidate['payload'] : array(),
        'shape' => 'fig',
        'decoded_scenegraph' => is_array($candidate['report'] ?? null) ? $candidate['report'] : array(),
    );
}

/**
 * @param array<string, mixed>|null $archive
 * @return array<string, mixed>
 */
function blocks_engine_figma_parser_parity_scenegraph_with_archive_assets(array $scenegraph, ?array $archive): array
{
    $archiveAssets = is_array($archive) && is_array($archive['assets'] ?? null) ? $archive['assets'] : array();
    if ( empty($archiveAssets) ) {
        return $scenegraph;
    }

    $assets = is_array($scenegraph['assets'] ?? null) ? $scenegraph['assets'] : array();
    foreach ( $archiveAssets as $asset ) {
        if ( ! is_array($asset) ) {
            continue;
        }

        $id = (string) ($asset['id'] ?? $asset['hash'] ?? $asset['path'] ?? count($assets));
        $assets[$id] = $asset;
    }

    $scenegraph['assets'] = $assets;
    return $scenegraph;
}

/**
 * @return array<string, mixed>
 */
function blocks_engine_figma_parser_parity_emit_result(array $normalized, array $transformOptions): array
{
    $artifact = (new StaticHtmlEmitter())->emit($normalized, $transformOptions);
    $diagnostics = array_merge(
        is_array($normalized['diagnostics'] ?? null) ? $normalized['diagnostics'] : array(),
        is_array($artifact['diagnostics'] ?? null) ? $artifact['diagnostics'] : array()
    );

    return array(
        'status' => $artifact['status'] ?? 'success_with_warnings',
        'diagnostics' => $diagnostics,
        'files' => is_array($artifact['files'] ?? null) ? $artifact['files'] : array(),
        'assets' => is_array($artifact['assets'] ?? null) ? $artifact['assets'] : array(),
        'source_reports' => array(
            'figma' => array(
                'scenegraph' => $normalized['source_report'] ?? array(),
                'html' => is_array($artifact['source_report'] ?? null) ? $artifact['source_report'] : array(),
            ),
        ),
    );
}

function blocks_engine_figma_parser_parity_archive_reader(?string $zstdCommand): FigArchiveReader
{
    if ( null === $zstdCommand || '' === $zstdCommand ) {
        return new FigArchiveReader();
    }

    return new FigArchiveReader(new FigKiwiParser(new ZstdCapability(new ZstdCommandDecoder(array($zstdCommand, '-dc')))));
}

/**
 * @return array<string, mixed>|null
 */
function blocks_engine_figma_parser_parity_decoded_scenegraph_candidate(array $archive): ?array
{
    $chunks = $archive['archive']['canvas']['chunks'] ?? array();
    if ( ! is_array($chunks) ) {
        return null;
    }

    $candidates = array();
    foreach ( $chunks as $chunk ) {
        if ( ! is_array($chunk) || ! is_array($chunk['payload'] ?? null) ) {
            continue;
        }
        $payload = $chunk['payload'];
        $json = null;
        if ( 'json' === ($payload['classification'] ?? null) && is_array($payload['json'] ?? null) ) {
            $json = $payload['json'];
        } elseif ( 'kiwi_message' === ($payload['classification'] ?? null) && is_array($payload['kiwi_message'] ?? null) ) {
            $json = $payload['kiwi_message'];
        }
        if ( ! is_array($json) || ! blocks_engine_figma_parser_parity_is_scenegraph_payload($json) ) {
            continue;
        }
        $shape = blocks_engine_figma_parser_parity_scenegraph_shape($json);
        $candidates[] = array(
            'payload' => $json,
            'score' => blocks_engine_figma_parser_parity_scenegraph_score($json, $shape),
            'report' => array(
                'chunk_index' => (int) ($chunk['index'] ?? count($candidates)),
                'shape' => $shape,
                'classification' => $payload['classification'] ?? null,
            ),
        );
    }

    usort($candidates, static fn (array $left, array $right): int => ($right['score'] <=> $left['score']) ?: ((int) ($left['report']['chunk_index'] ?? 0) <=> (int) ($right['report']['chunk_index'] ?? 0)));
    return $candidates[0] ?? null;
}

function blocks_engine_figma_parser_parity_is_scenegraph_payload(array $payload): bool
{
    return is_array($payload['NODE_CHANGES'] ?? null)
        || is_array($payload['node_changes'] ?? null)
        || is_array($payload['nodeChanges'] ?? null)
        || is_array($payload['document'] ?? null)
        || is_array($payload['nodes'] ?? null);
}

function blocks_engine_figma_parser_parity_scenegraph_shape(array $payload): string
{
    foreach ( array('NODE_CHANGES', 'node_changes', 'nodeChanges', 'document', 'nodes') as $key ) {
        if ( is_array($payload[$key] ?? null) ) {
            return $key;
        }
    }
    return 'unknown';
}

function blocks_engine_figma_parser_parity_scenegraph_score(array $payload, string $shape): int
{
    $score = 'document' === $shape ? 40 : ('nodes' === $shape ? 30 : 20);
    return $score + count(blocks_engine_figma_parser_parity_flat_node_map($payload));
}

/**
 * @param array<string, mixed>|null $archive
 * @return array<string, mixed>
 */
function blocks_engine_figma_parser_parity_report(string $input, array $source, ?array $archive, array $scenegraph, array $normalized, array $result, array $transformOptions, array $archiveOptions, int $limit, int $sampleLimit, ?string $zstdCommand): array
{
    $rawNodes = blocks_engine_figma_parser_parity_flat_node_map($scenegraph);
    $normalizedNodes = is_array($normalized['node_map'] ?? null) ? $normalized['node_map'] : array();
    $htmlReport = is_array($result['source_reports']['figma']['html'] ?? null) ? $result['source_reports']['figma']['html'] : array();
    $visualNodeIds = blocks_engine_figma_parser_parity_visual_node_ids($htmlReport);
    $htmlNodeIds = blocks_engine_figma_parser_parity_html_node_ids(blocks_engine_figma_parser_parity_file_content($result, 'index.html'));
    $rawFieldReport = blocks_engine_figma_parser_parity_field_report($rawNodes, $normalizedNodes, $limit, $sampleLimit);
    $rawTextNodeIds = array_values(array_unique(array_merge(
        blocks_engine_figma_parser_parity_node_ids_by_type($rawNodes, array('TEXT')),
        blocks_engine_figma_parser_parity_node_ids_with_paths($rawNodes, array('text', 'characters', 'figma_text.characters'))
    )));
    $rawVectorNodeIds = blocks_engine_figma_parser_parity_node_ids_by_type($rawNodes, array('VECTOR', 'BOOLEAN_OPERATION'));
    $rawComponentPropNodeIds = blocks_engine_figma_parser_parity_node_ids_with_paths($rawNodes, array('componentProperties', 'componentPropertyDefinitions', 'component_property_definitions', 'symbolData', 'derivedSymbolData'));
    $rawAssetRefs = blocks_engine_figma_parser_parity_raw_asset_reference_node_ids($rawNodes);
    $emittedNodeIds = array_fill_keys(array_values(array_unique(array_merge($visualNodeIds, $htmlNodeIds))), true);
    $emittedNodeIdList = array_keys($emittedNodeIds);
    $rawEmittedNodeIds = blocks_engine_figma_parser_parity_ids_in_covered_scope(array_keys($rawNodes), $emittedNodeIdList);
    $rawEmittedVectorNodeIds = blocks_engine_figma_parser_parity_ids_in_covered_scope($rawVectorNodeIds, $emittedNodeIdList);
    $normalizedCloneNodeIds = blocks_engine_figma_parser_parity_normalized_component_clone_node_ids($normalizedNodes);

    return array(
        'schema' => 'blocks-engine/figma-transformer/parser-parity/v1',
        'input' => array_filter(array(
            'path' => $input,
            'shape' => $source['shape'] ?? null,
            'decoded_scenegraph' => $source['decoded_scenegraph'] ?? null,
            'archive_input' => is_array($archive) ? ($archive['input'] ?? null) : null,
            'archive_options' => $archiveOptions,
            'zstd_command' => $zstdCommand,
        ), static fn (mixed $value): bool => null !== $value),
        'options' => $transformOptions,
        'raw' => array(
            'node_count' => count($rawNodes),
            'field_path_count' => count($rawFieldReport['raw_field_paths']),
            'blob_count' => count(is_array($scenegraph['blobs'] ?? null) ? $scenegraph['blobs'] : array()),
            'asset_count' => count(is_array($scenegraph['assets'] ?? null) ? $scenegraph['assets'] : array()),
            'asset_reference_node_count' => count($rawAssetRefs),
            'text_node_count' => count($rawTextNodeIds),
            'vector_node_count' => count($rawVectorNodeIds),
            'component_prop_node_count' => count($rawComponentPropNodeIds),
        ),
        'normalized' => array(
            'node_count' => count($normalizedNodes),
            'field_path_count' => count($rawFieldReport['normalized_field_paths']),
            'blob_count' => count(is_array($normalized['figma_blobs'] ?? null) ? $normalized['figma_blobs'] : array()),
            'asset_count' => count(is_array($normalized['assets'] ?? null) ? $normalized['assets'] : array()),
            'asset_reference_count' => count(is_array($normalized['asset_references'] ?? null) ? $normalized['asset_references'] : array()),
            'text_node_count' => count(is_array($normalized['text_inventory'] ?? null) ? $normalized['text_inventory'] : array()),
            'component_definition_count' => $normalized['source_report']['component_definition_count'] ?? null,
            'instance_node_count' => $normalized['source_report']['instance_node_count'] ?? null,
            'diagnostic_count' => count(is_array($normalized['diagnostics'] ?? null) ? $normalized['diagnostics'] : array()),
        ),
        'emitted' => array(
            'status' => $result['status'] ?? null,
            'file_count' => count(is_array($result['files'] ?? null) ? $result['files'] : array()),
            'asset_count' => count(is_array($result['assets'] ?? null) ? $result['assets'] : array()),
            'visual_node_count' => count($visualNodeIds),
            'html_node_attr_count' => count($htmlNodeIds),
            'css_rule_count' => blocks_engine_figma_parser_parity_css_rule_count(blocks_engine_figma_parser_parity_file_content($result, 'style.css')),
            'diagnostic_count' => count(is_array($result['diagnostics'] ?? null) ? $result['diagnostics'] : array()),
        ),
        'coverage' => array(
            'raw_to_normalized_node' => blocks_engine_figma_parser_parity_id_coverage(array_keys($rawNodes), array_keys($normalizedNodes), $sampleLimit),
            'raw_to_emitted_node' => blocks_engine_figma_parser_parity_id_coverage($rawEmittedNodeIds, $emittedNodeIdList, $sampleLimit, true),
            'raw_text_to_normalized_text' => blocks_engine_figma_parser_parity_id_coverage($rawTextNodeIds, blocks_engine_figma_parser_parity_normalized_text_node_ids($normalized), $sampleLimit),
            'raw_asset_refs_to_normalized_asset_refs' => blocks_engine_figma_parser_parity_id_coverage($rawAssetRefs, blocks_engine_figma_parser_parity_normalized_asset_reference_node_ids($normalized), $sampleLimit),
            'raw_vector_to_emitted' => blocks_engine_figma_parser_parity_id_coverage($rawEmittedVectorNodeIds, $emittedNodeIdList, $sampleLimit, true),
            'raw_component_props_to_normalized' => blocks_engine_figma_parser_parity_id_coverage($rawComponentPropNodeIds, array_keys($normalizedNodes), $sampleLimit),
            'normalized_component_clone_to_emitted' => blocks_engine_figma_parser_parity_id_coverage($normalizedCloneNodeIds, $emittedNodeIdList, $sampleLimit, true),
        ),
        'transform_diagnostics' => blocks_engine_figma_parser_parity_transform_diagnostics_summary($htmlReport),
        'top_missing_field_paths' => $rawFieldReport['top_missing_field_paths'],
        'diagnostics' => array(
            'normalized_sample' => array_slice(is_array($normalized['diagnostics'] ?? null) ? $normalized['diagnostics'] : array(), 0, $limit),
            'emitted_sample' => array_slice(is_array($result['diagnostics'] ?? null) ? $result['diagnostics'] : array(), 0, $limit),
        ),
    );
}

/**
 * @param array<string, array<string, mixed>> $normalizedNodes
 * @return array<int, string>
 */
function blocks_engine_figma_parser_parity_normalized_component_clone_node_ids(array $normalizedNodes): array
{
    $ids = array();
    foreach ( $normalizedNodes as $id => $node ) {
        if ( is_array($node) && isset($node['figma_component_source_id']) && is_scalar($node['figma_component_source_id']) ) {
            $ids[] = (string) $id;
        }
    }

    return array_values(array_unique($ids));
}

/**
 * @return array<string, mixed>
 */
function blocks_engine_figma_parser_parity_transform_diagnostics_summary(array $htmlReport): array
{
    $diagnostics = is_array($htmlReport['transform_diagnostics'] ?? null) ? $htmlReport['transform_diagnostics'] : array();
    $artifactQuality = is_array($diagnostics['artifact_quality'] ?? null) ? $diagnostics['artifact_quality'] : array();

    return array(
        'schema' => 'blocks-engine/figma-transformer/parser-parity-transform-diagnostics/v1',
        'artifact_quality_summary' => is_array($artifactQuality['summary'] ?? null) ? $artifactQuality['summary'] : array(),
        'text' => is_array($diagnostics['text'] ?? null) ? $diagnostics['text'] : array(),
        'components' => is_array($diagnostics['components'] ?? null) ? $diagnostics['components'] : array(),
        'effects' => is_array($diagnostics['effects'] ?? null) ? $diagnostics['effects'] : array(),
        'mask_effect_clipping' => is_array($diagnostics['mask_effect_clipping'] ?? null) ? $diagnostics['mask_effect_clipping'] : array(),
        'vector_child_composition' => is_array($diagnostics['vectors']['child_composition'] ?? null) ? $diagnostics['vectors']['child_composition'] : array(),
        'stacking_order' => is_array($diagnostics['layout']['stacking_order'] ?? null) ? $diagnostics['layout']['stacking_order'] : array(),
    );
}

/**
 * @return array<int, string>
 */
function blocks_engine_figma_parser_parity_ids_in_covered_scope(array $sourceIds, array $scopeIds): array
{
    $scoped = array();
    foreach ( array_values(array_unique(array_map('strval', $sourceIds))) as $sourceId ) {
        if ( blocks_engine_figma_parser_parity_id_is_covered_by($sourceId, $scopeIds) ) {
            $scoped[] = $sourceId;
        }
    }

    return $scoped;
}

/**
 * @return array<string, array<string, mixed>>
 */
function blocks_engine_figma_parser_parity_flat_node_map(array $source): array
{
    $nodes = array();
    foreach ( blocks_engine_figma_parser_parity_root_nodes($source) as $key => $root ) {
        if ( is_array($root) ) {
            blocks_engine_figma_parser_parity_collect_flat_node($root, is_string($key) ? $key : null, $nodes);
        }
    }

    ksort($nodes, SORT_NATURAL);
    return $nodes;
}

/**
 * @return array<mixed>
 */
function blocks_engine_figma_parser_parity_root_nodes(array $source): array
{
    foreach ( array('NODE_CHANGES', 'node_changes', 'nodeChanges') as $key ) {
        if ( is_array($source[$key] ?? null) ) {
            return $source[$key];
        }
    }

    if ( is_array($source['document'] ?? null) ) {
        return array($source['document']);
    }

    if ( is_array($source['nodes'] ?? null) ) {
        return $source['nodes'];
    }

    return $source;
}

/**
 * @param array<string, array<string, mixed>> $nodes
 */
function blocks_engine_figma_parser_parity_collect_flat_node(array $value, ?string $fallbackId, array &$nodes): void
{
    $node = blocks_engine_figma_parser_parity_unwrap_node($value);
    if ( null === $node ) {
        return;
    }

    $id = blocks_engine_figma_parser_parity_node_id($node) ?? $fallbackId;
    if ( null === $id || '' === $id ) {
        return;
    }

    $children = is_array($node['children'] ?? null) ? $node['children'] : array();
    unset($node['children']);
    $node['id'] = $id;
    $nodes[$id] = $node;

    foreach ( $children as $key => $child ) {
        if ( is_array($child) ) {
            blocks_engine_figma_parser_parity_collect_flat_node($child, is_string($key) ? $key : null, $nodes);
        }
    }
}

/**
 * @return array<string, mixed>|null
 */
function blocks_engine_figma_parser_parity_unwrap_node(array $value): ?array
{
    foreach ( array('node', 'document', 'newValue', 'value') as $key ) {
        if ( is_array($value[$key] ?? null) ) {
            return $value[$key];
        }
    }

    if ( isset($value['type']) || isset($value['id']) || isset($value['guid']) || isset($value['children']) ) {
        return $value;
    }

    return null;
}

function blocks_engine_figma_parser_parity_node_id(array $node): ?string
{
    foreach ( array('id', 'node_id', 'nodeId') as $key ) {
        if ( isset($node[$key]) && is_scalar($node[$key]) && '' !== (string) $node[$key] ) {
            return (string) $node[$key];
        }
    }

    $guid = $node['guid'] ?? null;
    if ( is_array($guid) ) {
        $session = $guid['sessionID'] ?? null;
        $local = $guid['localID'] ?? null;
        if ( is_scalar($session) && is_scalar($local) ) {
            return (string) $session . ':' . (string) $local;
        }
        if ( is_scalar($local) ) {
            return (string) $local;
        }
    }

    return null;
}

/**
 * @return array{raw_field_paths: array<string, true>, normalized_field_paths: array<string, true>, top_missing_field_paths: array<int, array<string, mixed>>}
 */
function blocks_engine_figma_parser_parity_field_report(array $rawNodes, array $normalizedNodes, int $limit, int $sampleLimit): array
{
    $rawFieldPaths = array();
    $normalizedFieldPaths = array();
    $missing = array();
    foreach ( $rawNodes as $nodeId => $rawNode ) {
        if ( ! is_array($rawNode) ) {
            continue;
        }
        $rawPaths = blocks_engine_figma_parser_parity_flat_paths($rawNode);
        foreach ( $rawPaths as $path ) {
            $rawFieldPaths[$path] = true;
        }
        $normalizedNode = is_array($normalizedNodes[$nodeId] ?? null) ? $normalizedNodes[$nodeId] : array();
        $normalizedPaths = blocks_engine_figma_parser_parity_flat_paths($normalizedNode);
        foreach ( $normalizedPaths as $path ) {
            $normalizedFieldPaths[$path] = true;
        }
        $normalizedPathSet = array_fill_keys($normalizedPaths, true);
        foreach ( $rawPaths as $path ) {
            if ( isset($normalizedPathSet[$path]) ) {
                continue;
            }
            $missing[$path] ??= array('path' => $path, 'count' => 0, 'sample_node_ids' => array());
            $missing[$path]['count']++;
            if ( count($missing[$path]['sample_node_ids']) < $sampleLimit ) {
                $missing[$path]['sample_node_ids'][] = (string) $nodeId;
            }
        }
    }

    usort($missing, static fn (array $left, array $right): int => ((int) $right['count'] <=> (int) $left['count']) ?: strcmp((string) $left['path'], (string) $right['path']));
    return array(
        'raw_field_paths' => $rawFieldPaths,
        'normalized_field_paths' => $normalizedFieldPaths,
        'top_missing_field_paths' => array_slice(array_values($missing), 0, $limit),
    );
}

/**
 * @return array<int, string>
 */
function blocks_engine_figma_parser_parity_flat_paths(array $value, string $prefix = ''): array
{
    $paths = array();
    foreach ( $value as $key => $child ) {
        if ( 'children' === $key || str_starts_with((string) $key, '_') ) {
            continue;
        }
        $path = '' === $prefix ? (string) $key : $prefix . '.' . (is_int($key) ? '*' : (string) $key);
        if ( is_array($child) && ! blocks_engine_figma_parser_parity_is_list_of_scalars($child) ) {
            $paths = array_merge($paths, blocks_engine_figma_parser_parity_flat_paths($child, $path));
        } else {
            $paths[] = $path;
        }
    }
    return array_values(array_unique($paths));
}

function blocks_engine_figma_parser_parity_is_list_of_scalars(array $value): bool
{
    if ( array() === $value ) {
        return true;
    }
    foreach ( $value as $child ) {
        if ( is_array($child) ) {
            return false;
        }
    }
    return true;
}

/**
 * @return array<int, string>
 */
function blocks_engine_figma_parser_parity_visual_node_ids(array $htmlReport): array
{
    $ids = array();
    foreach ( is_array($htmlReport['visual_node_map'] ?? null) ? $htmlReport['visual_node_map'] : array() as $node ) {
        if ( is_array($node) && isset($node['id']) && is_scalar($node['id']) ) {
            $ids[] = (string) $node['id'];
        }
    }
    return array_values(array_unique($ids));
}

/**
 * @return array<int, string>
 */
function blocks_engine_figma_parser_parity_html_node_ids(string $html): array
{
    if ( '' === $html || 1 !== preg_match_all('/data-figma-node-id="([^"]+)"/', $html, $matches) ) {
        return array();
    }
    return array_values(array_unique(array_map('html_entity_decode', $matches[1])));
}

function blocks_engine_figma_parser_parity_css_rule_count(string $css): int
{
    return '' !== $css && 1 === preg_match_all('/\{/', $css, $matches) ? count($matches[0]) : 0;
}

/**
 * @return array<int, string>
 */
function blocks_engine_figma_parser_parity_node_ids_by_type(array $nodes, array $types): array
{
    $allowed = array_fill_keys($types, true);
    $ids = array();
    foreach ( $nodes as $id => $node ) {
        if ( is_array($node) && isset($allowed[strtoupper((string) ($node['type'] ?? ''))]) ) {
            $ids[] = (string) $id;
        }
    }
    return $ids;
}

/**
 * @return array<int, string>
 */
function blocks_engine_figma_parser_parity_node_ids_with_paths(array $nodes, array $paths): array
{
    $ids = array();
    foreach ( $nodes as $id => $node ) {
        if ( ! is_array($node) ) {
            continue;
        }
        foreach ( $paths as $path ) {
            if ( blocks_engine_figma_parser_parity_has_path($node, $path) ) {
                $ids[] = (string) $id;
                break;
            }
        }
    }
    return array_values(array_unique($ids));
}

function blocks_engine_figma_parser_parity_has_path(array $value, string $path): bool
{
    $current = $value;
    foreach ( explode('.', $path) as $part ) {
        if ( ! is_array($current) || ! array_key_exists($part, $current) ) {
            return false;
        }
        $current = $current[$part];
    }
    return true;
}

/**
 * @return array<int, string>
 */
function blocks_engine_figma_parser_parity_raw_asset_reference_node_ids(array $nodes): array
{
    $ids = array();
    foreach ( $nodes as $id => $node ) {
        if ( ! is_array($node) ) {
            continue;
        }
        $encoded = json_encode($node, JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR);
        if ( is_string($encoded) && preg_match('/("asset_id"|"imageRef"|"imageHash"|"hash"|"ref")/', $encoded) ) {
            $ids[] = (string) $id;
        }
    }
    return array_values(array_unique($ids));
}

/**
 * @return array<int, string>
 */
function blocks_engine_figma_parser_parity_normalized_asset_reference_node_ids(array $normalized): array
{
    $ids = array();
    foreach ( is_array($normalized['asset_references'] ?? null) ? $normalized['asset_references'] : array() as $reference ) {
        if ( is_array($reference) && isset($reference['node_id']) && is_scalar($reference['node_id']) ) {
            $ids[] = (string) $reference['node_id'];
        }
    }

    foreach ( is_array($normalized['node_map'] ?? null) ? $normalized['node_map'] : array() as $nodeId => $node ) {
        if ( ! is_array($node) || 'INSTANCE' !== strtoupper((string) ($node['type'] ?? '')) ) {
            continue;
        }
        if ( blocks_engine_figma_parser_parity_normalized_node_has_child_asset_reference($node) ) {
            $ids[] = (string) $nodeId;
        }
    }

    return array_values(array_unique($ids));
}

function blocks_engine_figma_parser_parity_normalized_node_has_child_asset_reference(array $node): bool
{
    foreach ( is_array($node['children'] ?? null) ? $node['children'] : array() as $child ) {
        if ( ! is_array($child) ) {
            continue;
        }
        if ( blocks_engine_figma_parser_parity_normalized_node_has_direct_asset_reference($child) || blocks_engine_figma_parser_parity_normalized_node_has_child_asset_reference($child) ) {
            return true;
        }
    }

    return false;
}

function blocks_engine_figma_parser_parity_normalized_node_has_direct_asset_reference(array $node): bool
{
    foreach ( array('asset_id', 'assetId', 'image_ref', 'imageRef', 'imageHash', 'ref') as $key ) {
        if ( isset($node[$key]) && is_scalar($node[$key]) && '' !== (string) $node[$key] ) {
            return true;
        }
    }

    foreach ( array('fills', 'strokes', 'background', 'fillPaints', 'strokePaints') as $paintKey ) {
        if ( blocks_engine_figma_parser_parity_normalized_paints_have_asset_reference(is_array($node[$paintKey] ?? null) ? $node[$paintKey] : array()) ) {
            return true;
        }
    }

    foreach ( array('fills', 'strokes', 'background') as $paintKey ) {
        if ( blocks_engine_figma_parser_parity_normalized_paints_have_asset_reference(is_array($node['figma_paints'][$paintKey] ?? null) ? $node['figma_paints'][$paintKey] : array()) ) {
            return true;
        }
    }

    return false;
}

function blocks_engine_figma_parser_parity_normalized_paints_have_asset_reference(array $paints): bool
{
    foreach ( $paints as $paint ) {
        if ( ! is_array($paint) ) {
            continue;
        }
        if ( 'IMAGE' !== strtoupper((string) ($paint['type'] ?? '')) ) {
            continue;
        }
        foreach ( array('ref', 'imageRef', 'imageHash', 'asset_id', 'image_ref') as $key ) {
            if ( isset($paint[$key]) && is_scalar($paint[$key]) && '' !== (string) $paint[$key] ) {
                return true;
            }
        }
        if ( isset($paint['image']['hash']) && is_scalar($paint['image']['hash']) && '' !== (string) $paint['image']['hash'] ) {
            return true;
        }
    }

    return false;
}

/**
 * @return array<int, string>
 */
function blocks_engine_figma_parser_parity_normalized_text_node_ids(array $normalized): array
{
    $ids = array();
    foreach ( is_array($normalized['text_inventory'] ?? null) ? $normalized['text_inventory'] : array() as $record ) {
        if ( is_array($record) && isset($record['id']) && is_scalar($record['id']) ) {
            $ids[] = (string) $record['id'];
        }
    }
    return array_values(array_unique($ids));
}

/**
 * @return array<string, mixed>
 */
function blocks_engine_figma_parser_parity_id_coverage(array $sourceIds, array $coveredIds, int $sampleLimit, bool $allowCloneSuffix = false): array
{
    $sourceIds = array_values(array_unique(array_map('strval', $sourceIds)));
    $covered = array_fill_keys(array_values(array_unique(array_map('strval', $coveredIds))), true);
    $coveredIdList = array_keys($covered);
    $missing = array_values(array_filter(
        $sourceIds,
        static fn (string $id): bool => ! isset($covered[$id]) && (! $allowCloneSuffix || ! blocks_engine_figma_parser_parity_id_is_covered_by($id, $coveredIdList))
    ));
    $coveredCount = count($sourceIds) - count($missing);
    return array(
        'source_count' => count($sourceIds),
        'covered_count' => $coveredCount,
        'missing_count' => count($missing),
        'coverage_ratio' => 0 === count($sourceIds) ? 1.0 : round($coveredCount / count($sourceIds), 4),
        'missing_sample_node_ids' => array_slice($missing, 0, $sampleLimit),
    );
}

function blocks_engine_figma_parser_parity_id_is_covered_by(string $sourceId, array $coveredIds): bool
{
    if ( '' === $sourceId ) {
        return false;
    }

    foreach ( $coveredIds as $coveredId ) {
        $coveredId = (string) $coveredId;
        if ( $sourceId === $coveredId || str_ends_with($coveredId, '/' . $sourceId) ) {
            return true;
        }
    }

    return false;
}

function blocks_engine_figma_parser_parity_file_content(array $result, string $path): string
{
    foreach ( is_array($result['files'] ?? null) ? $result['files'] : array() as $file ) {
        if ( is_array($file) && $path === ($file['path'] ?? null) && is_scalar($file['content'] ?? null) ) {
            return (string) $file['content'];
        }
    }
    return '';
}

function blocks_engine_figma_parser_parity_json_encode(array $value): string
{
    return json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR) ?: '{}';
}
