#!/usr/bin/env php
<?php

declare(strict_types=1);

const ACCEPTANCE_SCHEMA = 'blocks-engine/figma-wordpress-acceptance/v1';
const SITE_PLAN_SCHEMA = 'blocks-engine/wordpress-site-plan/v1';
const FIXTURE_IDS = array('fse-pilot-build-theme', 'twenty-twenty-five-community', 'fisiostetic');
const STAGES = array('decode', 'normalize', 'emit', 'import', 'editor_validity', 'fallback', 'desktop_parity', 'mobile_parity', 'responsive_selection');

$autoload = dirname(__DIR__) . '/php-transformer/vendor/autoload.php';
if (is_readable($autoload)) {
    require_once $autoload;
}

$options = acceptance_options($argv);
if (isset($options['help'])) {
    acceptance_help();
    exit(0);
}

$manifestPath = $options['manifest'] ?? '';
if ('' === $manifestPath || !is_readable($manifestPath)) {
    fwrite(STDERR, "A readable --manifest=path is required. Run with --help for the contract.\n");
    exit(2);
}
$manifest = json_decode((string) file_get_contents($manifestPath), true);
if (!is_array($manifest)) {
    fwrite(STDERR, "Manifest must be valid JSON.\n");
    exit(2);
}

$output = $options['output'] ?? 'artifacts/figma-wordpress-acceptance';
$output = rtrim($output, '/');
if (!is_dir($output) && !mkdir($output, 0777, true) && !is_dir($output)) {
    fwrite(STDERR, "Unable to create output directory.\n");
    exit(2);
}

$summary = array('schema' => ACCEPTANCE_SCHEMA, 'status' => 'failed', 'fixtures' => array(), 'failure_count' => 0);
$fixtures = is_array($manifest['fixtures'] ?? null) ? $manifest['fixtures'] : array();
$byId = array();
foreach ($fixtures as $fixture) {
    if (is_array($fixture) && is_string($fixture['id'] ?? null)) {
        $byId[$fixture['id']] = $fixture;
    }
}

foreach (FIXTURE_IDS as $fixtureId) {
    $fixture = $byId[$fixtureId] ?? array('id' => $fixtureId);
    $fixtureOutput = $output . '/fixtures/' . $fixtureId;
    if (!is_dir($fixtureOutput)) {
        mkdir($fixtureOutput, 0777, true);
    }
    $record = acceptance_fixture($fixture, $fixtureOutput, $output, isset($options['no_run_providers']));
    $summary['fixtures'][] = $record;
    $summary['failure_count'] += count($record['failures']);
}

$summary['status'] = 0 === $summary['failure_count'] ? 'passed' : 'failed';
file_put_contents($output . '/summary.json', json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
echo json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
exit('passed' === $summary['status'] ? 0 : 1);

function acceptance_options(array $argv): array {
    $options = array();
    foreach (array_slice($argv, 1) as $argument) {
        if ('--help' === $argument || '-h' === $argument) {
            $options['help'] = true;
        } elseif ('--no-run-providers' === $argument) {
            $options['no_run_providers'] = true;
        } elseif (str_starts_with($argument, '--') && str_contains($argument, '=')) {
            [$key, $value] = explode('=', substr($argument, 2), 2);
            $options[str_replace('-', '_', $key)] = $value;
        }
    }
    return $options;
}

function acceptance_fixture(array $fixture, string $fixtureOutput, string $artifactRoot, bool $skipProviders): array {
    $id = is_string($fixture['id'] ?? null) ? $fixture['id'] : 'unknown';
    $record = array('id' => $id, 'status' => 'failed', 'stages' => array(), 'failures' => array());
    $input = is_string($fixture['fig'] ?? null) ? $fixture['fig'] : '';
    if ('' === $input || !is_readable($input)) {
        $record['failures'][] = acceptance_failure('decode', 'decode_missing_input');
    }

    $commands = is_array($fixture['provider_commands'] ?? null) ? $fixture['provider_commands'] : array();
    if (isset($fixture['figma_matrix_command'])) {
        $commands = array_merge(array('figma_matrix' => $fixture['figma_matrix_command']), $commands);
    }
    foreach ($commands as $name => $command) {
        if (!$skipProviders && is_string($command) && '' !== trim($command)) {
            $expanded = strtr($command, array('{fixture_output}' => escapeshellarg($fixtureOutput), '{fig}' => escapeshellarg($input)));
            exec($expanded, $ignored, $exitCode);
            if (0 !== $exitCode) {
                $stage = 'figma_matrix' === $name ? 'decode' : (in_array($name, STAGES, true) ? $name : 'import');
                $record['failures'][] = acceptance_failure($stage, $stage . '_provider_failed');
            }
        }
    }

    $evidence = is_array($fixture['evidence'] ?? null) ? $fixture['evidence'] : array();
    foreach (STAGES as $stage) {
        $path = is_string($evidence[$stage] ?? null) ? $evidence[$stage] : '';
        $stageRecord = acceptance_stage($stage, $path, $artifactRoot);
        $record['stages'][$stage] = $stageRecord;
        if ('passed' !== $stageRecord['status']) {
            $record['failures'][] = acceptance_failure($stage, $stageRecord['reason_code']);
        }
    }

    $sitePlan = is_string($fixture['site_plan'] ?? null) ? $fixture['site_plan'] : '';
    if (!acceptance_valid_site_plan($sitePlan)) {
        $record['failures'][] = acceptance_failure('import', 'import_missing_site_plan');
    }
    $record['status'] = empty($record['failures']) ? 'passed' : 'failed';
    return $record;
}

function acceptance_stage(string $stage, string $path, string $output): array {
    $missing = $stage . '_missing_evidence';
    if ('' === $path || !is_readable($path)) {
        return array('status' => 'failed', 'reason_code' => $missing);
    }
    $evidence = json_decode((string) file_get_contents($path), true);
    if (!is_array($evidence) || 'blocks-engine/figma-wordpress-stage-evidence/v1' !== ($evidence['schema'] ?? null)) {
        return array('status' => 'failed', 'reason_code' => $stage . '_invalid_evidence');
    }
    if ('passed' !== ($evidence['status'] ?? null)) {
        return array('status' => 'failed', 'reason_code' => $stage . '_failed');
    }
    if (!acceptance_references_valid($evidence['references'] ?? null, $output)) {
        return array('status' => 'failed', 'reason_code' => $stage . '_unresolvable_evidence');
    }
    if (in_array($stage, array('desktop_parity', 'mobile_parity'), true) && !acceptance_screenshot_proof($evidence, $output)) {
        return array('status' => 'failed', 'reason_code' => $stage . '_missing_screenshots');
    }
    if ('fallback' === $stage && !is_int($evidence['fallback_count'] ?? null)) {
        return array('status' => 'failed', 'reason_code' => 'fallback_missing_count');
    }
    if ('fallback' === $stage && 0 !== $evidence['fallback_count']) {
        return array('status' => 'failed', 'reason_code' => 'fallback_blocks_present');
    }
    if ('responsive_selection' === $stage && !acceptance_responsive_selection($evidence)) {
        return array('status' => 'failed', 'reason_code' => 'responsive_selection_invalid_routes');
    }
    return array('status' => 'passed', 'reason_code' => 'ok', 'references' => array_values($evidence['references']));
}

function acceptance_valid_site_plan(string $path): bool {
    if ('' === $path || !is_readable($path)) {
        return false;
    }
    $plan = json_decode((string) file_get_contents($path), true);
    if (!is_array($plan) || SITE_PLAN_SCHEMA !== ($plan['schema'] ?? null) || !class_exists('Automattic\\BlocksEngine\\PhpTransformer\\WordPressSitePlan\\WordPressSitePlan')) {
        return false;
    }
    try {
        \Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\WordPressSitePlan::assertValid($plan);
        return true;
    } catch (Throwable) {
        return false;
    }
}

function acceptance_screenshot_proof(array $evidence, string $output): bool {
    foreach (array('source_screenshot', 'rendered_screenshot', 'diff_report') as $key) {
        if (!is_string($evidence[$key] ?? null) || !acceptance_reference($evidence[$key]) || !is_file($output . '/' . $evidence[$key])) {
            return false;
        }
    }
    return true;
}

function acceptance_responsive_selection(array $evidence): bool {
    if (!in_array($evidence['selection_source'] ?? null, array('dev_status', 'heuristic_fallback'), true) || !is_array($evidence['responsive_routes'] ?? null) || empty($evidence['responsive_routes'])) {
        return false;
    }
    foreach ($evidence['responsive_routes'] as $route) {
        if (!is_array($route) || !is_string($route['route'] ?? null) || '' === $route['route'] || !is_array($route['source_frames'] ?? null) || count($route['source_frames']) < 2) {
            return false;
        }
    }
    return true;
}

function acceptance_references_valid(mixed $references, string $output): bool {
    if (!is_array($references) || empty($references)) {
        return false;
    }
    foreach ($references as $reference) {
        if (!is_string($reference) || !acceptance_reference($reference) || !is_file($output . '/' . $reference)) {
            return false;
        }
    }
    return true;
}

function acceptance_reference(string $reference): bool {
    return '' !== $reference && !str_starts_with($reference, '/') && !preg_match('#^[A-Za-z]:[\\\\/]#', $reference) && !preg_match('#^(?:https?://)?(?:localhost|127\\.0\\.0\\.1)#i', $reference) && !str_contains($reference, '..');
}

function acceptance_failure(string $stage, string $reason): array {
    return array('stage' => $stage, 'reason_code' => $reason);
}

function acceptance_help(): void {
    echo <<<'HELP'
Usage: php scripts/production-acceptance-matrix.php --manifest=acceptance.json [--output=artifacts/figma-wordpress-acceptance]

The manifest supplies private .fig inputs and generic external provider commands. Provider commands may use {fig} and {fixture_output}; they must write versioned stage evidence and a wordpress-site-plan/v1 JSON file. The generated summary contains only repository-relative evidence references, never input paths or URLs.

Required fixture ids: fse-pilot-build-theme, twenty-twenty-five-community, fisiostetic.
Required stages: decode, normalize, emit, import, editor_validity, fallback, desktop_parity, mobile_parity, responsive_selection.
HELP;
}
