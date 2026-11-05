<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\WordPressSitePlan;

$assert = static function (bool $condition, string $message): void { if (! $condition) throw new RuntimeException($message); };

// A site builder's header: a bar of links plus a "More" overflow submenu
// that lists every link again, tracking attributes and ids numbered per
// route, the served route's link marked only by a class swap, and the More
// trigger underlined (extra anchor classes) when the served route is one of
// the overflowed links. The front page has a header of its own design whose
// links rest differently.
// The three routes share one header design, so they share one header part.
$link = static fn (string $href, string $label, string $route, string $copy): string => '<li><a href="' . $href . '" class="' . ($href === $route ? "{$copy}-on" : "{$copy}-off") . '">' . $label . '</a></li>';
$page = static function (string $route, string $title, int $number, bool $overflowed) use ($link): string {
    $links = array('/' => 'Home', '/a/' => 'Route A', '/b/' => 'Route B', '/c/' => 'Route C');
    $bar = ''; $drawer = '';
    foreach ($links as $href => $label) { $bar .= $link($href, $label, $route, '/' === $route ? 'hero' : 'bar'); $drawer .= $link($href, $label, $route, 'drawer'); }
    $header = '/' === $route
        ? '<header class="hero"><div class="hero-inner"><a href="/" class="brand">Acme</a><nav><ul>' . $bar . '</ul></nav></div></header>'
        : '<header class="bar"><a href="/" id="logo-' . $number . '" data-track="Logo.Link.' . $number . '.click" class="brand"><p>Acme</p></a>'
            . '<nav><ul>' . $bar . '<li class="more"><a href="#" class="more-link' . ($overflowed ? ' u-line u-pad' : '') . '">More</a><ul>' . $drawer . '</ul></li></ul></nav></header>';
    return '<!doctype html><html><head><meta charset="utf-8"><title>' . $title . '</title><link rel="stylesheet" href="/style.css"></head><body>'
        . $header . '<main class="content"><h1>' . $title . '</h1><p>' . $title . ' body text.</p></main>'
        . '<footer class="foot"><p>Copyright 2026 Acme. All rights reserved.</p></footer></body></html>';
};
$plan = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array(
    'index.html' => $page('/', 'Welcome home', 101, false),
    'a/index.html' => $page('/a/', 'Route A', 202, false),
    'b/index.html' => $page('/b/', 'Route B', 303, true),
    'c/index.html' => $page('/c/', 'Route C', 404, true),
    'style.css' => '.bar,.hero{display:flex;padding:16px;background:#222}.hero-inner{display:flex;gap:8px}.brand{color:#fff}'
        . '.hero-off{color:#eee}.hero-on{color:#0ff}.bar-off{color:#fff}.bar-on{color:#ff0}.drawer-off{color:#ccc}.drawer-on{color:#f0f}.more-link{color:#fff}.u-line{border-bottom:1px solid}.u-pad{padding-bottom:4px}',
)))->toArray()['source_reports']['wordpress_site_plan'];
WordPressSitePlan::assertValid($plan);

$headers = array_values(array_filter($plan['template_parts'], static fn(array $part): bool => 'header' === ($part['area'] ?? null)));
$assert(1 === count($headers), 'Routes sharing one header design share one header part, got ' . count($headers));
$adopted = $headers[0]['placement']['source_paths'] ?? array_keys($headers[0]['provenance']['sources'] ?? array());
sort($adopted);
$assert(array('a/index.html', 'b/index.html', 'c/index.html') === $adopted, 'The header part serves the routes of its design: ' . json_encode($adopted));
foreach ($plan['pages'] as $routePage) {
    if ('index.html' === $routePage['source_path']) continue;
    $assert(!str_contains($routePage['canonical_block_markup'], 'wp:navigation'), "{$routePage['source_path']} keeps no inline header.");
}

// No route's state is baked into the shared menu: links and the overflow trigger rest.
$menus = array_values(array_filter(array_column($plan['menus'], 'block_markup'), static fn(string $menu): bool => str_contains($menu, 'wp:navigation-submenu')));
$assert(1 === count($menus), 'One overflow navigation entity serves its routes, got ' . count($menus));
$assert(!str_contains($menus[0], '-on') && !str_contains($menus[0], 'u-line') && !str_contains($menus[0], 'u-pad'), 'Every menu item carries its resting presentation: ' . $menus[0]);
$assert(str_contains($menus[0], 'more-link') && str_contains($menus[0], 'drawer-off'), 'The overflow trigger and its links keep their own resting classes.');
echo "overflow-menu-route-state-shared-header: ok\n";
