<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

const PAGE_COUNT = 34;
const STYLESHEET_COUNT = 24;
const ASSET_COUNT = 558;

$files = array();
for ($stylesheet = 0; $stylesheet < STYLESHEET_COUNT; ++$stylesheet) {
    $rules = array();
    for ($rule = 0; $rule < 30; ++$rule) {
        $rules[] = sprintf('.page .card-%d-%d{margin:%dpx;padding:%dpx;color:#123;background:#f7f7f7}', $stylesheet, $rule, $rule % 8, $stylesheet % 6);
    }
    $files[] = array('path' => sprintf('assets/css/site-%02d.css', $stylesheet), 'content' => implode("\n", $rules), 'metadata' => array('compilation' => array('scope' => 'shared')));
}
for ($script = 0; $script < 8; ++$script) {
    $files[] = array('path' => sprintf('assets/js/site-%02d.js', $script), 'content' => sprintf('document.querySelectorAll(".card-%d").forEach(function (card) { card.classList.add("ready"); });', $script), 'metadata' => array('compilation' => array('scope' => 'shared')));
}
for ($asset = 0; $asset < ASSET_COUNT; ++$asset) {
    $files[] = array('path' => sprintf('assets/images/image-%03d.png', $asset), 'content_base64' => base64_encode('production-shaped-image-' . $asset), 'mime_type' => 'image/png', 'metadata' => array('compilation' => array('scope' => 'shared')));
}
for ($page = 0; $page < PAGE_COUNT; ++$page) {
    $stylesheets = array();
    for ($stylesheet = 0; $stylesheet < STYLESHEET_COUNT; ++$stylesheet) {
        $stylesheets[] = sprintf('<link rel="stylesheet" href="assets/css/site-%02d.css">', $stylesheet);
    }
    $cards = array();
    for ($card = 0; $card < 20; ++$card) {
        $cards[] = sprintf('<article class="card-%d card-%d-%d"><h2>Page %d card %d</h2><p>Captured production-shaped content.</p><img src="assets/images/image-%03d.png" alt="Fixture image"></article>', $card % 8, $card % STYLESHEET_COUNT, $card % 30, $page, $card, ($page * 20 + $card) % ASSET_COUNT);
    }
    $path = 0 === $page ? 'index.html' : sprintf('pages/page-%02d.html', $page);
    $files[] = array('path' => $path, 'content' => implode('', $stylesheets) . sprintf('<main class="page page-%d">%s</main><script src="assets/js/site-%02d.js"></script>', $page, implode('', $cards), $page % 8));
}

$artifact = array('entrypoints' => array('index.html'), 'compiler_limits' => array('max_files' => count($files)), 'files' => $files);
$compiler = new ArtifactCompiler();
$startedAt = hrtime(true);
$sharedPlan = $compiler->prepareShared($artifact);
$sharedMs = (hrtime(true) - $startedAt) / 1000000;
$startedAt = hrtime(true);
$pagePlans = $compiler->preparePages($artifact, $sharedPlan);
$pagesMs = (hrtime(true) - $startedAt) / 1000000;
$startedAt = hrtime(true);
$receipts = $compiler->compilePreparedPages($sharedPlan, $pagePlans);
$compileMs = (hrtime(true) - $startedAt) / 1000000;

fwrite(STDOUT, json_encode(array(
    'fixture' => array('files' => count($files), 'pages' => PAGE_COUNT, 'shared_stylesheets' => STYLESHEET_COUNT, 'shared_assets' => ASSET_COUNT),
    'stages_ms' => array('prepare_shared' => $sharedMs, 'prepare_pages' => $pagesMs, 'compile_prepared_pages' => $compileMs),
    'pages' => array_map(static fn (array $receipt): array => array('page_id' => $receipt['page_id'], 'compile_duration_ms' => $receipt['work']['compile_duration_ms']), array_values($receipts)),
    'peak_memory_bytes' => memory_get_peak_usage(true),
), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
