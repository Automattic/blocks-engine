<?php
declare(strict_types=1);

$root = dirname(__DIR__, 3);
$temporary = sys_get_temp_dir() . '/blocks-engine-acceptance-' . bin2hex(random_bytes(4));
mkdir($temporary . '/evidence', 0777, true);

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
};

$stageNames = array('decode', 'normalize', 'emit', 'import', 'editor_validity', 'fallback', 'desktop_parity', 'mobile_parity', 'responsive_selection');
$fixtures = array();
$output = $temporary . '/output';
foreach (array('fse-pilot-build-theme', 'twenty-twenty-five-community', 'fisiostetic') as $fixtureId) {
    $fig = $temporary . '/' . $fixtureId . '.fig';
    file_put_contents($fig, 'fixture');
    $paths = array();
    foreach ($stageNames as $stage) {
        $path = $temporary . '/evidence/' . $fixtureId . '-' . $stage . '.json';
        $evidence = array(
            'schema' => 'blocks-engine/figma-wordpress-stage-evidence/v1',
            'status' => 'passed',
            'references' => array('artifacts/' . $fixtureId . '/' . $stage . '.json'),
        );
        $artifactDirectory = $output . '/artifacts/' . $fixtureId;
        if (!is_dir($artifactDirectory)) {
            mkdir($artifactDirectory, 0777, true);
        }
        file_put_contents($artifactDirectory . '/' . $stage . '.json', '{}');
        if ('fallback' === $stage) {
            $evidence['fallback_count'] = 0;
        }
        if (in_array($stage, array('desktop_parity', 'mobile_parity'), true)) {
            $evidence['source_screenshot'] = 'artifacts/' . $fixtureId . '/' . $stage . '-source.png';
            $evidence['rendered_screenshot'] = 'artifacts/' . $fixtureId . '/' . $stage . '-rendered.png';
            $evidence['diff_report'] = 'artifacts/' . $fixtureId . '/' . $stage . '-diff.json';
            file_put_contents($artifactDirectory . '/' . $stage . '-source.png', 'source');
            file_put_contents($artifactDirectory . '/' . $stage . '-rendered.png', 'rendered');
            file_put_contents($artifactDirectory . '/' . $stage . '-diff.json', '{}');
        }
        if ('responsive_selection' === $stage) {
            $evidence['selection_source'] = 'dev_status';
            $evidence['responsive_routes'] = array(array('route' => '/', 'source_frames' => array('desktop-frame', 'mobile-frame')));
        }
        file_put_contents($path, json_encode($evidence));
        $paths[$stage] = $path;
    }
    $sitePlan = $temporary . '/evidence/' . $fixtureId . '-site-plan.json';
    file_put_contents($sitePlan, json_encode(array(
        'schema' => 'blocks-engine/wordpress-site-plan/v1',
        'source' => array('schema' => 'blocks-engine/php-transformer/compiled-site/v1', 'source_hash' => str_repeat('a', 64), 'entry_path' => 'index.html', 'provenance' => array()),
        'pages' => array(), 'templates' => array(), 'template_parts' => array(), 'assets' => array(), 'writes' => array(),
        'routes' => array(), 'navigation_links' => array(), 'menus' => array(), 'asset_rewrite_candidates' => array(),
        'theme' => array(), 'visual_repair' => array(), 'diagnostics' => array(), 'quality' => array('status' => 'completed', 'metrics' => array(), 'fallbacks' => array()),
    )));
    $fixtures[] = array('id' => $fixtureId, 'fig' => $fig, 'site_plan' => $sitePlan, 'evidence' => $paths);
}

$manifest = $temporary . '/manifest.json';
file_put_contents($manifest, json_encode(array('fixtures' => $fixtures)));
$command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($root . '/scripts/production-acceptance-matrix.php') . ' --no-run-providers --manifest=' . escapeshellarg($manifest) . ' --output=' . escapeshellarg($output);
exec($command, $ignored, $exitCode);
$assert(0 === $exitCode, 'all complete fixture evidence passes the acceptance matrix');
$summary = json_decode((string) file_get_contents($output . '/summary.json'), true);
$assert('passed' === ($summary['status'] ?? null), 'summary reports a passing matrix');
$assert(!str_contains(json_encode($summary), $temporary), 'summary excludes private absolute input and evidence paths');

$broken = $fixtures;
unlink($broken[0]['evidence']['mobile_parity']);
file_put_contents($manifest, json_encode(array('fixtures' => $broken)));
exec($command, $ignored, $exitCode);
$assert(1 === $exitCode, 'missing mobile parity proof fails the acceptance matrix');
$summary = json_decode((string) file_get_contents($output . '/summary.json'), true);
$failure = $summary['fixtures'][0]['failures'][0] ?? array();
$assert('mobile_parity' === ($failure['stage'] ?? null), 'missing proof is attributed to the mobile parity stage');
$assert('mobile_parity_missing_evidence' === ($failure['reason_code'] ?? null), 'missing proof uses a stable reason code');

fwrite(STDOUT, "production acceptance matrix contract passed\n");
