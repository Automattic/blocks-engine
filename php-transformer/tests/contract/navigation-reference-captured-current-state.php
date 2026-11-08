<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\NavigationReferenceItemsRuntime;

$assert = static function (bool $condition, string $message): void { if (! $condition) throw new RuntimeException($message); };

// A site builder emits one atomic utility class per declaration and numbers
// them per route. Resting links pad all sides and pull back with negative
// side margins; the current link pads only its underline. The home page
// wraps its menu in a hero, so it presents differently from the header that
// every other route shares, and each reference records its own items.
$links = array('/' => 'Home', '/about/' => 'About', '/contact/' => 'Contact', '/blog/' => 'Blog');
$resting = array('padding-top:6px', 'padding-right:6px', 'padding-bottom:6px', 'padding-left:6px', 'margin-left:-6px', 'margin-right:-6px');
$current = array('padding-bottom:4px', 'margin-bottom:-5px', 'border-bottom:1px solid #222');
$numbering = static function (string $route) use ($resting, $current): array {
    $atoms = array_values(array_unique(array_merge($resting, $current)));
    if ('/' !== $route) $atoms = array_reverse($atoms);
    $names = array();
    foreach ($atoms as $index => $declaration) $names[$declaration] = 'c1-' . ($index + 1);
    return $names;
};
$menu = static function (string $route) use ($links, $resting, $current, $numbering): string {
    $names = $numbering($route);
    $out = '<nav class="site"><ul class="menu">';
    foreach ($links as $href => $text) {
        $classes = array_map(static fn (string $declaration): string => $names[$declaration], $href === $route ? $current : $resting);
        $out .= '<li><a class="link ' . implode(' ', $classes) . '" href="' . $href . '">' . $text . '</a></li>';
    }
    return $out . '</ul></nav>';
};
$page = static function (string $title, string $route) use ($menu, $numbering): string {
    $css = '.x .menu{display:flex;gap:32px;margin:0;padding:0;list-style:none}.x .link{color:#222;text-decoration:none}.hero{min-height:300px;background:#eee}.promo{color:red}';
    foreach ($numbering($route) as $declaration => $name) $css .= '.x .' . $name . '{' . $declaration . '}';
    $header = '/' === $route ? '<header class="hero"><p class="promo">Big news</p>' . $menu($route) . '<h1>Welcome</h1></header>' : '<header>' . $menu($route) . '</header>';
    return '<!DOCTYPE html><html><head><meta charset="utf-8"><title>' . $title . '</title><style>' . $css . '</style></head><body><div class="x">'
        . $header . '<main><p>' . $title . ' body text.</p></main></div></body></html>';
};
$plan = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array(
    'index.html' => $page('Home', '/'),
    'about/index.html' => $page('About', '/about/'),
    'contact/index.html' => $page('Contact', '/contact/'),
    'blog/index.html' => $page('Blog', '/blog/'),
)))->toArray()['source_reports']['wordpress_site_plan'];

$entities = array_values(array_filter($plan['menus'], static fn (array $menu): bool => is_string($menu['token'] ?? null)));
$assert(1 === count($entities), 'Every route references one navigation entity.');
preg_match_all('/<!--\s*wp:navigation-link\s+(\{.*?\})\s*\/-->/s', $entities[0]['block_markup'], $matches);
$entityItems = array_map(static fn (string $json): array => json_decode($json, true), $matches[1]);
$assert(4 === count($entityItems), 'The entity holds one copy of each link.');

$recorded = array();
foreach (array_merge($plan['template_parts'], $plan['pages']) as $document) {
    preg_match_all('/<!--\s*wp:navigation\s+(\{.*?\})\s*\/-->/s', (string) $document['canonical_block_markup'], $references);
    foreach ($references[1] as $json) {
        $own = json_decode($json, true)['metadata']['blocksEngineNavigationItems'] ?? null;
        if (is_array($own)) $recorded[(string) ($document['slug'] ?? '')] = $own;
    }
}
$assert(isset($recorded['header'], $recorded['home']), 'The shared header and the home hero each record their own items.');
// The header was captured on routes where each of its items rested; the hero
// was captured on the route its first item names.
$assert(array(false, false, false, false) === array_map(static fn (array $item): bool => ! empty($item['current']), $recorded['header']), 'Every header item was captured resting.');
$assert(array(true, false, false, false) === array_map(static fn (array $item): bool => ! empty($item['current']), $recorded['home']), 'The hero records its captured current item.');

// Render the header reference with the generated runtime on each route.
$runtime = NavigationReferenceItemsRuntime::source();
if (! function_exists('add_filter')) {
    function add_filter(string $hook, callable $callback): void { $GLOBALS['__filters'][$hook][] = $callback; }
    function apply_filters(string $hook, $value) { return $value; }
    function is_front_page(): bool { return '/' === $GLOBALS['__served']; }
    function is_singular(): bool { return '/' !== $GLOBALS['__served']; }
    function get_queried_object_id(): int { return '/' === $GLOBALS['__served'] ? 0 : 7; }
    function get_post_type($id): string { return 'page'; }
    function get_page_uri($id): string { return trim($GLOBALS['__served'], '/'); }
}
eval($runtime);
$render = static function (string $route, array $presentation) use ($entityItems): array {
    $GLOBALS['__served'] = $route;
    $blocks = array_map(static function (array $attrs): object {
        $block = new stdClass();
        $block->name = 'core/navigation-link';
        $block->inner_blocks = array();
        $block->parsed_block = array('attrs' => $attrs);
        return $block;
    }, $entityItems);
    $data = $GLOBALS['__filters']['render_block_data'][0];
    $data(array('blockName' => 'core/navigation', 'attrs' => array('metadata' => array('blocksEngineNavigationItems' => $presentation))));
    $blocks = ($GLOBALS['__filters']['block_core_navigation_render_inner_blocks'][0])($blocks);
    ($GLOBALS['__filters']['render_block_core/navigation'][0])('');
    return array_map(static fn (object $block): string => (string) ($block->parsed_block['attrs']['className'] ?? '') . '|' . (string) ($block->parsed_block['attrs']['metadata']['blocksEngineNavigationAnchor']['className'] ?? ''), $blocks);
};
$entityAnchors = array_map(static fn (array $item): string => (string) ($item['className'] ?? '') . '|' . (string) ($item['metadata']['blocksEngineNavigationAnchor']['className'] ?? ''), $entityItems);
$headerAnchors = array_map(static fn (array $item): string => (string) ($item['className'] ?? '') . '|' . (string) ($item['blocksEngineNavigationAnchor']['className'] ?? ''), $recorded['header']);
foreach (array(1, 2, 3) as $position) $assert($entityAnchors[$position] !== $headerAnchors[$position], 'The header records item presentation that differs from the entity item.');
foreach (array('/about/' => 1, '/contact/' => 2, '/blog/' => 3) as $route => $position) {
    $anchors = $render($route, $recorded['header']);
    // The header's resting padding and margins must not reach the served
    // item: the navigation's current-state rules restore its presentation.
    $assert($entityAnchors[$position] === $anchors[$position], "The served item on {$route} keeps the entity's presentation instead of the header's captured resting classes.");
    foreach ($anchors as $index => $anchor) {
        if ($index !== $position) $assert($headerAnchors[$index] === $anchor, "Resting items on {$route} take the header's captured presentation.");
    }
}
// On the hero's own route its captured current item keeps its presentation.
$anchors = $render('/', $recorded['home']);
$assert(array_map(static fn (array $item): string => (string) ($item['className'] ?? '') . '|' . (string) ($item['blocksEngineNavigationAnchor']['className'] ?? ''), $recorded['home']) === $anchors, 'The hero renders every item it captured on its own route.');

echo "Navigation reference captured current state contract passed.\n";
