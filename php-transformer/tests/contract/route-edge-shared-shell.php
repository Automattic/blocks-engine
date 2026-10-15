<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\WordPressSitePlan;

$assert = static function (bool $condition, string $message): void { if (! $condition) throw new RuntimeException($message); };

// Site chrome is what repeats at the edges of many routes, whatever its
// landmarks. Here the header is a utility bar plus a banner holding the menu,
// with no <header> element, and each route marks its own menu item current.
// Inner routes open with a page-title band of their own after the chrome.
$routes = array('index' => 'Home', 'about' => 'About', 'visit' => 'Visit', 'events' => 'Events');
$page = static function (string $current) use ($routes): string {
    $menu = '';
    foreach ($routes as $slug => $label) $menu .= '<a href="' . $slug . '.html"' . ($slug === $current ? ' class="active" aria-current="page"' : '') . '>' . $label . '</a>';
    $title = 'index' === $current ? '' : '<section class="page-title"><h1>' . $routes[$current] . '</h1><p>About this ' . $routes[$current] . ' page.</p></section>';
    return '<!doctype html><html><head><title>' . $routes[$current] . '</title><style>.utility{background:#123;color:#fff;font-size:12px}.banner{display:flex;justify-content:space-between;padding:16px}.banner nav a{margin-left:12px}.page-title{padding:40px}footer{background:#eee}</style></head><body>'
        . '<div class="site"><div class="utility"><a href="tel:+15550100">555-0100</a> · <a href="mailto:office@example.test">office@example.test</a></div>'
        . '<div class="banner"><a class="brand" href="index.html">Harbor Parish</a><nav>' . $menu . '</nav></div>'
        . $title
        . '<main><section><h2>' . $routes[$current] . ' welcome</h2><p>' . $routes[$current] . ' body copy.</p></section></main>'
        . '<footer><p>Harbor Parish, 1 Shore Road</p></footer></div></body></html>';
};
$files = array();
foreach (array_keys($routes) as $slug) $files['website/' . $slug . '.html'] = $page($slug);
// A route with a different banner stays page-owned.
$files['website/legacy.html'] = str_replace('Harbor Parish</a>', 'Old Parish</a>', $page('visit'));

$plan = (new ArtifactCompiler())->compile(array('entrypoint' => 'website/index.html', 'files' => $files))->toArray()['source_reports']['wordpress_site_plan'];
WordPressSitePlan::assertValid($plan);
$codes = array_column($plan['diagnostics'], 'code');
$headers = array_values(array_filter($plan['template_parts'], static fn(array $part): bool => 'header' === ($part['area'] ?? null)));
$assert(1 === count($headers), 'The repeated utility bar and banner are one shared header part: ' . json_encode($codes));
$header = (string) $headers[0]['canonical_block_markup'];
$assert(str_contains($header, '555-0100') && str_contains($header, 'Harbor Parish') && str_contains($header, '{{wordpress-site-plan:navigation:'), 'The header part holds both the utility bar and the banner with its menu.');
$assert(in_array('wordpress_site_plan_shell_edge_extracted', $codes, true), 'Edge extraction reports the part it made.');

$templates = array_column($plan['templates'] ?? array(), 'canonical_block_markup', 'slug');
$pages = array_column($plan['pages'], null, 'source_path');
foreach ($routes as $slug => $label) {
    $markup = (string) ($pages['website/' . $slug . '.html']['canonical_block_markup'] ?? '');
    $template = (string) ($templates['index' === $slug ? 'front-page' : 'page-' . $slug] ?? $templates['page'] ?? '');
    $assert(!str_contains($markup, '555-0100') && !str_contains($markup, 'Harbor Parish</a>') && !str_contains($markup, '<!-- wp:navigation '), "{$slug}.html no longer carries the header blocks.");
    $assert(1 === substr_count($markup . $template, '"slug":"' . $headers[0]['slug'] . '"'), "{$slug}.html renders the shared header once.");
    $assert(str_contains($markup, $label . ' body copy.'), "{$slug}.html keeps its content.");
    if ('index' !== $slug) $assert(str_contains($markup, 'About this ' . $label . ' page.'), "{$slug}.html keeps its own title band, which is content, not chrome.");
}
$legacy = (string) ($pages['website/legacy.html']['canonical_block_markup'] ?? '');
$assert(str_contains($legacy, 'Old Parish') && str_contains($legacy, '<!-- wp:navigation '), 'A route with a different banner keeps its own header blocks.');
$assert(!str_contains($header, 'aria-current') && !str_contains($header, '"className":"active"'), 'The shared header freezes no route selection.');

fwrite(STDOUT, "Route edge shared shell contract passed.\n");
