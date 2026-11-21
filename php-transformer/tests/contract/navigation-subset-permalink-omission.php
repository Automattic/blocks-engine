<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\NavigationEntityProjection;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\NavigationReferenceItemsRuntime;

// Importers rewrite a folded entity's internal URLs to permalinks; the narrow
// reference must still drop exactly the recorded extras (#7285).
$assert = static function (bool $condition, string $message): void {
    if (! $condition) throw new RuntimeException($message);
};
$wide = array('Home' => '/home', 'About' => '/about', 'Work' => 'https://site.example/work', 'Mail' => '/mail', 'Social' => 'https://social.example/account');
$narrowItems = array_slice($wide, 3, null, true);
$nav = static function (array $items, string $class): string {
    $links = '';
    foreach ($items as $label => $url) $links .= '<!-- wp:navigation-link {"label":"' . $label . '","url":"' . $url . '","className":"' . $class . '-item"} /-->';
    return '<!-- wp:navigation {"className":"' . $class . '"} -->' . $links . '<!-- /wp:navigation -->';
};
$markup = '<!-- wp:details --><details><summary>Menu</summary>' . $nav($wide, 'overlay') . '</details><!-- /wp:details -->' . $nav($narrowItems, 'icons');
$projected = NavigationEntityProjection::project(array(), array(array('source_path' => 'footer.html', 'area' => 'footer', 'title' => 'Footer', 'canonical_block_markup' => $markup)), array());
$assert(1 === count($projected['menus']) && 5 === $projected['menus'][0]['items'], 'The subsequence folds into the wider entity.');
$narrow = null;
foreach ($projected['parts'] as $part) {
    preg_match_all('/<!--\s*wp:navigation\s+(\{.*?\})\s*\/-->/s', $part['canonical_block_markup'], $matches);
    foreach ($matches[1] as $json) {
        $presentation = json_decode($json, true)['metadata'][NavigationEntityProjection::ITEMS_KEY] ?? array();
        if (! empty($presentation[0]['omit'])) $narrow = $presentation;
    }
}
$assert(is_array($narrow) && 5 === count($narrow), 'The narrow reference records its omissions.');

function add_filter(string $hook, callable $callback): void { $GLOBALS['permalink_filters'][$hook] = $callback; }
function apply_filters(string $hook, $value) { return $value; }
function wp_strip_all_tags(string $value): string { return strip_tags($value); }
function home_url(string $path = ''): string { return 'https://site.example' . $path; }
function is_front_page(): bool { return false; }
function is_singular(): bool { return false; }
function get_queried_object_id(): int { return 0; }
class WP_Block_List extends ArrayObject {}
eval(NavigationReferenceItemsRuntime::source());
$render = static function (array $urls) use ($narrow, $wide): array {
    $blocks = array();
    foreach (array_keys($wide) as $position => $label) {
        $blocks[] = (object) array('name' => 'core/navigation-link', 'inner_blocks' => array(), 'parsed_block' => array('attrs' => array('label' => $label, 'url' => $urls[$position], 'className' => 'entity')));
    }
    ($GLOBALS['permalink_filters']['render_block_data'])(array('blockName' => 'core/navigation', 'attrs' => array('metadata' => array(NavigationEntityProjection::ITEMS_KEY => $narrow))));
    $result = ($GLOBALS['permalink_filters']['block_core_navigation_render_inner_blocks'])(new WP_Block_List($blocks));
    ($GLOBALS['permalink_filters']['render_block_core/navigation'])('');
    return array_map(static fn (object $block): string => $block->parsed_block['attrs']['label'], (array) $result);
};
$assert(array('Mail', 'Social') === $render(array_values($wide)), 'Unrewritten URLs drop the recorded extras.');
$permalinks = array('/home/', 'https://site.example/about/', 'https://site.example/work/', '/mail/', 'https://social.example/account');
$assert(array('Mail', 'Social') === $render($permalinks), 'Permalink-rewritten URLs still drop the recorded extras.');
$assert(array('About', 'Mail', 'Social') === ($render(array('/home/', '/elsewhere/', '/work/', '/mail/', 'https://social.example/account'))), 'A changed omitted destination keeps that item.');
$assert(array('About', 'Mail', 'Social') === ($render(array('/home/', 'https://other.example/about/', '/work/', '/mail/', 'https://social.example/account'))), 'An off-site omitted destination keeps that item.');

echo "Navigation subset permalink omission contract passed.\n";
