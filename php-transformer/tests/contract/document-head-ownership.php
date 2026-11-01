<?php
declare(strict_types=1);
/**
 * WordPress renders the document head. The generated theme enqueues the source
 * stylesheets and executable scripts and carries no head replay; every other
 * source head item has a WordPress owner (core, platform fields, site icon) or
 * is reported as an unrendered loss on its page.
 */
require dirname(__DIR__, 2) . '/vendor/autoload.php';
use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\WordPressSitePlan;
$assert = static function (bool $condition, string $message): void {
    if (!$condition) { fwrite(STDERR, "FAIL: {$message}\n"); exit(1); }
};
// Selector-bearing head items (id, class, data-*) used to select a head replay.
$head = '<meta charset="utf-8"><title>Home</title><meta name="viewport" id="vp" content="width=device-width"><meta name="description" content="Home page">'
    . '<meta name="theme-color" class="brand" content="#123456"><meta http-equiv="refresh" content="30"><link rel="icon" href="icon.png"><link rel="preconnect" href="https://fonts.example.test">'
    . '<link rel="stylesheet" id="site-css" href="site.css"><style data-scope="page">h1{margin:0}</style><script type="application/json" id="route-data">{"mode":"home"}</script><script src="app.js" id="app"></script>';
$result = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array(
    'index.html' => '<!doctype html><html><head>' . $head . '</head><body><main><h1>Home</h1></main></body></html>',
    'site.css' => 'h1{color:#123456}',
    'app.js' => 'window.appLoaded=true;',
    'icon.png' => array('content_base64' => 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+a3ioAAAAASUVORK5CYII='),
)))->toArray();
$plan = $result['source_reports']['wordpress_site_plan'] ?? null;
$assert(is_array($plan) && 'failed' !== $result['status'], 'The page compiles to a site plan.');
WordPressSitePlan::assertValid($plan);
$assert(!array_key_exists('head', $plan['pages'][0]['document_metadata']), 'The plan carries no head replay context.');
$bootstrap = array_column($plan['writes'], null, 'target_path')['functions.php']['payload']['data'];
$assert(!str_contains($bootstrap, "add_action( 'wp_head'") && !str_contains($bootstrap, 'wp_dequeue_style') && !str_contains($bootstrap, '_block_template_viewport_meta_tag'), 'The theme prints nothing into wp_head and leaves the core viewport and enqueues alone.');
$enqueued = array();
foreach ($plan['assets'] as $asset) if (str_contains($bootstrap, var_export(implode('/', array_map('rawurlencode', explode('/', $asset['target_path']))), true))) $enqueued[] = $asset['source_path'];
$assert(in_array('site.css', $enqueued, true) && in_array('app.js', $enqueued, true), 'The head stylesheet and script load through theme enqueues: ' . json_encode($enqueued));
$assert(1 === preg_match("/wp_enqueue_script\\( 'blocks-engine-script-/", $bootstrap), 'The head script is enqueued, not skipped as head-owned.');
$drops = array();
foreach ($plan['diagnostics'] as $row) if ('document_head_element_dropped' === $row['code']) $drops[] = array($row['tag'], $row['name'] ?? '', $row['reason_code'], $row['source_path']);
$expected = array(array('meta', 'theme-color', 'unrendered', 'index.html'), array('meta', 'http-equiv=refresh', 'unrendered', 'index.html'), array('link', 'preconnect', 'unrendered', 'index.html'), array('script', 'application/json', 'unrendered', 'index.html'));
sort($drops); sort($expected);
$assert($expected === $drops, 'Exactly the head items with no WordPress owner are reported as unrendered losses: ' . json_encode($drops));
$assert('success_with_warnings' === $plan['quality']['status'], 'Unrendered head items make the plan a success with warnings.');
echo "document-head-ownership contract passed\n";
