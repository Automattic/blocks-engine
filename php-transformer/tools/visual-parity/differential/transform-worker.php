<?php
/**
 * Long-lived transformer worker for the differential corpus.
 *
 * Reads one JSON request per line on stdin and writes one JSON response per
 * line on stdout, so a whole corpus is transformed by a single PHP process.
 *
 * Request:  {"id": "0001-selectors", "html": "<!doctype html>..."}
 * Response: {"id": "...", "ok": true, "status": "...", "serialized_blocks": "...",
 *            "assets": [...], "editability": {...}|null}
 *       or  {"id": "...", "ok": false, "error": "..."}
 *
 * Diagnostics go to stderr; stdout carries protocol lines only.
 */
declare(strict_types=1);

ini_set('display_errors', 'stderr');
error_reporting(E_ALL);

$root = $argv[1] ?? dirname(__DIR__, 3);
require $root . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

const EDITABILITY_METRICS = array(
    'raw_html_block_count',
    'empty_wrapper_count',
    'max_nesting_depth',
    'generated_geometry_class_count',
    'source_marker_class_count',
    'block_count',
    'wrapper_block_count',
);

/** @param array<string, mixed> $result */
function differential_editability(array $result): ?array
{
    $report = $result['source_reports']['editability_report'] ?? null;
    if ( ! is_array($report) || ! is_array($report['metrics'] ?? null) ) {
        return null;
    }
    $metrics = array( 'schema' => $report['schema'] ?? null );
    foreach ( EDITABILITY_METRICS as $name ) {
        $metrics[$name] = $report['metrics'][$name] ?? null;
    }
    return $metrics;
}

/** @param array<string, mixed> $result */
function differential_assets(array $result): array
{
    $assets = array();
    foreach ( $result['assets'] ?? array() as $asset ) {
        if ( ! is_array($asset) || 'css' !== ($asset['kind'] ?? '') ) {
            continue;
        }
        $assets[] = array(
            'source' => $asset['source'] ?? null,
            'path' => $asset['path'] ?? null,
            'stylesheet_target' => $asset['stylesheet_target'] ?? null,
            'stylesheet_placement' => $asset['stylesheet_placement'] ?? null,
            'media' => $asset['media'] ?? null,
            'content' => (string) ($asset['content'] ?? ''),
        );
    }
    return $assets;
}

function differential_write(array $response): void
{
    fwrite(STDOUT, json_encode($response, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE) . "\n");
    fflush(STDOUT);
}

fwrite(STDOUT, json_encode(array( 'ready' => true, 'php' => PHP_VERSION )) . "\n");
fflush(STDOUT);

while ( false !== ($line = fgets(STDIN)) ) {
    $line = trim($line);
    if ( '' === $line ) {
        continue;
    }
    $request = json_decode($line, true);
    if ( ! is_array($request) || ! is_string($request['html'] ?? null) ) {
        differential_write(array( 'id' => $request['id'] ?? null, 'ok' => false, 'error' => 'Malformed request' ));
        continue;
    }

    ob_start();
    try {
        $result = (new HtmlTransformer())->transform($request['html'])->toArray();
        $stray = ob_get_clean();
        if ( '' !== $stray ) {
            fwrite(STDERR, "transformer wrote to stdout for {$request['id']}: " . substr($stray, 0, 200) . "\n");
        }
        differential_write(array(
            'id' => $request['id'] ?? null,
            'ok' => true,
            'status' => $result['status'] ?? null,
            'serialized_blocks' => (string) ($result['serialized_blocks'] ?? ''),
            'assets' => differential_assets($result),
            'editability' => differential_editability($result),
        ));
    } catch ( Throwable $error ) {
        ob_end_clean();
        differential_write(array(
            'id' => $request['id'] ?? null,
            'ok' => false,
            'error' => get_class($error) . ': ' . $error->getMessage(),
        ));
    }
}
