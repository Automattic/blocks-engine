<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

/**
 * Contract coverage for the controls around a product grid. A shop page's
 * filters, sort, and result count are evidence about the grid's products:
 *
 * - a facet is an option group whose values partition the cards, matched on
 *   a card leaf's text, a class-token segment, or a `data-*` value;
 * - a price range is a range input whose bounds hold every card price;
 * - a sort is a select whose options all read as orderings;
 * - a result count is a short text whose number is the card count.
 *
 * A `generic/product-listings/v1` entity declares them beside the grid: its
 * first binding is the region holding the grid and its controls, then one
 * binding per control, named by the control's id. Each product carries its
 * category, from a category facet, a category-labelled card leaf, or
 * schema.org `category`, and every other facet as an attribute with its
 * labels, even when another page shows the same product first.
 * Option groups that do not describe the cards are not controls.
 */

$assert = static function (bool $condition, string $message): void {
    if (! $condition) {
        throw new RuntimeException($message);
    }
};

$card = static fn (string $name, string $type, string $finish, string $price): string => '<article class="card"><div class="card-media"><svg viewBox="0 0 10 10"><circle cx="5" cy="5" r="4"/></svg><span class="card-cat">' . $type . '</span></div>'
    . '<div class="card-body"><h3 class="card-title">' . $name . '</h3><div class="card-meta"><span class="chip finish-' . $finish . '">' . ucfirst($finish) . '</span></div>'
    . '<div class="card-foot"><span class="price">$' . $price . '</span><button type="button" class="btn" data-add="' . strtolower(strtok($name, ' ')) . '">Add to cart</button></div></div></article>';
$cards = $card('Alpha Lamp', 'Lighting', 'matte', '18.00')
    . $card('Beta Lamp', 'Lighting', 'gloss', '24.00')
    . $card('Gamma Chair', 'Seating', 'matte', '40.00')
    . $card('Delta Stool', 'Seating', 'gloss', '32.00');
$shop = '<!doctype html><html><head><title>Shop</title></head><body><main><h1>Shop</h1><section class="layout"><aside class="filters">'
    . '<div class="group"><h4 id="type-label">Type</h4><div class="chips" role="group" aria-labelledby="type-label"><button type="button" data-type="all">All goods</button><button type="button" data-type="Lighting">Lighting</button><button type="button" data-type="Seating">Seating</button></div></div>'
    . '<div class="group"><label for="price">Max price</label><input type="range" id="price" min="10" max="50" step="1" value="50"></div>'
    . '<div class="group"><label for="finish">Finish</label><select id="finish"><option value="all">Any finish</option><option value="matte">Matte</option><option value="gloss">Gloss</option></select></div>'
    . '<div class="group"><label for="ship">Ship to</label><select id="ship"><option value="us">United States</option><option value="ca">Canada</option></select></div>'
    . '</aside><div class="results"><div class="toolbar"><span class="count">4 items</span><label for="sort">Sort by</label><select id="sort"><option value="featured">Featured</option><option value="price-asc">Price: low to high</option><option value="price-desc">Price: high to low</option></select></div>'
    . '<div class="grid" data-grid>' . $cards . '</div></div></section></main><script src="app.js"></script></body></html>';
$script = "var grid = document.querySelector('[data-grid]'); if (grid) { grid.innerHTML = grid.innerHTML; }";

$compile = static fn (array $files): array => (new ArtifactCompiler())->compile(array('entrypoint' => 'shop.html', 'files' => $files))->toArray();
$declaration = static fn (array $plan, string $type): ?array => current(array_filter($plan['runtime_declarations'] ?? array(), static fn (array $declaration): bool => $type === ($declaration['type'] ?? null))) ?: null;

$result = $compile(array('shop.html' => $shop, 'app.js' => $script));
$assert('failed' !== $result['status'] && ! isset($result['source_reports']['wordpress_site_plan_diagnostics']), 'The store composes cleanly: ' . json_encode($result['source_reports']['wordpress_site_plan_diagnostics'] ?? array()));
$plan = $result['source_reports']['wordpress_site_plan'] ?? array();
$shopPage = (string) (current(array_filter($plan['pages'] ?? array(), static fn (array $page): bool => 'shop.html' === ($page['source_path'] ?? null)))['canonical_block_markup'] ?? '');

$listing = $declaration($plan, 'product_listings')['payload']['entities'][0] ?? array();
$grid = $declaration($plan, 'product_grids')['payload']['entities'][0] ?? array();
$assert('shop.html' === ($listing['source_path'] ?? null) && ($grid['selector'] ?? '') === ($listing['grid_selector'] ?? null), 'A product listing is declared for the shop grid: ' . json_encode(array_keys($listing)));

$bindings = $listing['bindings'] ?? array();
$region = (string) ($bindings[0]['search_block_markup'] ?? '');
$assert('commerce_listing' === ($bindings[0]['role'] ?? null) && ! isset($bindings[0]['control']), 'The first binding is the commerce_listing region.');
$assert(1 === substr_count($shopPage, $region) && str_contains($region, 'Alpha Lamp') && str_contains($region, 'Any finish') && str_contains($region, '4 items') && ! str_contains($region, '<h1'), 'The region anchor is emitted once and holds the grid and every control, and nothing outside them.');

$controls = array_column($listing['controls'] ?? array(), null, 'id');
$controlBindings = array_column(array_slice($bindings, 1), null, 'control');
foreach ($controls as $id => $control) {
    $anchor = (string) ($controlBindings[$id]['search_block_markup'] ?? '');
    $assert('commerce_listing_control' === ($controlBindings[$id]['role'] ?? null) && '' !== $anchor && str_contains($region, $anchor), 'Every control is bound to its own block inside the region: ' . $id);
}
$assert(array('facet:type', 'facet:finish', 'sort', 'range:price', 'result_count') === array_keys($controls), 'The type and finish facets, sort, price range and count are recognized, and the shipping select is not: ' . json_encode(array_keys($controls)));

$type = $controls['facet:type'];
$assert('buttons' === $type['shape'] && 'category' === $type['role'] && 'Type' === $type['label'], 'The button group is a category facet labelled by its heading.');
$assert(array('all', 'Lighting', 'Seating') === array_column($type['options'], 'value') && true === ($type['options'][0]['reset'] ?? false), 'The facet lists its options, the first resetting it.');
$finish = $controls['facet:finish'];
$assert('select' === $finish['shape'] && 'attribute' === $finish['role'] && array('Any finish', 'Matte', 'Gloss') === array_column($finish['options'], 'label'), 'The finish select is an attribute facet matched by the chip class.');
$range = $controls['range:price'];
$assert('max' === $range['bound'] && 10.0 === $range['min'] && 50.0 === $range['max'], 'The range bounds the maximum price and keeps its source bounds.');
$assert(array('default', 'price_asc', 'price_desc') === array_column($controls['sort']['options'], 'order'), 'The sort options are read as orderings.');
$assert('4 items' === $controls['result_count']['label'], 'The count is the text whose number is the card count.');

$products = array_column($declaration($plan, 'products')['payload']['entities'] ?? array(), null, 'slug');
$assert('Seating' === ($products['delta-stool']['category'] ?? null), 'A category facet names the product category: ' . json_encode($products['delta-stool'] ?? null));
$assert(array(array('key' => 'finish', 'label' => 'Finish', 'value' => 'gloss', 'value_label' => 'Gloss')) == ($products['delta-stool']['attributes'] ?? null), 'Any other facet is a product attribute with its labels: ' . json_encode($products['delta-stool']['attributes'] ?? null));

// Without filter controls the category still comes from the card label, and
// a grid with no controls declares no listing.
$bare = '<!doctype html><html><head><title>Shop</title></head><body><main><h1>Shop</h1><div class="grid" data-grid>' . $cards . '</div></main><script src="app.js"></script></body></html>';
$barePlan = $compile(array('shop.html' => $bare, 'app.js' => $script))['source_reports']['wordpress_site_plan'] ?? array();
$bareProducts = array_column($declaration($barePlan, 'products')['payload']['entities'] ?? array(), null, 'slug');
$assert('Lighting' === ($bareProducts['alpha-lamp']['category'] ?? null) && ! isset($bareProducts['alpha-lamp']['attributes']), 'The card category label names the category on its own.');
$assert(null !== $declaration($barePlan, 'product_grids') && null === $declaration($barePlan, 'product_listings'), 'A grid with no controls declares no listing.');

// A home page that shows the same products first, without filters, does not
// hide the attributes the shop page's filters describe.
$bothPlan = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array('index.html' => $bare, 'shop.html' => $shop, 'app.js' => $script)))->toArray()['source_reports']['wordpress_site_plan'] ?? array();
$bothProducts = array_column($declaration($bothPlan, 'products')['payload']['entities'] ?? array(), null, 'slug');
$assert('gloss' === ($bothProducts['delta-stool']['attributes'][0]['value'] ?? null) && 'Seating' === ($bothProducts['delta-stool']['category'] ?? null), 'Attributes survive the same product shown first elsewhere: ' . json_encode($bothProducts['delta-stool'] ?? null));

// schema.org `category` names the leaf of a category path.
$jsonLd = '<script type="application/ld+json">' . json_encode(array('@context' => 'https://schema.org', '@type' => 'Product', 'name' => 'Omega Desk', 'category' => 'Furniture > Desks', 'offers' => array('@type' => 'Offer', 'price' => '120.00'))) . '</script>';
$schemaPlan = $compile(array('shop.html' => '<!doctype html><html><head><title>Desk</title>' . $jsonLd . '</head><body><main><h1>Omega Desk</h1></main></body></html>'))['source_reports']['wordpress_site_plan'] ?? array();
$schemaProducts = array_column($declaration($schemaPlan, 'products')['payload']['entities'] ?? array(), null, 'slug');
$assert('Desks' === ($schemaProducts['omega-desk']['category'] ?? null), 'A schema.org category path names its leaf: ' . json_encode($schemaProducts));

// A catalog page that repeats one category-group shape (heading, count,
// grid) under one wrapper: a grid whose own count label does not match its
// card count must not claim the sibling group's count, or the two listings
// would bind the same count block and the plan would be ambiguous.
$catalogGroup = static fn (string $heading, string $count, array $cards): string => '<div class="group"><div class="head"><h3>' . $heading . '</h3><span class="count">' . $count . '</span></div><div class="grid">' . implode('', $cards) . '</div></div>';
$catalogCards = array(
    $card('Epsilon Lamp', 'Lighting', 'matte', '18.00'),
    $card('Zeta Lamp', 'Lighting', 'gloss', '24.00'),
    $card('Eta Chair', 'Seating', 'matte', '40.00'),
    $card('Theta Lamp', 'Lighting', 'matte', '21.00'),
    $card('Iota Lamp', 'Lighting', 'gloss', '27.00'),
    $card('Kappa Chair', 'Seating', 'matte', '44.00'),
);
$catalog = '<!doctype html><html><head><title>Catalog</title></head><body><main><section><div class="catalog">'
    . $catalogGroup('Strips', '3 products', array_slice($catalogCards, 0, 3))
    . $catalogGroup('Merch', '6 products', array_slice($catalogCards, 3))
    . '</div></section></main></body></html>';

$catalogResult = $compile(array('catalog.html' => $catalog));
$assert('failed' !== $catalogResult['status'] && ! isset($catalogResult['source_reports']['wordpress_site_plan_diagnostics']), 'The catalog composes cleanly: ' . json_encode($catalogResult['source_reports']['wordpress_site_plan_diagnostics'] ?? array()));
$catalogPlan = $catalogResult['source_reports']['wordpress_site_plan'] ?? array();

// The repeated shape puts each group at a fixed position, so the source
// selectors tell the two groups' grids and counts apart.
$catalogPrefix = 'main:nth-of-type(1) > section:nth-of-type(1) > div:nth-of-type(1) > ';
$gridA = $catalogPrefix . 'div:nth-of-type(1) > div:nth-of-type(2)';
$gridB = $catalogPrefix . 'div:nth-of-type(2) > div:nth-of-type(2)';
$countA = $catalogPrefix . 'div:nth-of-type(1) > div:nth-of-type(1) > span:nth-of-type(1)';

$catalogGrids = array_column($declaration($catalogPlan, 'product_grids')['payload']['entities'] ?? array(), null, 'selector');
$assert(isset($catalogGrids[$gridA], $catalogGrids[$gridB]), 'Both category groups are recognized as product grids: ' . json_encode(array_keys($catalogGrids)));

$listingsByGrid = array_column($declaration($catalogPlan, 'product_listings')['payload']['entities'] ?? array(), null, 'grid_selector');
$countControls = array_values(array_filter($listingsByGrid[$gridA]['controls'] ?? array(), static fn (array $control): bool => 'result_count' === ($control['id'] ?? null)));
$assert(1 === count($countControls) && $countA === ($countControls[0]['selector'] ?? null) && '3 products' === ($countControls[0]['label'] ?? null), 'Group A\'s listing counts its own cards, not the sibling group\'s: ' . json_encode($listingsByGrid[$gridA]['controls'] ?? array()));
$countBindings = array_values(array_filter($listingsByGrid[$gridA]['bindings'] ?? array(), static fn (array $binding): bool => 'result_count' === ($binding['control'] ?? null)));
$countBinding = $countBindings[0] ?? array();
$countAnchor = (string) ($countBinding['search_block_markup'] ?? '');
$assert(1 === count($countBindings) && str_contains($countAnchor, '3 products') && ! str_contains($countAnchor, '6 products'), 'The result_count binding anchors on group A\'s own count block: ' . json_encode($countBinding));
$assert(! isset($listingsByGrid[$gridB]), 'Group B, whose count label does not match its card count, declares no listing that could bind group A\'s count.');

echo "product-grid-listing-controls: ok\n";
