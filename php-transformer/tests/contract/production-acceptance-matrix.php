<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

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
    $sourceHash = hash_file('sha256', $fig);
    $paths = array();
    foreach ($stageNames as $stage) {
        $path = $temporary . '/evidence/' . $fixtureId . '-' . $stage . '.json';
        $evidence = array(
            'schema' => 'blocks-engine/figma-wordpress-stage-evidence/v1',
            'status' => 'passed',
            'fixture_id' => $fixtureId,
            'stage' => $stage,
            'source_sha256' => $sourceHash,
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
        $evidence['metrics'] = match ($stage) {
            'decode' => array('missing_text_count' => 0, 'missing_asset_count' => 0, 'vector_placeholder_count' => 0),
            'normalize' => array('normalized_node_count' => 1),
            'emit' => array('emitted_route_count' => 1, 'missing_emitted_asset_count' => 0, 'missing_emitted_text_count' => 0),
            'import' => array('imported_route_count' => 1),
            'editor_validity' => array('parsed_block_count' => 1, 'native_editable_block_count' => 1, 'invalid_block_count' => 0),
            default => array(),
        };
        if ('import' === $stage) {
            $evidence['isolated_fresh_wordpress_import'] = true;
            $evidence['provider_identity'] = 'generic-provider@1.0.0';
            $evidence['runtime_identity'] = 'wordpress@6.8.0';
        }
        if (in_array($stage, array('desktop_parity', 'mobile_parity'), true)) {
            $evidence['source_screenshot'] = 'artifacts/' . $fixtureId . '/' . $stage . '-source.png';
            $evidence['rendered_screenshot'] = 'artifacts/' . $fixtureId . '/' . $stage . '-rendered.png';
            $evidence['diff_report'] = 'artifacts/' . $fixtureId . '/' . $stage . '-diff.json';
            file_put_contents($artifactDirectory . '/' . $stage . '-source.png', 'source');
            file_put_contents($artifactDirectory . '/' . $stage . '-rendered.png', 'rendered');
            file_put_contents($artifactDirectory . '/' . $stage . '-diff.json', json_encode(array('metrics' => array('pixel_difference_count' => 0, 'geometry_difference_count' => 0))));
        }
        if ('responsive_selection' === $stage) {
            $evidence['selection_source'] = 'dev_status';
            $evidence['responsive_routes'] = array(array('output_route' => '/', 'desktop_source_frame' => 'desktop-frame', 'mobile_source_frame' => 'mobile-frame', 'breakpoint_min_width' => 320, 'breakpoint_max_width' => 1440));
        }
        file_put_contents($path, json_encode($evidence));
        $paths[$stage] = $path;
    }
    $sitePlan = $temporary . '/evidence/' . $fixtureId . '-site-plan.json';
    $result = (new ArtifactCompiler())->compile(array(
        'entrypoint' => 'index.html',
        'files' => array('index.html' => '<main><h1>Home</h1></main>'),
    ))->toArray();
    file_put_contents($sitePlan, json_encode($result['source_reports']['wordpress_site_plan']));
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

$runFailure = static function (array $candidate, string $stage, string $reason) use ($manifest, $command, $output, $assert): void {
    file_put_contents($manifest, json_encode(array('fixtures' => $candidate)));
    exec($command, $ignored, $exitCode);
    $assert(1 === $exitCode, "{$reason} fails the acceptance matrix");
    $summary = json_decode((string) file_get_contents($output . '/summary.json'), true);
    $failure = $summary['fixtures'][0]['failures'][0] ?? array();
    $assert($stage === ($failure['stage'] ?? null), "{$reason} is attributed to {$stage}");
    $assert($reason === ($failure['reason_code'] ?? null), "{$reason} uses a stable reason code");
};

$decode = json_decode((string) file_get_contents($fixtures[0]['evidence']['decode']), true);
unset($decode['metrics']);
file_put_contents($fixtures[0]['evidence']['decode'], json_encode($decode));
$runFailure($fixtures, 'decode', 'decode_missing_metrics');
$decode['metrics'] = array('missing_text_count' => 0, 'missing_asset_count' => 0, 'vector_placeholder_count' => 0);
file_put_contents($fixtures[0]['evidence']['decode'], json_encode($decode));

$normalize = json_decode((string) file_get_contents($fixtures[0]['evidence']['normalize']), true);
$normalize['source_sha256'] = str_repeat('0', 64);
file_put_contents($fixtures[0]['evidence']['normalize'], json_encode($normalize));
$runFailure($fixtures, 'normalize', 'normalize_source_hash_mismatch');
$normalize['source_sha256'] = hash_file('sha256', $fixtures[0]['fig']);
file_put_contents($fixtures[0]['evidence']['normalize'], json_encode($normalize));

file_put_contents($output . '/artifacts/fse-pilot-build-theme/desktop_parity-diff.json', json_encode(array('metrics' => array('pixel_difference_count' => 1, 'geometry_difference_count' => 0))));
$runFailure($fixtures, 'desktop_parity', 'desktop_parity_nonzero_difference');
file_put_contents($output . '/artifacts/fse-pilot-build-theme/desktop_parity-diff.json', json_encode(array('metrics' => array('pixel_difference_count' => 0, 'geometry_difference_count' => 0))));

$editor = json_decode((string) file_get_contents($fixtures[0]['evidence']['editor_validity']), true);
$editor['metrics']['invalid_block_count'] = 1;
file_put_contents($fixtures[0]['evidence']['editor_validity'], json_encode($editor));
$runFailure($fixtures, 'editor_validity', 'editor_validity_invalid_blocks');
$editor['metrics']['invalid_block_count'] = 0;
file_put_contents($fixtures[0]['evidence']['editor_validity'], json_encode($editor));

$plan = json_decode((string) file_get_contents($fixtures[0]['site_plan']), true);
$plan['pages'] = array();
$plan['routes'] = array();
$plan['operations'] = array();
$plan['reporting']['source_documents'] = array();
$plan['reporting']['metrics']['source_document_count'] = 0;
$plan['reporting']['metrics']['block_document_count'] = 0;
file_put_contents($fixtures[0]['site_plan'], json_encode($plan));
$runFailure($fixtures, 'import', 'import_empty_site_plan');

fwrite(STDOUT, "production acceptance matrix contract passed\n");
