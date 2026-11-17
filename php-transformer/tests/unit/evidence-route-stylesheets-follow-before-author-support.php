<?php
declare(strict_types=1);

/**
 * Before-author engine support stays ahead of a route's author stylesheets
 * when pages carry box-tree evidence.
 *
 * The before-author sheet opens with the cascade-layer order statement
 * (`@layer blocks-engine-reset,…,utilities`) that registers the engine reset
 * layer first. Pages with engine-owned styles placed the route stylesheet
 * table ahead of every global enqueue, so the author's stylesheet registered
 * its own layers first and the engine reset layer landed after `utilities`:
 * synthetic-paragraph margin resets then beat authored spacing utilities
 * (`space-y-2`), collapsing a footer's link column.
 */

require getenv('BLOCKS_ENGINE_AUTOLOAD') ?: dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

$fixture = dirname(__DIR__) . '/fixtures/box-tree';
$html = (string) file_get_contents($fixture . '/index.html');
$tree = json_decode((string) file_get_contents($fixture . '/index.json'), true, 32, JSON_THROW_ON_ERROR);
$html = str_replace('</head>', '<link data-blocks-engine-node-id="styles" rel="stylesheet" href="author.css"></head>', $html);
$html = str_replace('</body>', '<footer><div class="space-y-2"><p>Explore</p><a class="block" href="/shop">Shop</a><a class="block" href="/help">Help</a></div></footer></body>', $html);
$tree['source_hash'] = hash('sha256', $html);
$tree['nodes']['n2']['children'][] = 'styles';
$tree['nodes']['styles'] = array('children' => array(), 'viewports' => $tree['nodes']['n3']['viewports']);

$result = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array(
    'index.html' => $html,
    // A second document links the sheets in another order, so each
    // document's author stylesheets are published as route instances.
    'about.html' => '<html><head><link rel="stylesheet" href="extra.css"><link rel="stylesheet" href="author.css"></head><body><main><p>About</p></main></body></html>',
    'extra.css' => 'main{color:#222}',
    'author.css' => '@layer base,utilities;@layer base{p{margin:0}}@layer utilities{.block{display:block}:where(.space-y-2>:not(:last-child)){margin-block-end:8px}}',
    'box-tree/index.json' => json_encode($tree),
)));
$plan = $result->toWordPressSitePlanView()['wordpress_site_plan'];
if (empty($plan['pages'][0]['document_metadata']['engine_owned_styles'])) {
    throw new RuntimeException('Fixture must carry engine-owned style evidence.');
}
$bootstrap = (string) array_column($plan['writes'], null, 'target_path')['functions.php']['payload']['data'];

$before = $routeLoop = $after = null;
foreach (explode("\n", $bootstrap) as $number => $line) {
    if (null === $before && str_contains($line, 'engine-support-before-author-')) $before = $number;
    if (null === $routeLoop && str_contains($line, 'foreach ( $blocks_engine_style_routes as')) $routeLoop = $number;
    if (null === $after && str_contains($line, 'engine-support-after-author-')) $after = $number;
}
$failures = array();
if (null === $before || null === $routeLoop || null === $after) {
    $failures[] = 'Bootstrap enqueues before-author support, route stylesheets and after-author support.';
} else {
    if ($before > $routeLoop) $failures[] = 'Before-author engine support is enqueued ahead of the route author stylesheets.';
    if ($routeLoop > $after) $failures[] = 'After-author engine support is enqueued after the route author stylesheets.';
}
if (!str_contains($bootstrap, '$blocks_engine_route_styles = true;')) {
    $failures[] = 'An evidence route still disables the global author fallback.';
}
$selection = strpos($bootstrap, '$blocks_engine_style_routes = [];');
$fallback = strpos($bootstrap, '! $blocks_engine_route_styles');
if (false !== $fallback && (false === $selection || $selection > $fallback)) {
    $failures[] = 'Route selection precedes every global fallback guard.';
}

if (array() !== $failures) {
    fwrite(STDERR, 'FAIL: ' . implode(PHP_EOL . 'FAIL: ', $failures) . PHP_EOL);
    exit(1);
}
echo "Evidence route stylesheets follow before-author engine support: passed\n";
