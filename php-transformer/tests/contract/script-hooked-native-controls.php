<?php
/**
 * A menu or a filter control a script finds by a data attribute or id stays a
 * native, editable block that still carries that hook:
 * - <nav data-nav> becomes core/navigation; its data-* / aria state ride in
 *   block metadata and the generated theme renders them on the <nav>.
 * - <select data-filter> / <select id="sort"> becomes the authored select
 *   block with its data-* hooks; the id lives on the control only, never
 *   duplicated on the wrapper, so getElementById returns the select.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Elements\ElementConversionPrelude;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\EngineSupportCss;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\RuntimeAttributesRuntime;

$failures = 0; $passed = 0;
$assert = static function (bool $condition, string $message) use (&$failures, &$passed): void {
    if ($condition) { ++$passed; return; }
    ++$failures; fwrite(STDERR, "FAIL: {$message}\n");
};
$transform = static fn (string $html, array $selectors): string => (string) ((new HtmlTransformer())->transform($html, array('runtime_dom_selectors' => $selectors, 'runtime_script_metadata' => array(array('path' => 'js/app.js'))))->toArray()['serialized_blocks'] ?? '');

$header = '<header class="site-header"><a class="brand" href="/">Shop</a><nav class="site-nav" data-nav aria-label="Primary"><a href="/">Home</a><a href="/shop">Shop</a><a href="/about">About</a></nav><button class="nav-toggle" data-nav-toggle aria-expanded="false" aria-label="Menu">Menu</button></header>';
$menu = $transform($header, array('[data-nav]', '[data-nav-toggle]'));
$assert(str_contains($menu, '<!-- wp:navigation ') && 3 === substr_count($menu, '<!-- wp:navigation-link '), 'A script-addressed menu stays core/navigation with its links.');
$assert(!str_contains($menu, '<!-- wp:html'), 'A script-addressed menu is not a raw HTML island.');
$assert(str_contains($menu, '"blocksEngineRuntimeAttributes":{"data-nav":""}'), 'The hook the script finds the menu by rides in block metadata.');
$assert(str_contains($menu, '"overlayMenu":"never"') && 1 === preg_match('/<button[^>]*data-nav-toggle/', $menu), 'The script keeps its own toggle; core adds no second one.');
$assert(str_contains($menu, ElementConversionPrelude::SCRIPT_NAVIGATION_CLASS), 'A script-opened menu is marked so core\'s list stays transport.');
$css = implode("\n", (new EngineSupportCss())->coreOverrideCss($menu));
$assert(str_contains($css, '.wp-block-navigation.' . ElementConversionPrelude::SCRIPT_NAVIGATION_CLASS . '>.wp-block-navigation__container') && str_contains($css, 'display:contents!important'), 'Core\'s list and items render as transport so the script\'s state class on the menu governs alone.');

$filters = '<div class="filters"><label class="field">Genre <select data-filter-genre><option value="">All</option><option value="fiction">Fiction</option></select></label><select id="sort" class="sort"><option value="new">Newest</option><option value="price">Price</option></select></div>';
$controls = $transform($filters, array('[data-filter-genre]', '#sort'));
$assert(!str_contains($controls, '<!-- wp:html'), 'Script-addressed selects are not raw HTML islands.');
$assert(2 === substr_count($controls, '<!-- wp:custom/authored-select '), 'Each script-addressed select is an authored select block.');
$assert(1 === preg_match('/<select[^>]*\sdata-filter-genre(?:[\s=>])/', $controls), 'A labelled select keeps the data attribute the script finds it by.');
$assert(1 === substr_count($controls, 'id="sort"') && 1 === preg_match('/<select[^>]*\sid="sort"/', $controls), 'The id the script finds a select by lives only on the select.');

$filter = RuntimeAttributesRuntime::source();
$assert(str_contains($filter, "add_filter( 'render_block'") && str_contains($filter, 'blocksEngineRuntimeAttributes') && str_contains($filter, 'null === $processor->get_attribute( $name )'), 'The theme renders recorded hooks on the block element without overriding rendered values.');

if (0 !== $failures) { fwrite(STDERR, "Script-hooked native controls: {$failures} failed\n"); exit(1); }
echo "Script-hooked native controls: {$passed} passed\n";
