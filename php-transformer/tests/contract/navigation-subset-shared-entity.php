<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\NavigationEntityProjection;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\NavigationReferenceItemsRuntime;

$assert = static function (bool $condition, string $message): void {
    if (! $condition) throw new RuntimeException($message);
};
// "Label=url" overrides the default /label/ destination.
$link = static function (string $label, string $class): string {
    [$label, $url] = array_pad(explode('=', $label, 2), 2, '/' . strtolower($label) . '/');
    return '<!-- wp:navigation-link {"label":"' . $label . '","url":"' . $url . '","className":"' . $class . '"} /-->';
};
$nav = static fn (array $labels, string $class): string => '<!-- wp:navigation {"className":"' . $class . '"} -->' . implode('', array_map(static fn (string $label): string => $link($label, $class . '-item'), $labels)) . '<!-- /wp:navigation -->';
$compile = static function (array $desktop, array $mobile = array('Home', 'Services', 'Book')) use ($nav): array {
    $markup = $nav($desktop, 'desktop') . '<!-- wp:details --><details><summary>Menu</summary>' . $nav($mobile, 'mobile') . '</details><!-- /wp:details -->';
    $projected = NavigationEntityProjection::project(array(), array(array('source_path' => 'header.html', 'area' => 'header', 'title' => 'Header', 'canonical_block_markup' => $markup)), array());
    return array('menus' => $projected['menus'], 'pages' => $projected['pages'], 'template_parts' => $projected['parts']);
};
$plan = $compile(array('Home', 'Services'));
$assert(1 === count($plan['menus']) && 3 === $plan['menus'][0]['items'], 'Desktop and mobile share one three-item entity.');
$references = array();
foreach (array_merge($plan['pages'], $plan['template_parts']) as $document) {
    preg_match_all('/<!--\s*wp:navigation\s+(\{.*?\})\s*\/-->/s', $document['canonical_block_markup'], $matches);
    foreach ($matches[1] as $json) $references[] = json_decode($json, true);
}
$assert(2 === count($references), 'Both navigation occurrences survive.');
foreach ($references as $reference) $assert(str_contains($reference['ref'], $plan['menus'][0]['token']), 'Both occurrences reference the wider entity.');
$narrow = null;
foreach ($references as $reference) {
    $presentation = $reference['metadata'][NavigationEntityProjection::ITEMS_KEY] ?? array();
    if (! empty($presentation[2]['omit'])) $narrow = $presentation;
}
$assert(is_array($narrow) && array('omit' => true, 'label' => 'Book', 'omitUrl' => '/book/') === $narrow[2], 'Narrow reference records the guarded Book omission.');
$assert(2 === count($compile(array('Services', 'Home'))['menus']), 'Reordered items do not fold.');
$assert(2 === count($compile(array('Other', 'Else'))['menus']), 'Disjoint items do not fold.');
$assert(2 === count($compile(array('Home'))['menus']), 'A single-item navigation does not fold.');
// A fragment extra resolves against the serving document, so it is no stable omission identity.
$assert(2 === count($compile(array('Home', 'Services'), array('Home', 'Services', 'Contact=#contact'))['menus']), 'A same-page fragment extra does not fold.');
$assert(2 === count($compile(array('Home', 'Services'), array('Home', 'Services', 'Hours=/visit/#hours'))['menus']), 'A route fragment extra does not fold.');

// Exercise the emitted runtime with core-shaped blocks and a list preserving objects.
function add_filter(string $hook, callable $callback): void { $GLOBALS['subset_filters'][$hook] = $callback; }
function apply_filters(string $hook, $value) { return $value; }
function wp_strip_all_tags(string $value): string { return strip_tags($value); }
function home_url(string $path = ''): string { return 'https://site.example' . $path; }
function is_front_page(): bool { return false; }
function is_singular(): bool { return false; }
function get_queried_object_id(): int { return 0; }
class WP_Block_List extends ArrayObject {}
eval(NavigationReferenceItemsRuntime::source());
$render = static function (array $presentation, string $book = 'Book', string $url = '/book/') {
    $blocks = array();
    foreach (array('Home', 'Services', $book) as $position => $label) {
        $blocks[] = (object) array('name' => 'core/navigation-link', 'inner_blocks' => array(), 'parsed_block' => array('attrs' => array('label' => $label, 'url' => 2 === $position ? $url : '/' . strtolower($label) . '/', 'className' => 'entity')));
    }
    ($GLOBALS['subset_filters']['render_block_data'])(array('blockName' => 'core/navigation', 'attrs' => array('metadata' => array(NavigationEntityProjection::ITEMS_KEY => $presentation))));
    $result = ($GLOBALS['subset_filters']['block_core_navigation_render_inner_blocks'])(new WP_Block_List($blocks));
    ($GLOBALS['subset_filters']['render_block_core/navigation'])('');
    return $result;
};
$rendered = $render($narrow);
$assert(2 === count($rendered), 'Runtime drops Book from the narrow reference.');
foreach ($rendered as $position => $block) $assert(($block->parsed_block['attrs']['className'] ?? '') === ($narrow[$position]['className'] ?? ''), 'Kept items retain their exact reference classes.');
$assert(3 === count($render($narrow, 'Reserve')), 'Renaming an omitted item fails open.');
$assert(3 === count($render($narrow, 'Book', '/reserve/')), 'Changing an omitted URL fails open.');
$assert(3 === count($render(array_slice($narrow, 0, 2))), 'Item-count mismatch keeps the entity untouched.');

// An extra in the middle must not shift positional replay for the last kept item.
$middle = array($narrow[0], array('omit' => true, 'label' => 'Services', 'omitUrl' => '/services/'), array('className' => 'last-reference'));
$rendered = $render($middle);
$assert(2 === count($rendered) && 'last-reference' === $rendered[1]->parsed_block['attrs']['className'], 'Omissions are removed before positional replay.');

echo "Navigation subset shared entity contract passed.\n";
