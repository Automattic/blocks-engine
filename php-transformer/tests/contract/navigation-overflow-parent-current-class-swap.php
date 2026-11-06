<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\WordPressSitePlan;

$assert = static function (bool $condition, string $message): void { if (! $condition) throw new RuntimeException($message); };

// A site builder marks the served route's link only by swapping utility
// classes: `rest` for `on`, which underlines it. Its overflow menu ("More")
// lists every route, hiding the ones the bar shows, and wears the same
// underline on the routes whose item sits in the overflow. The parent shares
// the served item's state, so it is no evidence that the underline rests:
// every route's current-item paint follows WordPress's runtime current item,
// never fixed item positions, and the parent is current exactly while its
// submenu displays the served item.
$routes = array('index.html' => array('/', 'Home'), 'a/index.html' => array('/a/', 'Route A'), 'b/index.html' => array('/b/', 'Route B'), 'c/index.html' => array('/c/', 'Route C'));
$overflowed = array('Home', 'Route C');
$artifact = array('entrypoint' => 'index.html', 'files' => array());
foreach ($routes as $path => [, $title]) {
    $links = '';
    $panel = '';
    foreach ($routes as [$href, $label]) {
        $state = $label === $title ? 'on' : 'rest';
        $links .= '<li class="item' . (in_array($label, $overflowed, true) ? ' hidden' : '') . '"><a class="link ' . $state . '" href="' . $href . '">' . $label . '</a></li>';
        $panel .= '<li class="sub-item"' . (in_array($label, $overflowed, true) ? '' : ' style="display:none"') . '><a class="sub-link" href="' . $href . '">' . $label . '</a></li>';
    }
    $more = '<li class="item"><span class="more-box"><a class="link-more' . (in_array($title, $overflowed, true) ? ' on' : '') . '" href="#" aria-haspopup="menu">More</a></span><ul class="panel">' . $panel . '</ul></li>';
    $artifact['files'][] = array('path' => $path, 'content' => '<!doctype html><html><head><meta charset="utf-8"><title>' . $title . '</title><style>'
        . '.bar{display:flex;gap:24px}.item{list-style:none}.hidden{display:none}.link,.link-more{color:rgb(20, 20, 20);text-decoration:none}.rest{letter-spacing:1px}'
        . '.on{border-bottom:1px solid;padding-bottom:4px}.panel{display:none}.sub-link{color:rgb(90, 90, 90)}'
        . '</style></head><body><header class="site-header"><h3 class="logo">Acme</h3><nav><ul class="bar">' . $links . $more . '</ul></nav></header>'
        . '<main><h1>' . $title . '</h1><p>' . $title . ' body text.</p></main><footer><p>Copyright Acme.</p></footer></body></html>');
}

$roundTrip = static fn (array $value): array => json_decode(json_encode($value, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);
$compiler = new ArtifactCompiler();
$shared = $roundTrip($compiler->prepareShared($artifact));
$prepared = $roundTrip($compiler->preparePages($artifact, $shared));
$result = $compiler->compose($shared, $roundTrip($compiler->compilePreparedPages($shared, $prepared)))->toArray();
$plan = $result['source_reports']['wordpress_site_plan'];
WordPressSitePlan::assertValid($plan);

$headers = array_values(array_filter($plan['template_parts'], static fn (array $part): bool => 'header' === ($part['area'] ?? null)));
$assert(1 === count($headers), 'Routes sharing one header design share one header part, got ' . count($headers));
$assert(1 === preg_match('/blocks-engine-navigation-instance-[a-f0-9]{12}-\d+/', (string) $headers[0]['canonical_block_markup'], $instance), 'The shared header navigation names its instance');

$underlines = array();
foreach ($result['assets'] as $asset) {
    if ('css' !== ($asset['kind'] ?? null)) continue;
    preg_match_all('/([^{}]+)\{([^{}]*border-bottom[^{}]*)\}/', (string) ($asset['content'] ?? ''), $matches, PREG_SET_ORDER);
    foreach ($matches as [, $selectors]) {
        foreach (explode(',', $selectors) as $selector) if (str_contains($selector, 'blocks-engine-navigation-instance-')) $underlines[] = trim($selector);
    }
}
$positional = array_values(array_filter($underlines, static fn (string $selector): bool => str_contains($selector, ':nth-child(')));
$assert(array() === $positional, 'No route freezes its current-item underline onto fixed item positions: ' . implode(' | ', $positional));
$runtime = array_values(array_filter($underlines, static fn (string $selector): bool => str_contains($selector, $instance[0]) && str_contains($selector, '[aria-current="page"]')));
$assert(array() !== $runtime, 'The shared navigation underlines its runtime current item: ' . implode(' | ', $underlines));

$section = array_values(array_filter(array_map(static fn (string $json): ?array => json_decode($json, true), preg_match_all('/<!-- wp:navigation-submenu (\{.*?\}) -->/', implode("\n", array_column($plan['menus'], 'block_markup')) . (string) $headers[0]['canonical_block_markup'], $submenus) ? $submenus[1] : array()), static fn (?array $attrs): bool => 'More' === trim(strip_tags((string) ($attrs['label'] ?? '')))));
$assert(array() !== $section && true === ($section[0]['metadata']['blocksEngineCurrentSection'] ?? null), 'The overflow parent records that it is the served item\'s section');

// The generated runtime marks the parent current only while its submenu
// displays the served item.
$bootstrap = '';
foreach ($plan['writes'] as $write) if ('functions.php' === ($write['path'] ?? $write['target_path'] ?? '')) $bootstrap = (string) $write['payload']['data'];
$hook = strpos($bootstrap, "add_filter( 'render_block_core/navigation-submenu', static function");
$assert(false !== $hook, 'Generated theme registers the submenu current-state filter');
$start = strpos($bootstrap, 'static function', $hook);
$submenu = eval('return ' . substr($bootstrap, $start, strpos($bootstrap, "\n}, 10, 2 );", $start) + 2 - $start) . ';');
function is_front_page(): bool { return false; }
function get_post_type(int $id): string { return 'page'; }
function get_page_uri(int $id): string { return 'elsewhere'; }
function get_queried_object_id(): int { return 0; }
function is_singular(): bool { return false; }
function apply_filters(string $hook, mixed $value): mixed { return $value; }
foreach (array('Route B' => false, 'Route C' => true, 'none' => false) as $served => $expected) {
    $items = '';
    foreach ($routes as [$href, $label]) {
        $items .= '<li class="wp-block-navigation-item wp-block-navigation-link"' . (in_array($label, $overflowed, true) ? '' : ' style="display: none"') . '><a class="wp-block-navigation-item__content"' . ($label === $served ? ' aria-current="page"' : '') . ' href="' . $href . '">' . $label . '</a></li>';
    }
    $html = '<li class="wp-block-navigation-item has-child wp-block-navigation-submenu"><div><a class="wp-block-navigation-item__content" href="#">More</a></div><ul class="wp-block-navigation__submenu-container">' . $items . '</ul></li>';
    $out = $submenu($html, array('attrs' => $section[0]));
    $assert($expected === (1 === preg_match('/^<li\b[^>]*\bclass="[^"]*\bcurrent-menu-item\b/', $out)), "Overflow parent current state while serving $served");
    $assert(!str_contains(substr($out, 0, (int) strpos($out, '<ul')), 'aria-current'), "The overflow parent is a section, not a link to the page, while serving $served");
}
$assert(1 === preg_match('/' . preg_quote($instance[0], '/') . '[^{]*\.wp-block-navigation-submenu\.current-menu-item[^{]*\{[^}]*border-bottom/', implode("\n", array_column($result['assets'], 'content'))), 'The current section parent wears the current-item underline');

echo "navigation overflow parent current class swap: ok\n";
