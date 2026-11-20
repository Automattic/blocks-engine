<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\NavigationEntityProjection;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\NavigationReferenceItemsRuntime;

$assert = static function (bool $condition, string $message): void { if (! $condition) throw new RuntimeException($message); };

// Two header template parts list the same links in two designs: one serves
// the routes a builder captured as separate desktop and mobile documents, the
// other the single-document routes. Each design wraps its items in its own
// boxes and labels, styled only by that part's stylesheet. Both reference one
// navigation entity, whose copy of the items is the first part's design.
$links = array('/' => 'Home', '/about/' => 'About', '/contact/' => 'Contact');
$part = static function (string $design) use ($links): string {
    $items = '';
    foreach ($links as $url => $label) {
        $items .= '<!-- wp:navigation-link ' . json_encode(array(
            'className' => 'menu-item blocks-engine-navigation-item-' . substr(hash('sha256', $design . $url), 0, 12) . '-0',
            'metadata' => array('blocksEngineNavigationAnchor' => array('style' => '', 'className' => '', 'boxes' => array(
                array('tag' => 'div', 'marker' => 'blocks-engine-navigation-box-' . substr(hash('sha256', $design . 'box'), 0, 12) . '-0', 'style' => '', 'className' => 'wrapper'),
            ))),
            'label' => '<span class="label ' . $design . '-label">' . $label . '</span>',
            'url' => $url, 'kind' => 'custom',
        ), JSON_UNESCAPED_SLASHES) . ' /-->';
    }
    return '<!-- wp:group {"tagName":"header"} --><header class="wp-block-group"><!-- wp:navigation {"className":"' . $design . '-menu","overlayMenu":"never"} -->'
        . $items . '<!-- /wp:navigation --></header><!-- /wp:group -->';
};
$projected = NavigationEntityProjection::project(array(), array(
    array('source_path' => 'shared/header#header', 'slug' => 'header', 'area' => 'header', 'title' => 'Header', 'canonical_block_markup' => $part('single')),
    array('source_path' => 'shared/header-devices#header', 'slug' => 'header-devices', 'area' => 'header', 'title' => 'Header', 'canonical_block_markup' => $part('devices')),
), array());
$assert(1 === count($projected['menus']), 'Both header designs reference one navigation entity.');
preg_match_all('/<!--\s*wp:navigation-link\s+(\{.*?\})\s*\/-->/s', $projected['menus'][0]['block_markup'], $matches);
$entityItems = array_map(static fn (string $json): array => json_decode($json, true), $matches[1]);
$assert(str_contains($entityItems[0]['label'], 'single-label'), "The entity holds the first part's design.");

$recorded = array();
foreach ($projected['parts'] as $document) {
    preg_match('/<!--\s*wp:navigation\s+(\{.*?\})\s*\/-->/s', (string) $document['canonical_block_markup'], $reference);
    $recorded[$document['slug']] = json_decode($reference[1], true)['metadata']['blocksEngineNavigationItems'] ?? null;
}
$assert(is_array($recorded['header-devices']), 'The second design records its own items.');
$assert(array(false, false, false) === array_map(static fn (array $item): bool => ! empty($item['current']), $recorded['header-devices']), 'A part captures every item resting.');

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
if (! function_exists('wp_strip_all_tags')) {
    function wp_strip_all_tags(string $text): string { return strip_tags($text); }
}
eval($runtime);
$render = static function (string $route, ?array $presentation) use ($entityItems): array {
    $GLOBALS['__served'] = $route;
    $blocks = array_map(static function (array $attrs): object {
        $block = new stdClass();
        $block->name = 'core/navigation-link';
        $block->inner_blocks = array();
        $block->parsed_block = array('attrs' => $attrs);
        return $block;
    }, $entityItems);
    ($GLOBALS['__filters']['render_block_data'][0])(array('blockName' => 'core/navigation', 'attrs' => array('metadata' => array('blocksEngineNavigationItems' => $presentation))));
    $blocks = ($GLOBALS['__filters']['block_core_navigation_render_inner_blocks'][0])($blocks);
    ($GLOBALS['__filters']['render_block_core/navigation'][0])('');
    return array_map(static fn (object $block): array => $block->parsed_block['attrs'], $blocks);
};
$boxes = static fn (array $anchor): array => array_column($anchor['boxes'] ?? array(), 'marker');

foreach (array('/' => 0, '/about/' => 1, '/contact/' => 2) as $route => $served) {
    $items = $render($route, $recorded['header-devices']);
    foreach ($items as $position => $attrs) {
        $own = $recorded['header-devices'][$position];
        // Boxes and label markup are the design the part renders: only its
        // stylesheet styles them, in either state.
        $assert($boxes($own['blocksEngineNavigationAnchor']) === $boxes($attrs['metadata']['blocksEngineNavigationAnchor'] ?? array()), "Item {$position} on {$route} keeps the second design's boxes.");
        $assert(str_contains((string) $attrs['label'], 'devices-label'), "Item {$position} on {$route} keeps the second design's label markup.");
        // The served item's classes stay the entity's, which the navigation's
        // current rules complete; resting items take the part's own.
        $expected = $position === $served ? (string) ($entityItems[$position]['className'] ?? '') : (string) ($own['className'] ?? '');
        $assert($expected === (string) ($attrs['className'] ?? ''), "Item {$position} on {$route} takes the " . ($position === $served ? "entity's state classes." : "part's own resting classes."));
    }
}
// The part that supplied the entity renders the entity's items unchanged.
$canonical = $render('/about/', $recorded['header']);
foreach ($canonical as $position => $attrs) $assert($boxes($entityItems[$position]['metadata']['blocksEngineNavigationAnchor']) === $boxes($attrs['metadata']['blocksEngineNavigationAnchor'] ?? array()), "The entity's own design keeps its boxes on item {$position}.");

echo "navigation-part-reference-own-structure: ok\n";
