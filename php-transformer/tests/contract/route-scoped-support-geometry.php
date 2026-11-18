<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\ThemeBootstrap;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\WordPressSitePlan;

// Generated support is scoped to the routes it was compiled for (#6458). A
// small route-only sheet used to fold into the site-wide support resource
// wherever its selectors matched nothing else, so every route loaded every
// other route's geometry carriers and support rules, and theme CSS grew with
// the number of imported routes.
$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};
$page = static fn(string $main): string => '<!doctype html><html><head><style>.card{width:301px;padding:13px}.menu-copy{width:420px;padding:9px}</style></head><body><header><a href="/">Brand</a></header><main>' . $main . '</main></body></html>';
$plan = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array(
    'index.html' => $page('<div class="card" style="width:640px;height:300px"><p>Home</p></div>'),
    'menu.html' => $page('<div class="card" style="width:333px;height:222px"><p>Menu</p></div><div class="menu-copy"><p>Dishes</p></div>'),
)))->toArray()['source_reports']['wordpress_site_plan'];
WordPressSitePlan::assertValid($plan);
$assets = array_column($plan['assets'], null, 'target_path');
$pages = array_column($plan['pages'], null, 'source_path');
$css = static function (string $source) use ($plan, $assets): string {
    $loaded = '';
    foreach (ThemeBootstrap::documentStylesheets($plan, $source) as $sheet) $loaded .= ($assets[$sheet['target_path']]['content'] ?? '') . "\n";
    return $loaded;
};
$carriers = static function (string $source) use ($pages): array {
    preg_match_all('/\bbe-inline-geometry-[a-f0-9]{16}(?:-[a-f0-9]{16})?\b/', (string) $pages[$source]['canonical_block_markup'], $matches);
    return array_values(array_unique($matches[0]));
};
$homeCss = $css('index.html');
$menuCss = $css('menu.html');
$assert(array() !== $carriers('index.html') && array() !== $carriers('menu.html'), 'Both routes carry measured geometry.');
foreach ($carriers('index.html') as $class) {
    $assert(str_contains($homeCss, '.' . $class . '{'), 'The home route loads its own geometry.');
    $assert(!str_contains($menuCss, '.' . $class), 'The menu route does not load home geometry.');
}
foreach ($carriers('menu.html') as $class) {
    $assert(str_contains($menuCss, '.' . $class . '{'), 'The menu route loads its own geometry.');
    $assert(!str_contains($homeCss, '.' . $class), 'The home route does not load menu geometry: ' . $class);
}
$assert(str_contains($menuCss, '.menu-copy{box-sizing:content-box}'), 'The menu route loads its own support rule.');
$assert(!str_contains($homeCss, '.menu-copy{box-sizing:content-box}'), 'The home route does not load support for elements only the menu route has.');
$assert(str_contains($homeCss, '.card{box-sizing:content-box}') && str_contains($menuCss, '.card{box-sizing:content-box}'), 'Support both routes need still reaches both.');
echo "Route-scoped support geometry passed\n";
