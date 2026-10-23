<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

// A catalog commonly renders the same sort control twice: in a bar beside the
// grid and inside the filter form. That is one logical control with two
// placements, so the listing carries it once and anchors each placement.
$assert = static function (bool $condition, string $message): void {
    if (! $condition) throw new RuntimeException($message);
};
$sort = '<select name="sort_by"><option value="featured">Featured</option><option value="price-ascending">Price: low to high</option><option value="price-descending">Price: high to low</option></select>';
$card = static fn (string $name): string => '<article><img src="' . strtolower($name) . '.jpg" alt="' . $name . '"><h2>' . $name . '</h2><p>$20.00</p></article>';
$page = '<!doctype html><html><head><title>Catalog</title></head><body><main><h1>Catalog</h1><section>'
    . '<form method="get" id="filters"><fieldset><legend>Finish</legend><label><input type="checkbox" name="filter.finish" value="matte">Matte</label><label><input type="checkbox" name="filter.finish" value="gloss">Gloss</label></fieldset>' . $sort . '<button type="submit">Apply</button></form>'
    . '<form method="get" id="sort-bar">' . $sort . '</form>'
    . '<div class="grid" style="display:grid">' . $card('Alpha') . $card('Beta') . '</div>'
    . '</section></main></body></html>';
$result = (new ArtifactCompiler())->compile(array('entrypoint' => 'catalog.html', 'files' => array('catalog.html' => $page)))->toArray();
$listings = array();
foreach ($result['source_reports']['wordpress_site_plan']['runtime_declarations'] ?? array() as $declaration) {
    if ('product_listings' === ($declaration['type'] ?? null)) $listings = $declaration['payload']['entities'] ?? array();
}
$assert(1 === count($listings), 'Expected one listing: ' . json_encode($listings));
$ids = array_column($listings[0]['controls'], 'id');
$assert(count($ids) === count(array_unique($ids)), 'Listing control ids are unique: ' . json_encode($ids));
$assert(1 === count(array_keys($ids, 'sort', true)), 'The repeated sort control is declared once: ' . json_encode($ids));
$sortBindings = array_filter($listings[0]['bindings'], static fn (array $binding): bool => 'sort' === ($binding['control'] ?? null));
$assert(2 === count($sortBindings), 'Each sort placement keeps its own anchor: ' . count($sortBindings));
foreach ($listings[0]['bindings'] as $binding) {
    $assert('commerce_listing' === $binding['role'] || in_array($binding['control'] ?? '', $ids, true), 'Every control binding names a declared control.');
}
echo "product-listing-repeated-control: ok\n";
