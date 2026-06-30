#!/usr/bin/env php
<?php

declare(strict_types=1);

use Automattic\BlocksEngine\FigmaTransformer\Compression\ZstdCapability;
use Automattic\BlocksEngine\FigmaTransformer\Compression\ZstdCommandDecoder;
use Automattic\BlocksEngine\FigmaTransformer\FigFile\FigArchiveReader;
use Automattic\BlocksEngine\FigmaTransformer\FigFile\FigKiwiParser;
use Automattic\BlocksEngine\FigmaTransformer\Html\StaticHtmlEmitter;
use Automattic\BlocksEngine\FigmaTransformer\Scenegraph\ScenegraphIndex;
use Automattic\BlocksEngine\FigmaTransformer\Scenegraph\ScenegraphNormalizer;

$autoload = __DIR__ . '/../vendor/autoload.php';
if ( is_readable($autoload) ) {
    require_once $autoload;
} else {
    require_once __DIR__ . '/../figma-transformer.php';
}

$options = blocks_engine_figma_trace_options($argv);
if ( true === ($options['help'] ?? false) ) {
    blocks_engine_figma_trace_usage(STDOUT);
    exit(0);
}

if ( '' === ($options['input'] ?? '') || '' === ($options['frame_id'] ?? '') || empty($options['node_ids']) ) {
    blocks_engine_figma_trace_usage(STDERR);
    exit(1);
}

$input = (string) $options['input'];
$frameId = (string) $options['frame_id'];
$nodeIds = $options['node_ids'];
$zstdCommand = $options['zstd_command'] ?? (getenv('FIGMA_TRANSFORMER_ZSTD_COMMAND') ?: null);
$diagnosticLimit = (int) ($options['diagnostic_limit'] ?? 20);
$maxNodes = isset($options['max_nodes']) ? (int) $options['max_nodes'] : null;
$archiveOptions = blocks_engine_figma_trace_archive_options($options);

$archive = null;
$source = blocks_engine_figma_trace_read_source($input, is_string($zstdCommand) && '' !== $zstdCommand ? $zstdCommand : null, $archiveOptions, $archive);

$transformOptions = array_merge($archiveOptions, array('frame_id' => $frameId));
if ( null !== $maxNodes ) {
    $transformOptions['max_nodes'] = max(0, $maxNodes);
}

$normalizer = new ScenegraphNormalizer();
$normalized = $normalizer->normalize(is_array($source['scenegraph'] ?? null) ? $source['scenegraph'] : array(), $transformOptions);
$result = blocks_engine_figma_trace_emit_result($normalized, $transformOptions);

$normalizedNodes = blocks_engine_figma_trace_normalized_node_lookup($normalized);
$traceSourceIds = array();
foreach ( $nodeIds as $nodeId ) {
    $normalizedNode = blocks_engine_figma_trace_normalized_node($normalized, $normalizedNodes, (string) $nodeId);
    $sourceId = blocks_engine_figma_trace_component_source_id($normalizedNode);
    if ( null !== $sourceId ) {
        $traceSourceIds[] = $sourceId;
    }
}
$rawNodes = blocks_engine_figma_trace_find_raw_nodes(is_array($source['scenegraph'] ?? null) ? $source['scenegraph'] : array(), array_values(array_unique(array_merge($nodeIds, $traceSourceIds))));
$htmlReport = is_array($result['source_reports']['figma']['html'] ?? null) ? $result['source_reports']['figma']['html'] : array();
$trace = array(
    'schema' => 'blocks-engine/figma-transformer/node-trace/v1',
    'input' => array_filter(array(
        'path' => $input,
        'shape' => $source['shape'] ?? null,
        'decoded_scenegraph' => $source['decoded_scenegraph'] ?? null,
        'archive_input' => is_array($archive) ? ($archive['input'] ?? null) : null,
    ), static fn (mixed $value): bool => null !== $value),
    'frame_id' => $frameId,
    'node_ids' => $nodeIds,
    'nodes' => array(),
    'diagnostics_sample' => blocks_engine_figma_trace_diagnostics_sample($result, $htmlReport, $diagnosticLimit),
    'metrics' => $result['metrics'] ?? array(),
);

foreach ( $nodeIds as $nodeId ) {
    $nodeId = (string) $nodeId;
    $normalizedNode = blocks_engine_figma_trace_normalized_node($normalized, $normalizedNodes, $nodeId);
    $sourceId = blocks_engine_figma_trace_component_source_id($normalizedNode);
    $style = blocks_engine_figma_trace_style_diagnostic($htmlReport, $nodeId);
    $className = is_array($style) ? (string) ($style['node']['class'] ?? '') : '';
    $trace['nodes'][] = array(
        'id' => $nodeId,
        'raw' => blocks_engine_figma_trace_node_summary($rawNodes[$nodeId] ?? (null !== $sourceId ? ($rawNodes[$sourceId] ?? null) : null), array(), isset($rawNodes[$nodeId]['id']) && is_scalar($rawNodes[$nodeId]['id']) ? (string) $rawNodes[$nodeId]['id'] : ($sourceId ?? $nodeId)),
        'source' => null !== $sourceId ? blocks_engine_figma_trace_node_summary($rawNodes[$sourceId] ?? ($normalizedNodes[$sourceId] ?? null), $normalized, $sourceId) : null,
        'normalized' => blocks_engine_figma_trace_node_summary($normalizedNode, $normalized, $nodeId),
        'emitted' => array_filter(array(
            'class' => '' !== $className ? $className : null,
            'tag' => is_array($style) ? ($style['node']['tag'] ?? null) : null,
            'html' => blocks_engine_figma_trace_html_snippet($result, $nodeId),
            'css' => '' !== $className ? blocks_engine_figma_trace_css_rule($result, $className) : null,
            'style_diagnostic' => $style,
        ), static fn (mixed $value): bool => null !== $value && array() !== $value),
        'visual' => blocks_engine_figma_trace_visual_node($htmlReport, $nodeId),
    );
}

fwrite(STDOUT, blocks_engine_figma_trace_json_encode($trace) . "\n");
exit(0);

/**
 * @return array<string, mixed>
 */
function blocks_engine_figma_trace_options(array $argv): array
{
    $options = array('node_ids' => array());
    foreach ( array_slice($argv, 1) as $argument ) {
        if ( '--help' === $argument || '-h' === $argument ) {
            $options['help'] = true;
            continue;
        }
        if ( ! str_starts_with($argument, '--') ) {
            if ( ! isset($options['input']) ) {
                $options['input'] = $argument;
            }
            continue;
        }

        $parts = explode('=', substr($argument, 2), 2);
        $name = str_replace('-', '_', $parts[0]);
        $value = $parts[1] ?? '1';

        if ( 'node_id' === $name ) {
            $options['node_ids'][] = $value;
            continue;
        }
        if ( 'node_ids' === $name ) {
            foreach ( explode(',', $value) as $nodeId ) {
                $nodeId = trim($nodeId);
                if ( '' !== $nodeId ) {
                    $options['node_ids'][] = $nodeId;
                }
            }
            continue;
        }
        $options[$name] = $value;
    }

    $options['node_ids'] = array_values(array_unique(array_filter(array_map('strval', $options['node_ids']))));
    return $options;
}

function blocks_engine_figma_trace_usage(mixed $stream): void
{
    fwrite($stream, "Usage: figma-node-trace.php <path-to-fig-or-scenegraph-json> --frame-id=<id> --node-ids=<id,id> [--zstd-command=/opt/homebrew/bin/zstd] [--max-kiwi-message-decode-bytes=1] [--max-nodes=5000] [--diagnostic-limit=20]\n");
}

/**
 * @return array<string, mixed>
 */
function blocks_engine_figma_trace_archive_options(array $options): array
{
    return array(
        'include_asset_content' => false,
        'max_kiwi_message_decode_bytes' => max(1, (int) ($options['max_kiwi_message_decode_bytes'] ?? 1)),
    );
}

/**
 * @return array{scenegraph: array<string, mixed>, shape: string, decoded_scenegraph?: array<string, mixed>}
 */
function blocks_engine_figma_trace_read_source(string $input, ?string $zstdCommand, array $archiveOptions, ?array &$archive): array
{
    if ( str_ends_with(strtolower($input), '.json') ) {
        $decoded = is_readable($input) ? json_decode((string) file_get_contents($input), true) : null;
        return array('scenegraph' => is_array($decoded) ? $decoded : array(), 'shape' => 'json');
    }

    $archiveReader = blocks_engine_figma_trace_archive_reader($zstdCommand);
    $archive = $archiveReader->read($input, $archiveOptions);
    $candidate = blocks_engine_figma_trace_decoded_scenegraph_candidate($archive);
    return array(
        'scenegraph' => is_array($candidate['payload'] ?? null) ? $candidate['payload'] : array(),
        'shape' => 'fig',
        'decoded_scenegraph' => is_array($candidate['report'] ?? null) ? $candidate['report'] : array(),
    );
}

function blocks_engine_figma_trace_archive_reader(?string $zstdCommand): FigArchiveReader
{
    if ( null === $zstdCommand || '' === $zstdCommand ) {
        return new FigArchiveReader();
    }

    return new FigArchiveReader(new FigKiwiParser(new ZstdCapability(new ZstdCommandDecoder(array($zstdCommand, '-dc')))));
}

/**
 * @return array<string, mixed>
 */
function blocks_engine_figma_trace_emit_result(array $normalized, array $transformOptions): array
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
        'metrics' => array(
            'node_count' => is_array($normalized['node_map'] ?? null) ? count($normalized['node_map']) : 0,
        ),
        'source_reports' => array(
            'figma' => array(
                'scenegraph' => $normalized['source_report'] ?? array(),
                'html' => is_array($artifact['source_report'] ?? null) ? $artifact['source_report'] : array(),
            ),
        ),
    );
}

/**
 * @return array<string, mixed>|null
 */
function blocks_engine_figma_trace_decoded_scenegraph_candidate(array $archive): ?array
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
        if ( ! is_array($json) || ! blocks_engine_figma_trace_is_scenegraph_payload($json) ) {
            continue;
        }
        $shape = blocks_engine_figma_trace_scenegraph_shape($json);
        $candidates[] = array(
            'payload' => $json,
            'score' => blocks_engine_figma_trace_scenegraph_score($json, $shape),
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

function blocks_engine_figma_trace_is_scenegraph_payload(array $payload): bool
{
    return is_array($payload['NODE_CHANGES'] ?? null)
        || is_array($payload['node_changes'] ?? null)
        || is_array($payload['nodeChanges'] ?? null)
        || is_array($payload['document'] ?? null)
        || is_array($payload['nodes'] ?? null);
}

function blocks_engine_figma_trace_scenegraph_shape(array $payload): string
{
    foreach ( array('NODE_CHANGES', 'node_changes', 'nodeChanges', 'document', 'nodes') as $key ) {
        if ( is_array($payload[$key] ?? null) ) {
            return $key;
        }
    }
    return 'unknown';
}

function blocks_engine_figma_trace_scenegraph_score(array $payload, string $shape): int
{
    $score = 'document' === $shape ? 40 : ('nodes' === $shape ? 30 : 20);
    $index = (new ScenegraphIndex())->build($payload);
    return $score + count(is_array($index['nodes'] ?? null) ? $index['nodes'] : array());
}

/**
 * @param array<string, mixed> $scenegraph
 * @param array<int, string>   $nodeIds
 * @return array<string, array<string, mixed>>
 */
function blocks_engine_figma_trace_find_raw_nodes(array $scenegraph, array $nodeIds): array
{
    $wanted = array_fill_keys(array_map('strval', $nodeIds), true);
    $found = array();
    blocks_engine_figma_trace_collect_raw_nodes($scenegraph, $wanted, $found);
    return $found;
}

/**
 * @param array<string, mixed>                $node
 * @param array<string, true>                 $wanted
 * @param array<string, array<string, mixed>> $found
 */
function blocks_engine_figma_trace_collect_raw_nodes(array $node, array $wanted, array &$found): void
{
    $nodeId = is_scalar($node['id'] ?? null) ? (string) $node['id'] : '';
    if ( '' !== $nodeId && isset($wanted[$nodeId]) ) {
        $found[$nodeId] = $node;
    }

    foreach ( array('NODE_CHANGES', 'node_changes', 'nodeChanges') as $changesKey ) {
        if ( ! is_array($node[$changesKey] ?? null) ) {
            continue;
        }
        foreach ( $node[$changesKey] as $key => $change ) {
            $candidate = is_array($change) && is_array($change['node'] ?? null) ? $change['node'] : $change;
            if ( ! is_array($candidate) ) {
                continue;
            }
            $id = is_scalar($candidate['id'] ?? null) ? (string) $candidate['id'] : (is_scalar($key) ? (string) $key : '');
            if ( '' !== $id && isset($wanted[$id]) ) {
                $found[$id] = $candidate;
            }
            blocks_engine_figma_trace_collect_raw_nodes($candidate, $wanted, $found);
        }
    }

    foreach ( array('document', 'nodes', 'children') as $childrenKey ) {
        $children = $node[$childrenKey] ?? null;
        if ( is_array($children) && array_is_list($children) ) {
            foreach ( $children as $child ) {
                if ( is_array($child) ) {
                    blocks_engine_figma_trace_collect_raw_nodes($child, $wanted, $found);
                }
            }
            continue;
        }
        if ( is_array($children) ) {
            blocks_engine_figma_trace_collect_raw_nodes($children, $wanted, $found);
        }
    }
}

/**
 * @return array<string, array<string, mixed>>
 */
function blocks_engine_figma_trace_normalized_node_lookup(array $normalized): array
{
    $lookup = array();
    foreach ( is_array($normalized['node_map'] ?? null) ? $normalized['node_map'] : array() as $id => $node ) {
        if ( is_array($node) && is_scalar($id) ) {
            $lookup[(string) $id] = $node;
        }
    }

    foreach ( is_array($normalized['nodes'] ?? null) ? $normalized['nodes'] : array() as $node ) {
        if ( is_array($node) ) {
            blocks_engine_figma_trace_collect_normalized_nodes($node, $lookup);
        }
    }

    return $lookup;
}

/**
 * @param array<string, array<string, mixed>> $lookup
 */
function blocks_engine_figma_trace_collect_normalized_nodes(array $node, array &$lookup): void
{
    if ( isset($node['id']) && is_scalar($node['id']) && '' !== (string) $node['id'] ) {
        $lookup[(string) $node['id']] = $node;
    }

    foreach ( is_array($node['children'] ?? null) ? $node['children'] : array() as $child ) {
        if ( is_array($child) ) {
            blocks_engine_figma_trace_collect_normalized_nodes($child, $lookup);
        }
    }
}

/**
 * @param array<string, array<string, mixed>> $normalizedNodes
 * @return array<string, mixed>|null
 */
function blocks_engine_figma_trace_normalized_node(array $normalized, array $normalizedNodes, string $nodeId): ?array
{
    if ( is_array($normalizedNodes[$nodeId] ?? null) ) {
        return $normalizedNodes[$nodeId];
    }

    return is_array($normalized['node_map'][$nodeId] ?? null) ? $normalized['node_map'][$nodeId] : null;
}

function blocks_engine_figma_trace_component_source_id(?array $node): ?string
{
    if ( ! is_array($node) || ! isset($node['figma_component_source_id']) || ! is_scalar($node['figma_component_source_id']) ) {
        return null;
    }

    $sourceId = (string) $node['figma_component_source_id'];
    return '' !== $sourceId ? $sourceId : null;
}

function blocks_engine_figma_trace_node_summary(mixed $node, array $index, string $nodeId): ?array
{
    if ( ! is_array($node) ) {
        return null;
    }
    $box = is_array($node['box'] ?? null) ? $node['box'] : array();
    $layout = is_array($node['layout'] ?? null) ? $node['layout'] : array();
    return array_filter(array(
        'id' => $nodeId,
        'name' => isset($node['name']) && is_scalar($node['name']) ? (string) $node['name'] : null,
        'type' => isset($node['type']) && is_scalar($node['type']) ? strtoupper((string) $node['type']) : null,
        'source_id' => isset($node['figma_component_source_id']) && is_scalar($node['figma_component_source_id']) && '' !== (string) $node['figma_component_source_id'] ? (string) $node['figma_component_source_id'] : null,
        'parent_id' => is_scalar($index['parent_index'][$nodeId] ?? null) ? (string) $index['parent_index'][$nodeId] : null,
        'child_ids' => is_array($index['children_index'][$nodeId] ?? null) ? array_values($index['children_index'][$nodeId]) : array(),
        'box' => ! empty($box) ? $box : blocks_engine_figma_trace_raw_box($node),
        'layout' => ! empty($layout) ? $layout : blocks_engine_figma_trace_raw_layout($node),
        'mask' => blocks_engine_figma_trace_mask_summary($node),
        'text' => blocks_engine_figma_trace_text_summary($node),
        'paints' => blocks_engine_figma_trace_paint_summary($node),
    ), static fn (mixed $value): bool => null !== $value && array() !== $value);
}

function blocks_engine_figma_trace_mask_summary(array $node): array
{
    if ( is_array($node['figma_mask'] ?? null) ) {
        return $node['figma_mask'];
    }

    return array_filter(array(
        'mask' => $node['mask'] ?? null,
        'isMask' => $node['isMask'] ?? null,
        'maskType' => $node['maskType'] ?? null,
    ), static fn (mixed $value): bool => null !== $value);
}

function blocks_engine_figma_trace_raw_box(array $node): array
{
    $box = array();
    foreach ( array('x', 'y', 'width', 'height') as $key ) {
        if ( is_numeric($node[$key] ?? null) ) {
            $box[$key] = (float) $node[$key];
        }
    }
    foreach ( array('absoluteBoundingBox', 'absoluteRenderBounds', 'size') as $key ) {
        if ( is_array($node[$key] ?? null) ) {
            $box[$key] = $node[$key];
        }
    }
    return $box;
}

function blocks_engine_figma_trace_raw_layout(array $node): array
{
    $layout = array();
    foreach ( array('layoutMode', 'layoutPositioning', 'primaryAxisAlignItems', 'counterAxisAlignItems', 'itemSpacing', 'clipsContent') as $key ) {
        if ( isset($node[$key]) && is_scalar($node[$key]) ) {
            $layout[$key] = $node[$key];
        }
    }
    return $layout;
}

function blocks_engine_figma_trace_text_summary(array $node): ?array
{
    $text = is_array($node['figma_text'] ?? null) ? $node['figma_text'] : array();
    $characters = $text['characters'] ?? ($node['characters'] ?? ($node['text'] ?? null));
    if ( ! is_scalar($characters) ) {
        return null;
    }
    return array('characters' => (string) $characters, 'length' => strlen((string) $characters));
}

function blocks_engine_figma_trace_paint_summary(array $node): array
{
    $paints = is_array($node['figma_paints'] ?? null) ? $node['figma_paints'] : (is_array($node['fillPaints'] ?? null) ? $node['fillPaints'] : array());
    return array_map(static function (mixed $paint): array {
        return is_array($paint) ? array_intersect_key($paint, array_flip(array('type', 'color', 'opacity', 'ref', 'imageHash', 'imageName'))) : array();
    }, array_values($paints));
}

function blocks_engine_figma_trace_style_diagnostic(array $htmlReport, string $nodeId): ?array
{
    foreach ( is_array($htmlReport['node_style_diagnostics'] ?? null) ? $htmlReport['node_style_diagnostics'] : array() as $diagnostic ) {
        if ( is_array($diagnostic) && $nodeId === (string) ($diagnostic['node']['id'] ?? '') ) {
            return $diagnostic;
        }
    }
    return null;
}

function blocks_engine_figma_trace_visual_node(array $htmlReport, string $nodeId): ?array
{
    foreach ( is_array($htmlReport['visual_node_map'] ?? null) ? $htmlReport['visual_node_map'] : array() as $node ) {
        if ( is_array($node) && $nodeId === (string) ($node['id'] ?? '') ) {
            return $node;
        }
    }
    return null;
}

function blocks_engine_figma_trace_html_snippet(array $result, string $nodeId): ?string
{
    $html = blocks_engine_figma_trace_file_content($result, 'index.html');
    if ( '' === $html ) {
        return null;
    }
    $quoted = preg_quote('data-figma-node-id="' . htmlspecialchars($nodeId, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '"', '/');
    if ( 1 === preg_match('/<[^>]+\s' . $quoted . '[^>]*>(?:[^<]{0,200})/s', $html, $matches) ) {
        return trim($matches[0]);
    }
    return null;
}

function blocks_engine_figma_trace_css_rule(array $result, string $className): ?string
{
    $css = blocks_engine_figma_trace_file_content($result, 'style.css');
    if ( '' === $css ) {
        return null;
    }
    if ( 1 === preg_match('/\.' . preg_quote($className, '/') . '\{[^}]*\}/', $css, $matches) ) {
        return $matches[0];
    }
    return null;
}

function blocks_engine_figma_trace_file_content(array $result, string $path): string
{
    foreach ( is_array($result['files'] ?? null) ? $result['files'] : array() as $file ) {
        if ( is_array($file) && $path === ($file['path'] ?? null) && is_scalar($file['content'] ?? null) ) {
            return (string) $file['content'];
        }
    }
    return '';
}

function blocks_engine_figma_trace_diagnostics_sample(array $result, array $htmlReport, int $limit): array
{
    $diagnostics = is_array($result['diagnostics'] ?? null) ? $result['diagnostics'] : array();
    $transformDiagnostics = is_array($htmlReport['transform_diagnostics'] ?? null) ? $htmlReport['transform_diagnostics'] : array();
    return array(
        'top_level' => array_slice($diagnostics, 0, max(0, $limit)),
        'transform' => $transformDiagnostics,
    );
}

function blocks_engine_figma_trace_json_encode(array $value): string
{
    return json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR) ?: '{}';
}
