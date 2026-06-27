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
$inspectLimit = (int) ($options['inspect_limit'] ?? 100);
$dryRun = true === ($options['dry_run'] ?? false);
$inspectOnly = true === ($options['inspect_only'] ?? false);
$captureDomBoxes = true === ($options['capture_dom_boxes'] ?? false);
$homeboyCommand = (string) ($options['homeboy_command'] ?? (getenv('HOMEBOY_COMMAND') ?: 'homeboy'));
$domBoxProviderCommand = (string) ($options['dom_box_provider_command'] ?? (getenv('HOMEBOY_DOM_BOX_CAPTURE_COMMAND') ?: ''));
$only = isset($options['only']) ? array_filter(array_map('trim', explode(',', (string) $options['only']))) : array();
$adHocFixtures = matrix_list_option($options['fixture'] ?? array());
$fontCssPassthrough = matrix_font_css_passthrough($options);
$evidenceOptions = matrix_evidence_options($options);

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
    'capture_dom_boxes' => $captureDomBoxes,
    'homeboy_command' => $captureDomBoxes ? $homeboyCommand : null,
    'dom_box_provider_command_configured' => $captureDomBoxes ? '' !== $domBoxProviderCommand : null,
    'font_css' => $fontCssPassthrough['summary'],
    'evidence' => $evidenceOptions['summary'],
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
    $record['evidence'] = matrix_fixture_evidence($evidenceOptions, $fixture, array());

    if ( $dryRun ) {
        $record['status'] = 'planned';
        $record['selection'] = isset($fixture['frame_ids']) ? 'manual_frame_ids' : 'auto_from_inspection';
        $hasDryRunFrameIds = isset($fixture['frame_ids']) && is_array($fixture['frame_ids']);
        $dryRunFrameIds = $hasDryRunFrameIds ? $fixture['frame_ids'] : array('<selected-frame-ids>');
        $dryRunEntryFrameId = (string) ($fixture['entry_frame_id'] ?? ($hasDryRunFrameIds ? ($dryRunFrameIds[0] ?? '') : '<entry-frame-id>'));
        $record['evidence'] = matrix_fixture_evidence($evidenceOptions, $fixture, matrix_dry_run_pages($dryRunFrameIds));
        if ( $captureDomBoxes ) {
            $record['dom_box_capture'] = array(
                'status' => 'planned',
                'command' => matrix_dom_box_capture_command($homeboyCommand, $domBoxProviderCommand, $fixtureOutputDir, array('<generated-html-entrypoints>'), $fixtureOutputDir . '/dom-boxes.json'),
                'report_path' => $fixtureOutputDir . '/dom-boxes.json',
            );
        }
        $record['command'] = matrix_transform_command($figmaRoot, $fixturePath, $dryRunFrameIds, $dryRunEntryFrameId, $fixtureOutputDir, $resultPath, $zstdCommand, $maxNodes, $fontCssPassthrough['arguments'], $record['evidence']['transform_arguments'] ?? array());
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
    $record['evidence'] = matrix_fixture_evidence($evidenceOptions, $fixture, $record['selected_frames']);

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

    $command = matrix_transform_command($figmaRoot, $fixturePath, $frameIds, $record['entry_frame_id'], $fixtureOutputDir, $resultPath, $zstdCommand, $maxNodes, $fontCssPassthrough['arguments'], $captureDomBoxes ? array() : ($record['evidence']['transform_arguments'] ?? array()));
    $record['command'] = $command;
    passthru($command, $exitCode);
    $record['exit_code'] = $exitCode;
    $record['duration_ms'] = (int) round((microtime(true) - $startedAt) * 1000);
    $record['status'] = 0 === $exitCode ? 'completed' : 'failed';

    if ( 0 === $exitCode && $captureDomBoxes ) {
        $domBoxesPath = $fixtureOutputDir . '/dom-boxes.json';
        $entrypoints = matrix_html_entrypoints($fixtureOutputDir);
        $captureCommand = matrix_dom_box_capture_command($homeboyCommand, $domBoxProviderCommand, $fixtureOutputDir, $entrypoints, $domBoxesPath);
        $record['dom_box_capture'] = array(
            'status' => empty($entrypoints) ? 'no_html_entrypoints' : 'running',
            'command' => $captureCommand,
            'report_path' => $domBoxesPath,
            'entrypoints' => $entrypoints,
        );
        if ( ! empty($entrypoints) ) {
            passthru($captureCommand, $captureExitCode);
            $record['dom_box_capture']['exit_code'] = $captureExitCode;
            $record['dom_box_capture']['exists'] = is_file($domBoxesPath);
            $record['dom_box_capture']['status'] = 0 === $captureExitCode && is_file($domBoxesPath) ? 'completed' : 'failed';
            if ( 'failed' === $record['dom_box_capture']['status'] ) {
                $record['status'] = 'dom_box_capture_failed';
            }
            if ( 0 === $captureExitCode && is_file($domBoxesPath) ) {
                $capturedEvidenceOptions = matrix_merge_evidence_templates($evidenceOptions, array(
                    'dom_boxes_path' => $domBoxesPath,
                ));
                $capturedEvidence = matrix_fixture_evidence($capturedEvidenceOptions, $fixture, $record['selected_frames']);
                $rerunResultPath = $outputDir . '/' . $fixture['id'] . '-result-with-dom-boxes.json';
                $rerunCommand = matrix_transform_command($figmaRoot, $fixturePath, $frameIds, $record['entry_frame_id'], $fixtureOutputDir, $rerunResultPath, $zstdCommand, $maxNodes, $fontCssPassthrough['arguments'], $capturedEvidence['transform_arguments'] ?? array());
                $record['dom_box_rerun_command'] = $rerunCommand;
                passthru($rerunCommand, $rerunExitCode);
                $record['dom_box_rerun_exit_code'] = $rerunExitCode;
                if ( 0 === $rerunExitCode && is_file($rerunResultPath) ) {
                    $resultPath = $rerunResultPath;
                    $record['result_path'] = $resultPath;
                    $record['exit_code'] = $rerunExitCode;
                    $record['evidence'] = $capturedEvidence;
                    $record['status'] = 'completed';
                } else {
                    $record['status'] = 'dom_box_rerun_failed';
                }
            }
        }
    }

    $record['duration_ms'] = (int) round((microtime(true) - $startedAt) * 1000);

    if ( 'completed' === $record['status'] && is_file($resultPath) ) {
        $result = json_decode((string) file_get_contents($resultPath), true);
        if ( is_array($result) ) {
            $result = matrix_attach_evidence_to_result($result, $record['evidence']);
            file_put_contents($resultPath, json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
            $record['result_status'] = $result['status'] ?? null;
            $record['parity'] = matrix_parity_summary(is_array($result['parity'] ?? null) ? $result['parity'] : array());
            $record['metrics'] = $result['metrics'] ?? array();
            $diagnostics = $result['source_reports']['figma']['html']['transform_diagnostics'] ?? array();
            if ( is_array($diagnostics) ) {
                $record['diagnostic_codes'] = $diagnostics['diagnostic_codes'] ?? array();
                $record['vector_placeholders'] = $diagnostics['vectors']['placeholders'] ?? null;
                $record['generated_svg_assets'] = $diagnostics['generated_svg_assets'] ?? null;
                $record['artifact_quality'] = $diagnostics['artifact_quality'] ?? null;
                $record['quality_status'] = $diagnostics['artifact_quality']['quality_status'] ?? null;
                $record['quality_summary'] = $diagnostics['artifact_quality']['summary'] ?? null;
                $record['transform_selection'] = $diagnostics['selection'] ?? null;
                if ( is_array($record['parity'] ?? null) && is_array($record['quality_summary']) ) {
                    $record['parity']['layout_mismatch_count'] = $record['quality_summary']['layout_mismatch_count'] ?? $record['parity']['layout_mismatch_count'];
                    $record['parity']['layout_mismatch_status'] = $record['quality_summary']['layout_mismatch_status'] ?? null;
                }
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

        if ( '--capture-dom-boxes' === $arg ) {
            $options['capture_dom_boxes'] = true;
            continue;
        }

        if ( ! str_starts_with($arg, '--') || ! str_contains($arg, '=') ) {
            continue;
        }

        [$name, $value] = explode('=', substr($arg, 2), 2);
        $key = str_replace('-', '_', $name);
        $key = array(
            'dom_box_command' => 'dom_box_provider_command',
            'homeboy_bin'     => 'homeboy_command',
        )[$key] ?? $key;
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
 * @param array<string, mixed> $options
 * @return array{arguments: array<int, string>, summary: array<string, mixed>}
 */
function matrix_font_css_passthrough(array $options): array
{
    $arguments = array();
    $summary = array('source' => 'none');

    if ( isset($options['font_css']) && is_scalar($options['font_css']) && '' !== (string) $options['font_css'] ) {
        $fontCss = (string) $options['font_css'];
        $arguments[] = '--font-css=' . escapeshellarg($fontCss);
        $summary = array(
            'source' => 'inline',
            'length' => strlen($fontCss),
        );
    }

    if ( isset($options['font_css_file']) && is_scalar($options['font_css_file']) && '' !== (string) $options['font_css_file'] ) {
        $fontCssFile = (string) $options['font_css_file'];
        $arguments[] = '--font-css-file=' . escapeshellarg($fontCssFile);
        $summary = array(
            'source' => 'file',
            'path' => $fontCssFile,
            'exists' => is_file($fontCssFile),
            'readable' => is_readable($fontCssFile),
        );
    }

    return array(
        'arguments' => $arguments,
        'summary' => $summary,
    );
}

/**
 * @param array<string, mixed> $options
 * @return array{templates: array<string, string>, summary: array<string, mixed>}
 */
function matrix_evidence_options(array $options): array
{
    $map = array(
        'parity_report' => 'parity_report_path',
        'parity_report_path' => 'parity_report_path',
        'dom_boxes' => 'dom_boxes_path',
        'dom_boxes_path' => 'dom_boxes_path',
        'layout_report' => 'layout_report_path',
        'layout_report_path' => 'layout_report_path',
        'layout_mismatch_report' => 'layout_mismatch_report_path',
        'layout_mismatch_report_path' => 'layout_mismatch_report_path',
    );
    $templates = array();
    foreach ( $map as $optionKey => $templateKey ) {
        if ( isset($options[$optionKey]) && is_scalar($options[$optionKey]) && '' !== (string) $options[$optionKey] ) {
            $templates[$templateKey] = (string) $options[$optionKey];
        }
    }

    return array(
        'templates' => $templates,
        'summary' => empty($templates) ? array('source' => 'none') : array(
            'source' => 'runner_paths',
            'templates' => $templates,
            'template_tokens' => array('{fixture}', '{id}', '{frame_id}', '{page}', '{slug}'),
        ),
    );
}

/**
 * @param array{templates: array<string, string>, summary: array<string, mixed>} $evidenceOptions
 * @param array<string, string> $templates
 * @return array{templates: array<string, string>, summary: array<string, mixed>}
 */
function matrix_merge_evidence_templates(array $evidenceOptions, array $templates): array
{
    $merged = array_merge($evidenceOptions['templates'], $templates);

    return array(
        'templates' => $merged,
        'summary' => empty($merged) ? array('source' => 'none') : array(
            'source' => 'runner_paths',
            'templates' => $merged,
            'template_tokens' => array('{fixture}', '{id}', '{frame_id}', '{page}', '{slug}'),
        ),
    );
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
 * @param array<int, string> $fontCssArguments
 * @param array<int, string> $evidenceArguments
 */
function matrix_transform_command(string $figmaRoot, string $fixturePath, array $frameIds, string $entryFrameId, string $fixtureOutputDir, string $resultPath, string $zstdCommand, int $maxNodes, array $fontCssArguments = array(), array $evidenceArguments = array()): string
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

    array_push($parts, ...$fontCssArguments);
    array_push($parts, ...$evidenceArguments);

    return implode(' ', $parts) . ' > ' . escapeshellarg($resultPath);
}

/**
 * @param array{templates: array<string, string>, summary: array<string, mixed>} $evidenceOptions
 * @param array<string, mixed> $fixture
 * @param array<int, array<string, mixed>> $pages
 * @return array<string, mixed>
 */
function matrix_fixture_evidence(array $evidenceOptions, array $fixture, array $pages): array
{
    $templates = $evidenceOptions['templates'];
    if ( empty($templates) ) {
        return array('source' => 'none', 'transform_arguments' => array());
    }

    $fixturePaths = array();
    foreach ( $templates as $key => $template ) {
        $fixturePaths[$key] = matrix_resolve_evidence_template($template, $fixture, array());
    }

    $pageRecords = array();
    foreach ( $pages as $page ) {
        $pagePaths = array();
        foreach ( $templates as $key => $template ) {
            $pagePaths[$key] = matrix_resolve_evidence_template($template, $fixture, $page);
        }
        $pageRecords[] = array(
            'frame_id' => (string) ($page['id'] ?? $page['frame_id'] ?? ''),
            'name' => (string) ($page['name'] ?? ''),
            'slug' => (string) ($page['slug'] ?? ''),
            'paths' => matrix_evidence_path_records($pagePaths),
        );
    }

    return array(
        'source' => 'runner_paths',
        'paths' => matrix_evidence_path_records($fixturePaths),
        'pages' => $pageRecords,
        'transform_arguments' => matrix_evidence_transform_arguments($fixturePaths),
    );
}

/**
 * @param array<int, string> $frameIds
 * @return array<int, array<string, mixed>>
 */
function matrix_dry_run_pages(array $frameIds): array
{
    return array_map(static fn (string $frameId): array => array('id' => $frameId, 'name' => $frameId, 'slug' => matrix_slug($frameId)), $frameIds);
}

/**
 * @param array<string, mixed> $fixture
 * @param array<string, mixed> $page
 */
function matrix_resolve_evidence_template(string $template, array $fixture, array $page): string
{
    $frameId = (string) ($page['frame_id'] ?? $page['id'] ?? '');
    $tokens = array(
        '{fixture}' => (string) ($fixture['id'] ?? ''),
        '{id}' => (string) ($fixture['id'] ?? ''),
        '{frame_id}' => '' !== $frameId ? $frameId : (string) ($fixture['entry_frame_id'] ?? ''),
        '{page}' => (string) ($page['name'] ?? ''),
        '{slug}' => (string) ($page['slug'] ?? ('' !== $frameId ? matrix_slug($frameId) : (string) ($fixture['id'] ?? ''))),
    );

    return strtr($template, $tokens);
}

/**
 * @param array<string, string> $paths
 * @return array<string, array<string, mixed>>
 */
function matrix_evidence_path_records(array $paths): array
{
    $records = array();
    foreach ( $paths as $key => $path ) {
        $records[$key] = array(
            'path' => $path,
            'exists' => is_file($path),
            'readable' => is_readable($path),
        );
    }

    return $records;
}

/**
 * @param array<string, string> $paths
 * @return array<int, string>
 */
function matrix_evidence_transform_arguments(array $paths): array
{
    $map = array(
        'parity_report_path' => '--parity-report-path=',
        'dom_boxes_path' => '--parity-dom-boxes-path=',
        'layout_report_path' => '--parity-layout-report-path=',
        'layout_mismatch_report_path' => '--parity-layout-mismatch-report-path=',
    );
    $arguments = array();
    foreach ( $map as $key => $argument ) {
        if ( isset($paths[$key]) && '' !== $paths[$key] ) {
            $arguments[] = $argument . escapeshellarg($paths[$key]);
        }
    }

    return $arguments;
}

/**
 * @return array<int, string>
 */
function matrix_html_entrypoints(string $root): array
{
    if ( ! is_dir($root) ) {
        return array();
    }

    $entrypoints = array();
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
    foreach ( $iterator as $file ) {
        if ( ! $file instanceof SplFileInfo || ! $file->isFile() || 'html' !== strtolower($file->getExtension()) ) {
            continue;
        }
        $path = $file->getPathname();
        $relative = ltrim(substr($path, strlen(rtrim($root, DIRECTORY_SEPARATOR)) + 1), DIRECTORY_SEPARATOR);
        $entrypoints[] = str_replace(DIRECTORY_SEPARATOR, '/', $relative);
    }

    usort($entrypoints, static function (string $a, string $b): int {
        if ( 'index.html' === $a ) {
            return 'index.html' === $b ? 0 : -1;
        }
        if ( 'index.html' === $b ) {
            return 1;
        }
        return strnatcasecmp($a, $b);
    });

    return $entrypoints;
}

/**
 * @param array<int, string> $entrypoints
 */
function matrix_dom_box_capture_command(string $homeboyCommand, string $domBoxProviderCommand, string $root, array $entrypoints, string $reportPath): string
{
    $parts = array(
        escapeshellarg($homeboyCommand),
        'tunnel',
        'artifact-origin',
        'dom-boxes',
        '--root=' . escapeshellarg($root),
        '--report=' . escapeshellarg($reportPath),
    );

    foreach ( $entrypoints as $entrypoint ) {
        $parts[] = '--entrypoint=' . escapeshellarg($entrypoint);
    }

    $command = implode(' ', $parts);
    if ( '' === $domBoxProviderCommand ) {
        return $command;
    }

    return 'HOMEBOY_DOM_BOX_CAPTURE_COMMAND=' . escapeshellarg($domBoxProviderCommand) . ' ' . $command;
}

function matrix_slug(string $value): string
{
    $slug = strtolower((string) preg_replace('/[^a-zA-Z0-9]+/', '-', $value));
    return trim($slug, '-') ?: 'page';
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
 * @param array<string, mixed> $result
 * @param array<string, mixed> $evidence
 * @return array<string, mixed>
 */
function matrix_attach_evidence_to_result(array $result, array $evidence): array
{
    if ( 'runner_paths' !== ($evidence['source'] ?? null) ) {
        return $result;
    }

    $parity = is_array($result['parity'] ?? null) ? $result['parity'] : array();
    $parity['artifacts'] = is_array($parity['artifacts'] ?? null) ? $parity['artifacts'] : array();
    $parity['layout_diagnostics'] = is_array($parity['layout_diagnostics'] ?? null) ? $parity['layout_diagnostics'] : array();
    $paths = is_array($evidence['paths'] ?? null) ? $evidence['paths'] : array();

    foreach ( array(
        'parity_report_path' => 'report_path',
        'dom_boxes_path' => 'dom_boxes_path',
        'layout_report_path' => 'layout_report_path',
        'layout_mismatch_report_path' => 'layout_mismatch_report_path',
    ) as $pathKey => $artifactKey ) {
        $path = matrix_evidence_record_path($paths[$pathKey] ?? null);
        if ( '' !== $path ) {
            $parity['artifacts'][$artifactKey] = $path;
        }
    }

    $parityReport = matrix_read_json_evidence($paths['parity_report_path'] ?? null);
    if ( is_array($parityReport) ) {
        $parity = matrix_merge_parity_report($parity, $parityReport);
    } elseif ( 'not_run' === ($parity['status'] ?? 'not_run') && '' !== matrix_evidence_record_path($paths['parity_report_path'] ?? null) ) {
        $parity['status'] = 'pending';
        $parity['reason'] = 'parity_report_path_supplied';
    }

    $layoutReport = matrix_read_json_evidence($paths['layout_report_path'] ?? null);
    if ( ! is_array($layoutReport) ) {
        $layoutReport = matrix_read_json_evidence($paths['layout_mismatch_report_path'] ?? null);
    }
    if ( is_array($layoutReport) ) {
        $parity['layout_diagnostics'] = array_merge($parity['layout_diagnostics'], matrix_layout_summary($layoutReport));
    }

    $result['parity'] = $parity;
    return $result;
}

/**
 * @param mixed $record
 */
function matrix_evidence_record_path(mixed $record): string
{
    if ( is_array($record) && isset($record['path']) && is_scalar($record['path']) ) {
        return (string) $record['path'];
    }

    return '';
}

/**
 * @param mixed $record
 * @return array<string, mixed>|null
 */
function matrix_read_json_evidence(mixed $record): ?array
{
    $path = matrix_evidence_record_path($record);
    if ( '' === $path || ! is_readable($path) ) {
        return null;
    }

    $data = json_decode((string) file_get_contents($path), true);
    return is_array($data) ? $data : null;
}

/**
 * @param array<string, mixed> $parity
 * @param array<string, mixed> $report
 * @return array<string, mixed>
 */
function matrix_merge_parity_report(array $parity, array $report): array
{
    foreach ( array('status', 'reason', 'viewport') as $key ) {
        if ( isset($report[$key]) ) {
            $parity[$key] = $report[$key];
        }
    }

    foreach ( array('source', 'generated', 'diff', 'diff_summary', 'metrics') as $key ) {
        if ( isset($report[$key]) && is_array($report[$key]) ) {
            $parity[$key] = array_merge(is_array($parity[$key] ?? null) ? $parity[$key] : array(), $report[$key]);
        }
    }

    foreach ( array('pixel_mismatch_count', 'pixel_mismatch_ratio') as $key ) {
        if ( isset($report[$key]) && is_numeric($report[$key]) ) {
            $parity['metrics'][$key] = str_contains((string) $report[$key], '.') ? (float) $report[$key] : (int) $report[$key];
            $parity['diff_summary'][$key] = $parity['metrics'][$key];
        }
    }

    return $parity;
}

/**
 * @param array<string, mixed> $report
 * @return array<string, mixed>
 */
function matrix_layout_summary(array $report): array
{
    $mismatches = is_array($report['mismatches'] ?? null) ? array_values($report['mismatches']) : (is_array($report['diagnostics'] ?? null) ? array_values($report['diagnostics']) : array());
    $topNodes = $report['top_nodes'] ?? $report['top_mismatches'] ?? matrix_layout_top_nodes($mismatches);
    $summary = is_array($report['summary'] ?? null) ? $report['summary'] : array();
    $suspectedCauses = $summary['suspected_causes'] ?? $report['suspected_causes'] ?? array();

    return array(
        'status' => $report['status'] ?? null,
        'mismatch_count' => matrix_first_numeric($summary, array('diagnostic_count', 'layout_mismatch_count', 'mismatch_count', 'count')) ?? matrix_first_numeric($report, array('layout_mismatch_count', 'mismatch_count', 'count')) ?? count($mismatches),
        'top_nodes' => is_array($topNodes) ? array_slice(array_values($topNodes), 0, 5) : array(),
        'suspected_causes' => is_array($suspectedCauses) ? array_slice(array_values($suspectedCauses), 0, 5) : array(),
    );
}

/**
 * @param array<int, mixed> $mismatches
 * @return array<int, array<string, mixed>>
 */
function matrix_layout_top_nodes(array $mismatches): array
{
    $nodes = array();
    foreach ( array_slice($mismatches, 0, 5) as $mismatch ) {
        if ( ! is_array($mismatch) ) {
            continue;
        }
        $node = is_array($mismatch['node'] ?? null) ? $mismatch['node'] : $mismatch;
        $nodes[] = array_filter(array(
            'id' => isset($node['id']) && is_scalar($node['id']) ? (string) $node['id'] : null,
            'name' => isset($node['name']) && is_scalar($node['name']) ? (string) $node['name'] : null,
            'type' => isset($node['type']) && is_scalar($node['type']) ? (string) $node['type'] : null,
            'code' => isset($mismatch['code']) && is_scalar($mismatch['code']) ? (string) $mismatch['code'] : null,
            'max_delta' => isset($mismatch['max_delta']) && is_numeric($mismatch['max_delta']) ? (float) $mismatch['max_delta'] : null,
        ), static fn (mixed $value): bool => null !== $value && '' !== $value);
    }

    return $nodes;
}

/**
 * @param array<string, mixed> $values
 * @param array<int, string> $keys
 */
function matrix_first_numeric(array $values, array $keys): int|float|null
{
    foreach ( $keys as $key ) {
        if ( isset($values[$key]) && is_numeric($values[$key]) ) {
            return str_contains((string) $values[$key], '.') ? (float) $values[$key] : (int) $values[$key];
        }
    }

    return null;
}

/**
 * @param array<string, mixed> $parity
 * @return array<string, mixed>
 */
function matrix_parity_summary(array $parity): array
{
    $metrics = is_array($parity['metrics'] ?? null) ? $parity['metrics'] : array();
    $diffSummary = is_array($parity['diff_summary'] ?? null) ? $parity['diff_summary'] : array();
    $layout = is_array($parity['layout_diagnostics'] ?? null) ? $parity['layout_diagnostics'] : array();

    return array(
        'status' => $parity['status'] ?? 'not_run',
        'pixel_mismatch_count' => $metrics['pixel_mismatch_count'] ?? $diffSummary['pixel_mismatch_count'] ?? null,
        'pixel_mismatch_ratio' => $metrics['pixel_mismatch_ratio'] ?? $diffSummary['pixel_mismatch_ratio'] ?? null,
        'layout_mismatch_count' => $layout['mismatch_count'] ?? null,
        'layout_top_nodes' => is_array($layout['top_nodes'] ?? null) ? array_slice($layout['top_nodes'], 0, 5) : array(),
        'layout_suspected_causes' => is_array($layout['suspected_causes'] ?? null) ? array_slice($layout['suspected_causes'], 0, 5) : array(),
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
    $selectedBuckets = array();
    $deferred = array();
    foreach ( $candidates as $candidate ) {
        if ( count($selected) >= $maxPages || ! is_array($candidate) ) {
            break;
        }

        $id = isset($candidate['id']) && is_scalar($candidate['id']) ? (string) $candidate['id'] : '';
        if ( '' === $id || ! matrix_is_page_like_candidate($candidate) ) {
            continue;
        }

        $bucket = matrix_candidate_bucket($candidate);
        if ( isset($selectedBuckets[$bucket]) ) {
            $deferred[] = $candidate;
            continue;
        }

        $selected[] = $id;
        $selectedBuckets[$bucket] = true;
    }

    foreach ( $deferred as $candidate ) {
        if ( count($selected) >= $maxPages || ! is_array($candidate) ) {
            break;
        }

        $id = isset($candidate['id']) && is_scalar($candidate['id']) ? (string) $candidate['id'] : '';
        if ( '' !== $id && ! in_array($id, $selected, true) ) {
            $selected[] = $id;
        }
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

    return matrix_order_selected_frame_ids($selected, $candidates);
}

/**
 * @param array<int, string> $selected
 * @param array<int, mixed>  $candidates
 * @return array<int, string>
 */
function matrix_order_selected_frame_ids(array $selected, array $candidates): array
{
    $byId = array();
    $positions = array();
    foreach ( $selected as $index => $id ) {
        $positions[$id] = $index;
    }

    foreach ( $candidates as $candidate ) {
        if ( is_array($candidate) && isset($candidate['id']) && is_scalar($candidate['id']) ) {
            $byId[(string) $candidate['id']] = $candidate;
        }
    }

    usort(
        $selected,
        static function (string $left, string $right) use ($byId, $positions): int {
            $leftBucket = isset($byId[$left]) ? matrix_candidate_bucket($byId[$left]) : '';
            $rightBucket = isset($byId[$right]) ? matrix_candidate_bucket($byId[$right]) : '';
            if ( 'homepage' === $leftBucket || 'homepage' === $rightBucket ) {
                return ('homepage' === $leftBucket ? 0 : 1) <=> ('homepage' === $rightBucket ? 0 : 1);
            }

            return ((int) ($positions[$left] ?? 0)) <=> ((int) ($positions[$right] ?? 0));
        }
    );

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
            'bucket' => matrix_candidate_bucket($candidate),
            'selection_reasons' => matrix_candidate_selection_reasons($candidate),
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
    $type = strtoupper((string) ($candidate['type'] ?? ''));
    $parentType = strtoupper((string) ($candidate['parent']['type'] ?? ''));

    if ( 'FRAME' === $type ) {
        $score += 160;
    } elseif ( in_array($type, array('COMPONENT', 'INSTANCE'), true) ) {
        $score -= 400;
    }
    if ( in_array($parentType, array('CANVAS', 'SECTION'), true) ) {
        $score += 100;
    }
    if ( 1 === preg_match('/\b(home|homepage|desktop|page|website|landing|lp|archive|single|blog|theme|build|hosts?|agenc(?:y|ies)|pricing|features?|about|contact)\b/', $name . ' ' . $pageName) ) {
        $score += 200;
    }
    if ( 1 === preg_match('/\b(website comps?|pages?|screens?|final|production|dev handoff)\b/', $pageName) ) {
        $score += 240;
    }
    if ( 1 === preg_match('/\b(for hosts?|for agenc(?:y|ies)|pricing|features?|about|contact)\b/', $name) ) {
        $score += 160;
    }
    if ( 1 === preg_match('/\blp\s*i\s*([0-9]+)\b/', $name, $nameMatches) && 1 === preg_match('/\bi\s*([0-9]+)\b/', $pageName, $pageMatches) && $nameMatches[1] !== $pageMatches[1] ) {
        $score -= 90;
    }
    if ( 1 === preg_match('/\b(style guide|style tile|template|preview|core blocks|component|footer|header|menu)\b/', $name) ) {
        $score -= 250;
    }
    if ( 1 === preg_match('/\b(playground|presentation|addit?ional links?|graphics|imageries|exploration|scratch|old|deprecated|archive copy|copy\b|wip)\b/', $name . ' ' . $pageName) ) {
        $score -= 320;
    }

    return $score;
}

/**
 * @param array<string, mixed> $candidate
 */
function matrix_candidate_bucket(array $candidate): string
{
    $name = strtolower((string) ($candidate['name'] ?? ''));
    $pageName = strtolower((string) ($candidate['page']['name'] ?? ''));
    $haystack = $name . ' ' . $pageName;

    if ( 1 === preg_match('/\blp\s*i\s*([0-9]+)\b/', $haystack, $matches) ) {
        return 'landing:i' . $matches[1];
    }
    if ( 1 === preg_match('/\blp\b.*\bnew\b/', $haystack) ) {
        return 'landing:new';
    }
    if ( 1 === preg_match('/\bi\s*([0-9]+)\b/', $pageName, $matches) && 1 === preg_match('/\blp\b/', $name) ) {
        return 'landing:i' . $matches[1];
    }

    foreach ( array(
        'homepage' => '/\b(home|homepage)\b/',
        'hosts' => '/\bhosts?\b/',
        'agencies' => '/\bagenc(?:y|ies)\b/',
        'pricing' => '/\bpricing\b/',
        'features' => '/\bfeatures?\b/',
        'about' => '/\babout\b/',
        'contact' => '/\bcontact\b/',
        'archive' => '/\barchive\b/',
        'single' => '/\b(single|post page)\b/',
        'theme' => '/\b(theme|build)\b/',
        'landing' => '/\b(landing|lp)\b/',
    ) as $bucket => $pattern ) {
        if ( 1 === preg_match($pattern, $haystack) ) {
            return $bucket;
        }
    }

    $normalized = preg_replace('/\b(desktop|mobile|tablet|copy|wip|new|old|v\d+|i\d+)\b/', '', $name) ?? $name;
    $normalized = trim(preg_replace('/[^a-z0-9]+/', '-', $normalized) ?? '');
    return '' !== $normalized ? 'frame:' . $normalized : 'generic';
}

/**
 * @param array<string, mixed> $candidate
 * @return array<int, string>
 */
function matrix_candidate_selection_reasons(array $candidate): array
{
    $reasons = array();
    $name = strtolower((string) ($candidate['name'] ?? ''));
    $pageName = strtolower((string) ($candidate['page']['name'] ?? ''));
    $type = strtoupper((string) ($candidate['type'] ?? ''));
    $parentType = strtoupper((string) ($candidate['parent']['type'] ?? ''));

    if ( 'FRAME' === $type ) {
        $reasons[] = 'transformable_frame';
    }
    if ( in_array($parentType, array('CANVAS', 'SECTION'), true) ) {
        $reasons[] = 'top_level_candidate';
    }
    if ( 1 === preg_match('/\b(home|homepage|website|landing|lp|archive|single|blog|theme|build|hosts?|agenc(?:y|ies)|pricing|features?|about|contact)\b/', $name . ' ' . $pageName) ) {
        $reasons[] = 'page_like_name';
    }
    if ( 1 === preg_match('/\b(website comps?|pages?|screens?|final|production|dev handoff)\b/', $pageName) ) {
        $reasons[] = 'page_collection';
    }

    return $reasons;
}

/**
 * @param array<string, mixed> $candidate
 */
function matrix_is_page_like_candidate(array $candidate): bool
{
    $name = strtolower((string) ($candidate['name'] ?? ''));
    $pageName = strtolower((string) ($candidate['page']['name'] ?? ''));
    $type = strtoupper((string) ($candidate['type'] ?? ''));
    $width = isset($candidate['width']) && is_numeric($candidate['width']) ? (float) $candidate['width'] : 0.0;
    $height = isset($candidate['height']) && is_numeric($candidate['height']) ? (float) $candidate['height'] : 0.0;
    $textCount = isset($candidate['text_count']) && is_numeric($candidate['text_count']) ? (int) $candidate['text_count'] : 0;
    $parentType = strtoupper((string) ($candidate['parent']['type'] ?? ''));

    if ( 'FRAME' !== $type ) {
        return false;
    }
    if ( 1 === preg_match('/\b(style guide|style tile|template|preview|core blocks|component|footer|header|menu)\b/', $name) ) {
        return false;
    }
    if ( 1 === preg_match('/\b(style guide|style tile|style tiles|presentation|template|templates|components)\b/', $pageName) ) {
        return false;
    }

    return $width >= 900.0 && $height >= 500.0 && $textCount > 0 && in_array($parentType, array('CANVAS', 'SECTION'), true);
}
