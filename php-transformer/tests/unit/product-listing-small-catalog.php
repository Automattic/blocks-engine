<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

$assert = static function (bool $condition, string $message): void {
    if (! $condition) throw new RuntimeException($message);
};
$controls = '<form method="get"><label for="sort">Sort by</label><select id="sort" name="sort_by"><option value="featured">Featured</option><option value="price-ascending">Price: low to high</option></select>'
    . '<fieldset><legend>Finish</legend><label><input type="checkbox" name="filter.finish" value="matte">Matte</label><label><input type="checkbox" name="filter.finish" value="gloss">Gloss</label></fieldset></form>';
$card = static fn (string $name, string $finish): string => '<article data-finish="' . $finish . '"><img src="' . strtolower($name) . '.jpg" alt="' . $name . '"><h2>' . $name . '</h2><p>$20.00</p></article>';
$compile = static function (string $content): array {
    return (new ArtifactCompiler())->compile(array('entrypoint' => 'catalog.html', 'files' => array('catalog.html' => '<!doctype html><html><head><title>Catalog</title></head><body><main><h1>Catalog</h1>' . $content . '</main></body></html>')))->toArray();
};
$entities = static function (array $result, string $type): array {
    foreach ($result['source_reports']['wordpress_site_plan']['runtime_declarations'] ?? array() as $declaration) {
        if ($type === ($declaration['type'] ?? null)) return $declaration['payload']['entities'] ?? array();
    }
    return array();
};

foreach (array(2, 0, 1, 3) as $count) {
    $cards = '';
    foreach (array_slice(array('Alpha', 'Beta', 'Gamma'), 0, $count) as $index => $name) {
        $cards .= $card($name, 0 === $index % 2 ? 'matte' : 'gloss');
    }
    $result = $compile('<section>' . $controls . (0 === $count ? '<div>No items available.</div>' : '<div class="grid" style="display:grid">' . $cards . '</div>') . '</section>');
    $assert('failed' !== $result['status'] && !isset($result['source_reports']['wordpress_site_plan_diagnostics']), 'Catalog composes cleanly: ' . json_encode($result['source_reports']['wordpress_site_plan_diagnostics'] ?? $result['status']));
    $grids = $entities($result, 'product_grids');
    $listings = $entities($result, 'product_listings');
    $assert(1 === count($grids) && $count === count($grids[0]['items']), 'Expected one grid with ' . $count . ' items: ' . json_encode($grids));
    $assert($count === count($entities($result, 'products')), 'Expected ' . $count . ' products.');
    $assert(1 === count($listings) && $listings[0]['grid_selector'] === $grids[0]['selector'], 'Expected one listing connected to its grid.');
    $assert(array('sort', 'facet') === array_column($listings[0]['controls'], 'kind'), 'Sort and checkbox facet survive: ' . json_encode($listings[0]['controls']));
    $assert('checkboxes' === $listings[0]['controls'][1]['shape'] && 'Finish' === $listings[0]['controls'][1]['label'], 'Checkbox facet shape and legend are retained.');
    $assert((0 === $count) === !isset($grids[0]['item_template']), 'Only nonempty grids have a repeatable card template.');
    $assert(3 === count($listings[0]['bindings']), 'Region and both controls have bindings.');
    foreach ($listings[0]['bindings'] as $binding) {
        $assert('' !== ($binding['search_block_markup'] ?? ''), 'Every listing binding has a page-owned block anchor.');
    }
}

$pricing = $compile('<section><div class="grid" style="display:grid">' . $card('Basic', 'matte') . $card('Plus', 'gloss') . $card('Premium', 'matte') . '</div></section>');
$assert(array() === $entities($pricing, 'product_grids') && array() === $entities($pricing, 'product_listings'), 'Three pricing cards without controls are not commerce.');
// Localized labels must not hide explicit ordering values, even on one card.
$sort = '<select name="sort_by">';
foreach (array('manual' => 'Destacados', 'best-selling' => 'Más vendidos', 'title-ascending' => 'Título A–Z', 'title-descending' => 'Título Z–A', 'price-ascending' => 'Precio, menor a mayor', 'price-descending' => 'Precio, mayor a menor', 'created-ascending' => 'Fecha, antiguos primero', 'created-descending' => 'Fecha, recientes primero') as $value => $label) {
    $sort .= '<option value="' . $value . '">' . $label . '</option>';
}
$sort .= '</select>';
foreach (array(1, 2) as $count) {
    $result = $compile('<section><form id="catalog-bar">' . $sort . '</form><div class="grid" style="display:grid">' . $card('Alpha', 'matte') . (2 === $count ? $card('Beta', 'gloss') : '') . '</div></section>');
    $grids = $entities($result, 'product_grids');
    $listings = $entities($result, 'product_listings');
    $assert(1 === count($grids) && $count === count($grids[0]['items']), 'Localized sort qualifies a small grid.');
    $assert(1 === count($listings) && in_array('sort', array_column($listings[0]['controls'], 'kind'), true), 'Localized sort is a listing control.');
    $orders = array_column($listings[0]['controls'][0]['options'], 'order', 'value');
    $assert('name_desc' === $orders['title-descending'] && 'oldest' === $orders['created-ascending'] && 'newest' === $orders['created-descending'], 'Ordering values retain their direction.');
    $assert(array() === $entities($result, 'forms'), 'A listing-owned sort form is not also a generic form.');
}

$facets = '<fieldset><legend>Size</legend><label><input type="checkbox" name="filter.size" value="small">Small</label><label><input type="checkbox" name="filter.size" value="large">Large</label></fieldset>'
    . '<fieldset><legend>Color</legend><label><input type="checkbox" name="filter.color" value="red">Red</label><label><input type="checkbox" name="filter.color" value="blue">Blue</label></fieldset>';
$panel = '<div class="filter-panel"><form><div>' . $facets . '</div><div>' . $sort . '</div></form></div>';
$result = $compile($panel . '<section><div class="grid" style="display:grid">' . $card('Alpha', 'matte') . $card('Beta', 'gloss') . '</div></section>');
$grids = $entities($result, 'product_grids');
$listings = $entities($result, 'product_listings');
$assert(1 === count($grids) && 2 === count($grids[0]['items']), 'Filter panel does not create empty grids.');
$gridFindings = array_values(array_filter($result['fallbacks'] ?? array(), static fn (array $finding): bool => 'html_product_grid_fallback' === ($finding['diagnostic_code'] ?? null)));
$assert(1 === count($gridFindings) && 2 === $gridFindings[0]['product_count'], 'Exactly one populated grid fallback, with no panel findings.');
$assert(1 === count($listings) && array('sort', 'facet', 'facet') === array_column($listings[0]['controls'], 'kind'), 'Sibling panel controls attach to populated listing.');

foreach (array('header', 'aside') as $landmark) {
    $html = '<!doctype html><html><head><title>Catalog</title></head><body><' . $landmark . '><h2>Promoted products</h2><div style="display:grid">' . $card('Delta', 'matte') . $card('Epsilon', 'gloss') . '</div></' . $landmark . '><main><h1>Catalog</h1><filter-form><form>' . $facets . $sort . '</form></filter-form><div class="collection-empty collection" id="product-grid"><svg viewBox="0 0 10 10"><circle cx="5" cy="5" r="4"/></svg><p>No products found</p></div></main></body></html>';
    $result = (new ArtifactCompiler())->compile(array('entrypoint' => 'catalog.html', 'files' => array('catalog.html' => $html)))->toArray();
    $grids = $entities($result, 'product_grids');
    $listings = $entities($result, 'product_listings');
    $assert(1 === count($grids) && array() === $grids[0]['items'] && 'main:nth-of-type(1) > div:nth-of-type(1)' === $grids[0]['selector'] && str_contains($grids[0]['bindings'][0]['search_block_markup'], 'id="product-grid"'), 'Only the empty-state element is the empty grid.');
    $assert(1 === count($listings) && $listings[0]['grid_selector'] === $grids[0]['selector'] && 3 === count($listings[0]['controls']), 'Unrelated promo cards do not veto the empty listing.');
    $gridFindings = array_values(array_filter($result['fallbacks'] ?? array(), static fn (array $finding): bool => 'html_product_grid_fallback' === ($finding['diagnostic_code'] ?? null)));
    $assert(1 === count($gridFindings) && 0 === $gridFindings[0]['product_count'], 'Exactly one zero-product grid fallback.');
}

$contact = $compile('<section><h2>Contact us</h2><form><input name="email" type="email"><fieldset><legend>Interests</legend><label><input type="checkbox" name="interests" value="news">News</label><label><input type="checkbox" name="interests" value="events">Events</label></fieldset><button>Send</button></form></section>');
$assert(array() === $entities($contact, 'product_grids') && array() === $entities($contact, 'product_listings'), 'Contact interests without product signals are not commerce.');
$assert(array() === array_filter($contact['fallbacks'] ?? array(), static fn (array $finding): bool => 'html_product_grid_fallback' === ($finding['diagnostic_code'] ?? null)), 'Contact interests emit no grid findings.');
$loneOrder = $compile('<section><form><select name="order"><option value="one">Uno</option><option value="two">Dos</option></select></form></section>');
$assert(array() === $entities($loneOrder, 'product_grids') && array() === $entities($loneOrder, 'product_listings'), 'An order select without product signals is not commerce.');
// Captured-page topology: no landmarks, deep sibling branches, duplicate devices.
$capturedCard = static fn (string $name): string => '<li class="column"><product-card class="product-card"><figure><a href="/products/' . strtolower($name) . '"><img src="' . $name . '.jpg" alt="' . $name . '"></a></figure><h2><a href="/products/' . strtolower($name) . '">' . $name . '</a></h2><span>$145.000</span></product-card></li>';
$capturedSort = '<select name="sort_by">';
foreach (array('manual' => 'Destacados', 'best-selling' => 'Más vendidos', 'title-ascending' => 'Título A–Z', 'price-ascending' => 'Precio, menor a mayor', 'price-descending' => 'Precio, mayor a menor', 'created-descending' => 'Fecha, recientes primero') as $value => $label) {
    $capturedSort .= '<option value="' . $value . '">' . $label . '</option>';
}
$capturedSort .= '</select>';
$capturedFacets = '';
foreach (array('availability' => array('1'), 'color' => array('red', 'blue'), 'size' => array('XS', 'S', 'M', 'L', 'XL', 'XXL', 'XXXL')) as $key => $values) {
    $capturedFacets .= '<details><summary>' . ucfirst($key) . '</summary><div>';
    foreach ($values as $value) {
        $capturedFacets .= '<label><input type="checkbox" name="filter.v.' . ('availability' === $key ? '' : 'option.') . $key . '" value="' . $value . '">' . $value . '</label>';
    }
    $capturedFacets .= '</div></details>';
}
foreach (array(2, 1, 0) as $count) {
    $grid = 0 === $count ? '<div id="product-grid" class="collection-empty"><p>No products found</p></div>' : '<ul id="product-grid" class="products collection row">' . $capturedCard('Alpha') . (2 === $count ? $capturedCard('Beta') : '') . '</ul>';
    $column = '<div><div><form id="FiltersFormMobile">' . $capturedFacets . $capturedSort . '</form></div><div><form id="FiltersForm-bar">' . $capturedSort . '</form><span>' . $count . ' productos</span>' . $grid . '</div></div>';
    $drawer = '<div class="search-drawer"><ul>';
    foreach (array('Gamma', 'Delta', 'Epsilon', 'Zeta', 'Eta', 'Theta') as $name) $drawer .= $capturedCard($name);
    $drawer .= '</ul></div>';
    $copy = '<div>' . str_repeat('<div>', 8) . $column . str_repeat('</div>', 8) . $drawer . '</div>';
    $html = '<!doctype html><html><head><title>Catalog</title></head><body><style>.neutral { color: black; }</style><script>window.neutral = "not a facet label";</script>' . $copy . $copy . '</body></html>';
    $result = (new ArtifactCompiler())->compile(array('entrypoint' => 'catalog.html', 'files' => array('catalog.html' => $html)))->toArray();
    $grids = $entities($result, 'product_grids');
    $listings = $entities($result, 'product_listings');
    $assert('failed' !== $result['status'] && !isset($result['source_reports']['wordpress_site_plan_diagnostics']), 'Landmark-free page composes cleanly.');
    $assert(2 === count($grids) && 2 === count($listings), 'Each device has exactly one grid and listing for ' . $count . ' cards.');
    foreach ($grids as $index => $gridEntity) {
        $assert($count === count($gridEntity['items']) && !empty($gridEntity['bindings']), 'Grid items and binding survive.');
        $listing = $listings[$index];
        $assert($listing['grid_selector'] === $gridEntity['selector'] && !empty($listing['bindings']), 'Listing binds to its own device grid.');
        $kinds = array_count_values(array_column($listing['controls'], 'kind'));
        $assert(array('sort' => 2, 'facet' => 3, 'result_count' => 1) === $kinds, 'All facets, both sorts and result count belong to the listing: ' . json_encode($kinds));
        $keys = array_column(array_filter($listing['controls'], static fn (array $control): bool => 'facet' === $control['kind']), 'key');
        $assert(array('availability', 'color', 'size') === $keys && max(array_map('strlen', $keys)) <= 40, 'Facet keys use only their own group label.');
        foreach ($listing['controls'] as $control) {
            $assert(str_starts_with($control['selector'], 'div:nth-of-type(' . ($index + 1) . ') > '), 'Controls stay within their device copy.');
        }
    }
    $assert(array() === $entities($result, 'forms'), 'Both filter_sort forms per device are claimed by the listing.');
    $findings = array_values(array_filter($result['fallbacks'] ?? array(), static fn (array $finding): bool => 'html_product_grid_fallback' === ($finding['diagnostic_code'] ?? null)));
    $assert(2 === count($findings), 'Search drawer cards never create another grid.');
}
// Without a group caption, use the input name, never unlabeled ancestor text.
$dom = new DOMDocument();
$dom->loadHTML('<html><body><style>body { color: red; }</style><div>Unrelated page text<form><div><label><input type="checkbox" name="filter.v.option.color" value="red">Red</label><label><input type="checkbox" name="filter.v.option.color" value="blue">Blue</label></div></form><ul id="empty-grid"></ul></div></body></html>');
$recognized = (new Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Patterns\ListingControlsRecognizer())->recognize($dom->getElementsByTagName('ul')->item(0), array(), array());
$assert('color' === ($recognized['controls'][0]['key'] ?? null), 'Unlabeled facet key falls back to the final input name segment.');
$assert(strlen($recognized['controls'][0]['label']) <= 40, 'Unlabeled facet never takes ancestor text.');
echo "product-listing-small-catalog: ok\n";
