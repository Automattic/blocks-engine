#!/usr/bin/env php
<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$figmaRoot = $root . '/figma-transformer';
$options = matrix_options($argv);
$fixtureDir = $options['fixture_dir'] ?? ($figmaRoot . '/fixtures');
$defaultOutputRoot = getenv('HOMEBOY_ARTIFACT_ROOT') ?: sys_get_temp_dir();
$outputDir = $options['output_dir'] ?? ($defaultOutputRoot . '/figma-transformer-fixture-matrix-' . gmdate('Ymd-His'));
$zstdCommand = $options['zstd_command'] ?? (getenv('FIGMA_TRANSFORMER_ZSTD_COMMAND') ?: matrix_default_zstd_command());
$maxNodes = (int) ($options['max_nodes'] ?? 5000);
$maxPages = (int) ($options['max_pages'] ?? 3);
$inspectLimit = (int) ($options['inspect_limit'] ?? 40);
$dryRun = true === ($options['dry_run'] ?? false);
$inspectOnly = true === ($options['inspect_only'] ?? false);
$only = isset($options['only']) ? array_filter(array_map('trim', explode(',', (string) $options['only']))) : array();
$adHocFixtures = matrix_list_option($options['fixture'] ?? array());

$fixtures = matrix_discover_fixtures($fixtureDir);
foreach ( $adHocFixtures as $fixturePath ) {
    $fixtures[] = matrix_fixture_from_path($fixturePath, true);
}

$fixtures = matrix_unique_fixtures($fixtures);

if ( isset($options['frame_ids']) ) {
    $globalFrameIds = array_values(array_filter(array_map('trim', explode(',', (string) $options['frame_ids']))));
    foreach ( $fixtures as $index => $fixture ) {
        $fixtures[$index]['mode'] = 'transform';
        $fixtures[$index]['frame_ids'] = $globalFrameIds;
        $fixtures[$index]['entry_frame_id'] = (string) ($options['entry_frame_id'] ?? ($globalFrameIds[0] ?? ''));
    }
}

if ( ! empty($only) ) {
    $fixtures = array_values(array_filter($fixtures, static fn (array $fixture): bool => in_array($fixture['id'], $only, true)));
}

if ( empty($fixtures) ) {
    fwrite(STDERR, "No fixtures selected.\n");
    exit(1);
}

$summary = array(
    'schema' => 'blocks-engine/figma-transformer/fixture-matrix/v1',
    'fixture_dir' => $fixtureDir,
    'output_dir' => $outputDir,
    'zstd_command' => $zstdCommand,
    'max_nodes' => $maxNodes,
    'max_pages' => $maxPages,
    'inspect_limit' => $inspectLimit,
    'dry_run' => $dryRun,
    'inspect_only' => $inspectOnly,
    'fixtures' => array(),
);

if ( ! $dryRun && ! is_dir($outputDir) && ! mkdir($outputDir, 0777, true) && ! is_dir($outputDir) ) {
    fwrite(STDERR, "Unable to create output directory: {$outputDir}\n");
    exit(1);
}

foreach ( $fixtures as $fixture ) {
    $fixturePath = (string) ($fixture['path'] ?? ($fixtureDir . '/' . $fixture['file']));
    $record = array(
        'id' => $fixture['id'],
        'mode' => $fixture['mode'] ?? 'auto',
        'path' => $fixturePath,
        'exists' => is_file($fixturePath),
    );

    if ( ! is_file($fixturePath) ) {
        $record['status'] = 'missing_fixture';
        $summary['fixtures'][] = $record;
        continue;
    }

    $fixtureOutputDir = $outputDir . '/' . $fixture['id'];
    $inspectPath = $outputDir . '/' . $fixture['id'] . '-inspect.json';
    $resultPath = $outputDir . '/' . $fixture['id'] . '-result.json';
    $inspectCommand = matrix_inspect_command($figmaRoot, $fixturePath, $inspectPath, $zstdCommand, $inspectLimit);
    $record['inspect_command'] = $inspectCommand;
    $record['inspect_path'] = $inspectPath;
    $record['result_path'] = $resultPath;
    $record['artifact_dir'] = $fixtureOutputDir;

    if ( $dryRun ) {
        $record['status'] = 'planned';
        $record['selection'] = isset($fixture['frame_ids']) ? 'manual_frame_ids' : 'auto_from_inspection';
        $summary['fixtures'][] = $record;
        continue;
    }

    $startedAt = microtime(true);
    passthru($inspectCommand, $inspectExitCode);
    $record['inspect_exit_code'] = $inspectExitCode;
    $record['inspect_duration_ms'] = (int) round((microtime(true) - $startedAt) * 1000);
    if ( 0 !== $inspectExitCode || ! is_file($inspectPath) ) {
        $record['status'] = 'inspect_failed';
        $summary['fixtures'][] = $record;
        continue;
    }

    $inspection = json_decode((string) file_get_contents($inspectPath), true);
    $record['inspection'] = matrix_inspection_summary(is_array($inspection) ? $inspection : array());
    $frameIds = isset($fixture['frame_ids']) && is_array($fixture['frame_ids']) ? $fixture['frame_ids'] : matrix_select_frame_ids(is_array($inspection) ? $inspection : array(), $maxPages);
    $record['selected_frame_ids'] = $frameIds;
    $record['selected_frames'] = matrix_selected_frame_records(is_array($inspection) ? $inspection : array(), $frameIds);
    $record['entry_frame_id'] = (string) ($fixture['entry_frame_id'] ?? ($frameIds[0] ?? ''));

    if ( $inspectOnly ) {
        $record['status'] = 'inspected';
        $summary['fixtures'][] = $record;
        continue;
    }

    if ( empty($frameIds) ) {
        $record['status'] = 'no_frame_candidates';
        $summary['fixtures'][] = $record;
        continue;
    }

    $command = matrix_transform_command($figmaRoot, $fixturePath, $frameIds, $record['entry_frame_id'], $fixtureOutputDir, $resultPath, $zstdCommand, $maxNodes);
    $record['command'] = $command;
    passthru($command, $exitCode);
    $record['exit_code'] = $exitCode;
    $record['duration_ms'] = (int) round((microtime(true) - $startedAt) * 1000);
    $record['status'] = 0 === $exitCode ? 'completed' : 'failed';

    if ( 0 === $exitCode && is_file($resultPath) ) {
        $result = json_decode((string) file_get_contents($resultPath), true);
        if ( is_array($result) ) {
            $record['result_status'] = $result['status'] ?? null;
            $record['metrics'] = $result['metrics'] ?? array();
            $diagnostics = $result['source_reports']['figma']['html']['transform_diagnostics'] ?? array();
            if ( is_array($diagnostics) ) {
                $record['diagnostic_codes'] = $diagnostics['diagnostic_codes'] ?? array();
                $record['vector_placeholders'] = $diagnostics['vectors']['placeholders'] ?? null;
                $record['generated_svg_assets'] = $diagnostics['generated_svg_assets'] ?? null;
            }
        }
    }

    $summary['fixtures'][] = $record;
}

if ( ! $dryRun ) {
    file_put_contents($outputDir . '/summary.json', json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
}

echo json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";

function matrix_options(array $argv): array
{
    $options = array();
    foreach ( array_slice($argv, 1) as $arg ) {
        if ( '--dry-run' === $arg ) {
            $options['dry_run'] = true;
            continue;
        }

        if ( '--inspect-only' === $arg ) {
            $options['inspect_only'] = true;
            continue;
        }

        if ( ! str_starts_with($arg, '--') || ! str_contains($arg, '=') ) {
            continue;
        }

        [$name, $value] = explode('=', substr($arg, 2), 2);
        $key = str_replace('-', '_', $name);
        if ( 'fixture' === $key ) {
            $options[$key] ??= array();
            $options[$key][] = $value;
            continue;
        }

        $options[$key] = $value;
    }

    return $options;
}

/**
 * @param mixed $value
 * @return array<int, string>
 */
function matrix_list_option(mixed $value): array
{
    if ( is_array($value) ) {
        return array_values(array_filter(array_map('strval', $value), static fn (string $item): bool => '' !== trim($item)));
    }

    if ( is_scalar($value) && '' !== trim((string) $value) ) {
        return array((string) $value);
    }

    return array();
}

/**
 * @return array<int, array<string, mixed>>
 */
function matrix_discover_fixtures(string $fixtureDir): array
{
    $paths = glob(rtrim($fixtureDir, '/') . '/*.fig') ?: array();
    sort($paths, SORT_NATURAL | SORT_FLAG_CASE);

    return array_map(static fn (string $path): array => matrix_fixture_from_path($path, false), $paths);
}

/**
 * @param array<int, array<string, mixed>> $fixtures
 * @return array<int, array<string, mixed>>
 */
function matrix_unique_fixtures(array $fixtures): array
{
    $unique = array();
    foreach ( $fixtures as $fixture ) {
        $path = isset($fixture['path']) && is_scalar($fixture['path']) ? (string) $fixture['path'] : '';
        $key = (string) ($fixture['id'] ?? '') . '|' . ($path ? (realpath($path) ?: $path) : '');
        $unique[$key] = $fixture;
    }

    return array_values($unique);
}

/**
 * @return array<string, mixed>
 */
function matrix_fixture_from_path(string $path, bool $adHoc): array
{
    $id = matrix_fixture_id($path);
    return array(
        'id' => $id,
        'file' => basename($path),
        'path' => $path,
        'mode' => 'auto',
        'inspect_limit' => 40,
        'ad_hoc' => $adHoc,
    );
}

function matrix_fixture_id(string $path): string
{
    $base = preg_replace('/\.fig$/i', '', basename($path)) ?? basename($path);
    $id = strtolower((string) preg_replace('/[^a-zA-Z0-9]+/', '-', $base));
    return trim($id, '-') ?: 'fixture';
}

function matrix_default_zstd_command(): string
{
    foreach ( array('/opt/homebrew/bin/zstd', '/usr/local/bin/zstd', '/usr/bin/zstd') as $candidate ) {
        if ( is_file($candidate) && is_executable($candidate) ) {
            return $candidate;
        }
    }

    $path = trim((string) shell_exec('command -v zstd 2>/dev/null'));
    return '' !== $path ? $path : 'zstd';
}

function matrix_inspect_command(string $figmaRoot, string $fixturePath, string $resultPath, string $zstdCommand, int $inspectLimit): string
{
    $parts = array(
        escapeshellarg(PHP_BINARY),
        '-d',
        'memory_limit=1536M',
        escapeshellarg($figmaRoot . '/bin/figma-transformer'),
        escapeshellarg($fixturePath),
        '--zstd-command=' . escapeshellarg($zstdCommand),
    );

    $parts[] = '--inspect-frames=' . $inspectLimit;

    return implode(' ', $parts) . ' > ' . escapeshellarg($resultPath);
}

/**
 * @param array<int, string> $frameIds
 */
function matrix_transform_command(string $figmaRoot, string $fixturePath, array $frameIds, string $entryFrameId, string $fixtureOutputDir, string $resultPath, string $zstdCommand, int $maxNodes): string
{
    $parts = array(
        escapeshellarg(PHP_BINARY),
        '-d',
        'memory_limit=1536M',
        escapeshellarg($figmaRoot . '/bin/figma-transformer'),
        escapeshellarg($fixturePath),
        '--zstd-command=' . escapeshellarg($zstdCommand),
        '--multi-page',
        '--frame-ids=' . escapeshellarg(implode(',', $frameIds)),
        '--entry-frame-id=' . escapeshellarg($entryFrameId),
        '--max-nodes=' . $maxNodes,
        '--output-dir=' . escapeshellarg($fixtureOutputDir),
    );

    return implode(' ', $parts) . ' > ' . escapeshellarg($resultPath);
}

/**
 * @param array<string, mixed> $inspection
 * @return array<string, mixed>
 */
function matrix_inspection_summary(array $inspection): array
{
    return array(
        'status' => $inspection['status'] ?? null,
        'node_count' => $inspection['node_count'] ?? null,
        'candidate_count' => $inspection['candidate_count'] ?? null,
        'returned_count' => $inspection['returned_count'] ?? null,
    );
}

/**
 * @param array<string, mixed> $inspection
 * @return array<int, string>
 */
function matrix_select_frame_ids(array $inspection, int $maxPages): array
{
    $candidates = is_array($inspection['candidates'] ?? null) ? $inspection['candidates'] : array();
    usort($candidates, static fn (mixed $a, mixed $b): int => matrix_candidate_rank(is_array($b) ? $b : array()) <=> matrix_candidate_rank(is_array($a) ? $a : array()));

    $selected = array();
    foreach ( $candidates as $candidate ) {
        if ( count($selected) >= $maxPages || ! is_array($candidate) ) {
            break;
        }

        $id = isset($candidate['id']) && is_scalar($candidate['id']) ? (string) $candidate['id'] : '';
        if ( '' === $id || ! matrix_is_page_like_candidate($candidate) ) {
            continue;
        }

        $selected[] = $id;
    }

    if ( empty($selected) ) {
        foreach ( $candidates as $candidate ) {
            if ( count($selected) >= $maxPages || ! is_array($candidate) ) {
                break;
            }

            $id = isset($candidate['id']) && is_scalar($candidate['id']) ? (string) $candidate['id'] : '';
            if ( '' !== $id ) {
                $selected[] = $id;
            }
        }
    }

    return $selected;
}

/**
 * @param array<string, mixed> $inspection
 * @param array<int, string> $frameIds
 * @return array<int, array<string, mixed>>
 */
function matrix_selected_frame_records(array $inspection, array $frameIds): array
{
    $byId = array();
    foreach ( is_array($inspection['candidates'] ?? null) ? $inspection['candidates'] : array() as $candidate ) {
        if ( ! is_array($candidate) || ! isset($candidate['id']) || ! is_scalar($candidate['id']) ) {
            continue;
        }

        $byId[(string) $candidate['id']] = array(
            'id' => (string) $candidate['id'],
            'name' => (string) ($candidate['name'] ?? ''),
            'page' => (string) ($candidate['page']['name'] ?? ''),
            'width' => $candidate['width'] ?? null,
            'height' => $candidate['height'] ?? null,
            'score' => $candidate['score'] ?? null,
            'rank' => matrix_candidate_rank($candidate),
        );
    }

    return array_values(array_filter(array_map(static fn (string $id): ?array => $byId[$id] ?? null, $frameIds)));
}

/**
 * @param array<string, mixed> $candidate
 */
function matrix_candidate_rank(array $candidate): int
{
    $score = isset($candidate['score']) && is_numeric($candidate['score']) ? (int) $candidate['score'] : 0;
    $name = strtolower((string) ($candidate['name'] ?? ''));
    $pageName = strtolower((string) ($candidate['page']['name'] ?? ''));
    $parentType = strtoupper((string) ($candidate['parent']['type'] ?? ''));

    if ( in_array($parentType, array('CANVAS', 'SECTION'), true) ) {
        $score += 100;
    }
    if ( 1 === preg_match('/\b(home|homepage|desktop|page|website|landing|lp|archive|single|blog|theme|build)\b/', $name . ' ' . $pageName) ) {
        $score += 200;
    }
    if ( 1 === preg_match('/\b(style tile|template|preview|core blocks|component|footer|header|menu)\b/', $name) ) {
        $score -= 250;
    }

    return $score;
}

/**
 * @param array<string, mixed> $candidate
 */
function matrix_is_page_like_candidate(array $candidate): bool
{
    $name = strtolower((string) ($candidate['name'] ?? ''));
    $pageName = strtolower((string) ($candidate['page']['name'] ?? ''));
    $width = isset($candidate['width']) && is_numeric($candidate['width']) ? (float) $candidate['width'] : 0.0;
    $height = isset($candidate['height']) && is_numeric($candidate['height']) ? (float) $candidate['height'] : 0.0;
    $textCount = isset($candidate['text_count']) && is_numeric($candidate['text_count']) ? (int) $candidate['text_count'] : 0;
    $parentType = strtoupper((string) ($candidate['parent']['type'] ?? ''));

    if ( 1 === preg_match('/\b(style tile|template|preview|core blocks|component|footer|header|menu)\b/', $name) ) {
        return false;
    }
    if ( 1 === preg_match('/\b(style tile|style tiles|presentation|template|templates|components)\b/', $pageName) ) {
        return false;
    }

    return $width >= 900.0 && $height >= 500.0 && $textCount > 0 && in_array($parentType, array('CANVAS', 'SECTION'), true);
}
