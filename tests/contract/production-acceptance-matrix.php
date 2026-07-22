<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
require $root . '/php-transformer/vendor/autoload.php';
require_once $root . '/figma-transformer/scripts/figma-fixture-matrix-acceptance.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

$temporary = sys_get_temp_dir() . '/blocks-engine-acceptance-' . bin2hex(random_bytes(4));
mkdir($temporary . '/evidence', 0777, true);

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
};

$parityStages = array(
    'figma_html_desktop_parity' => 'figma_html',
    'figma_html_mobile_parity' => 'figma_html',
    'html_wordpress_desktop_parity' => 'html_wordpress',
    'html_wordpress_mobile_parity' => 'html_wordpress',
    'figma_wordpress_desktop_parity' => 'figma_wordpress',
    'figma_wordpress_mobile_parity' => 'figma_wordpress',
);
$stageNames = array('decode', 'normalize', 'emit', 'figma_html_desktop_parity', 'figma_html_mobile_parity', 'import', 'editor_validity', 'fallback', 'html_wordpress_desktop_parity', 'html_wordpress_mobile_parity', 'figma_wordpress_desktop_parity', 'figma_wordpress_mobile_parity', 'responsive_selection');
$fixtures = array();
$output = $temporary . '/output';
foreach (array('fse-pilot-build-theme', 'twenty-twenty-five-community', 'fisiostetic', 'arbitrary-community-design') as $fixtureId) {
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
        if (isset($parityStages[$stage])) {
            $evidence['comparison'] = $parityStages[$stage];
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
$assert('blocks-engine/figma-wordpress-acceptance/v2' === ($summary['schema'] ?? null), 'summary reports the layered v2 contract');
$assert(3 === count($summary['fixtures'] ?? array()), 'production profile enforces the canonical three fixtures');
$assert(!str_contains(json_encode($summary), $temporary), 'summary excludes private absolute input and evidence paths');

// The fixture matrix emits the Figma-owned stages from actual transform facts;
// SSI-owned stages below remain the truthful downstream provider fixtures.
foreach ($fixtures as $index => $fixture) {
    $fixtureId = $fixture['id'];
    $matrixRoot = $output . '/figma-matrix/' . $fixtureId;
    mkdir($matrixRoot . '/artifacts', 0777, true);
    file_put_contents($matrixRoot . '/result.json', '{}');
    foreach (array('desktop-source.png', 'desktop-rendered.png', 'mobile-source.png', 'mobile-rendered.png') as $artifact) {
        file_put_contents($matrixRoot . '/artifacts/' . $artifact, 'image');
    }
    foreach (array('desktop', 'mobile') as $viewport) {
        file_put_contents($matrixRoot . '/artifacts/' . $viewport . '-diff.json', json_encode(array('metrics' => array('pixel_difference_count' => 0, 'geometry_difference_count' => 0))));
    }
    $result = array(
        'metrics' => array('node_count' => 2, 'page_count' => 1),
        'source_reports' => array('figma' => array(
            'pages' => array('selection_source' => 'dev_status', 'pages' => array(array('path' => 'index.html', 'variants' => array(array('frame_id' => 'desktop', 'device_hint' => 'desktop', 'viewport_width' => 1440), array('frame_id' => 'mobile', 'device_hint' => 'mobile', 'viewport_width' => 375))))),
            'html' => array('transform_diagnostics' => array('artifact_quality' => array('summary' => array('empty_decoded_text_nodes' => 0, 'missing_asset_nodes' => 0, 'vector_placeholders' => 0, 'missing_emitted_text_nodes' => 0, 'source_loss_coverage' => array('domains' => array('images' => array('node_coverage' => array('uncovered_source_nodes' => 0)))))))),
        )),
        'parity' => array('breakpoints' => array(
            array('viewport' => array('device_hint' => 'desktop', 'width' => 1440), 'source' => array('screenshot_path' => $matrixRoot . '/artifacts/desktop-source.png'), 'generated' => array('screenshot_path' => $matrixRoot . '/artifacts/desktop-rendered.png'), 'artifacts' => array('report_path' => $matrixRoot . '/artifacts/desktop-diff.json')),
            array('viewport' => array('device_hint' => 'mobile', 'width' => 375), 'source' => array('screenshot_path' => $matrixRoot . '/artifacts/mobile-source.png'), 'generated' => array('screenshot_path' => $matrixRoot . '/artifacts/mobile-rendered.png'), 'artifacts' => array('report_path' => $matrixRoot . '/artifacts/mobile-diff.json')),
        )),
    );
    $readiness = matrix_acceptance_readiness($fixtureId, $fixture['fig'], $result, $output, $matrixRoot . '/result.json');
    $stagePaths = matrix_write_acceptance_readiness($readiness, $matrixRoot);
    foreach (array('decode', 'normalize', 'emit', 'figma_html_desktop_parity', 'figma_html_mobile_parity', 'responsive_selection') as $stage) {
        $fixtures[$index]['evidence'][$stage] = $stagePaths[$stage];
    }
}
file_put_contents($manifest, json_encode(array('fixtures' => $fixtures)));
exec($command, $ignored, $matrixProducerExitCode);
$assert(0 === $matrixProducerExitCode, 'fixture-matrix Figma stage evidence is accepted alongside downstream stages');

$manifestCommand = $command . ' --profile=manifest';
exec($manifestCommand, $ignored, $manifestExitCode);
$assert(0 === $manifestExitCode, 'manifest profile accepts arbitrary fixture ids');
$manifestSummary = json_decode((string) file_get_contents($output . '/summary.json'), true);
$assert(4 === count($manifestSummary['fixtures'] ?? array()), 'manifest profile evaluates every supplied fixture');

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

file_put_contents($output . '/artifacts/fse-pilot-build-theme/html_wordpress_desktop_parity-diff.json', json_encode(array('metrics' => array('pixel_difference_count' => 1, 'geometry_difference_count' => 0))));
$runFailure($fixtures, 'html_wordpress_desktop_parity', 'html_wordpress_desktop_parity_nonzero_difference');
file_put_contents($output . '/artifacts/fse-pilot-build-theme/html_wordpress_desktop_parity-diff.json', json_encode(array('metrics' => array('pixel_difference_count' => 0, 'geometry_difference_count' => 0))));

$parity = json_decode((string) file_get_contents($fixtures[0]['evidence']['figma_html_desktop_parity']), true);
$parity['comparison'] = 'html_wordpress';
file_put_contents($fixtures[0]['evidence']['figma_html_desktop_parity'], json_encode($parity));
$runFailure($fixtures, 'figma_html_desktop_parity', 'figma_html_desktop_parity_comparison_mismatch');
$parity['comparison'] = 'figma_html';
file_put_contents($fixtures[0]['evidence']['figma_html_desktop_parity'], json_encode($parity));

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
