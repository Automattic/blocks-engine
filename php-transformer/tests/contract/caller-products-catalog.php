<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\WordPressSitePlan;

$assert = static function (bool $condition, string $message): void {
    if (!$condition) { fwrite(STDERR, "FAIL: {$message}\n"); exit(1); }
};
$catalog = array();
foreach (array('a', 'b', 'c') as $slug) {
    $catalog[] = array('slug' => $slug, 'name' => 'Catalog ' . strtoupper($slug), 'regular_price' => '99.00', 'sale_price' => '89.00', 'sku' => 'CAT-' . $slug, 'image' => 'https://example.test/catalog.jpg', 'description' => 'Catalog description', 'images' => array(array('src' => 'https://example.test/catalog.jpg', 'alt' => 'Catalog')), 'tags' => array('catalog'), 'category' => 'Catalog category', 'stock_status' => 'outofstock', 'variants' => array());
}
$catalog[0]['currency'] = 'PEN';
$card = static fn (string $slug): string => '<article><a href="/products/' . $slug . '.html"><img src="https://example.test/' . $slug . '.jpg" alt="Product ' . $slug . '"><h2>Product ' . $slug . '</h2></a><p>$10.00</p><button type="button">Add to cart</button></article>';
$ld = array('@context' => 'https://schema.org', '@type' => 'Product', 'name' => 'Detected A', 'url' => 'https://example.test/products/a.html', 'sku' => 'DETECTED', 'description' => 'Detected description', 'offers' => array('@type' => 'Offer', 'price' => '12.00', 'priceCurrency' => 'USD'));
$artifact = array('entrypoint' => 'index.html', 'files' => array(
    'index.html' => '<main><h1>Shop</h1><a href="/cart/">Cart</a><label>Sort<select name="sort"><option value="price-asc">Price: low to high</option><option value="price-desc">Price: high to low</option></select></label><div class="grid">' . $card('a') . $card('b') . '</div></main>',
    'products/a.html' => '<script type="application/ld+json">' . json_encode($ld, JSON_THROW_ON_ERROR) . '</script><main><h1>Detected A</h1><p>Detail page</p><form action="/cart/" method="post"><label>Quantity<input type="number" name="quantity" value="1"></label><button type="submit" name="add-to-cart" value="a">Add to cart</button></form></main>',
), 'runtime_declarations' => array(array('kind' => 'entity_collection', 'type' => 'products', 'source_path' => 'catalog.json', 'payload' => array('schema' => 'generic/products/v1', 'entities' => $catalog))));
$result = (new ArtifactCompiler())->compile($artifact)->toArray();
$plan = $result['source_reports']['wordpress_site_plan'] ?? array();
$assert(array() !== $plan, 'Compilation produces a site plan.');
$collections = array();
foreach ($plan['runtime_declarations'] as $declaration) {
    if ('entity_collection' === $declaration['kind']) $collections[$declaration['type']] = $declaration['payload']['entities'];
}
$products = array_column($collections['products'] ?? array(), null, 'slug');
$assert(array('a', 'b', 'c') === array_keys($products), 'The merged collection contains A, B and C.');
$roles = array_column($products['a']['bindings'] ?? array(), 'role');
$assert(in_array('commerce_add_to_cart_form', $roles, true) && count($roles) >= 2, 'A retains detail-page and card bindings.');
foreach ($catalog as $row) foreach ($row as $field => $value) $assert($value == ($products[$row['slug']][$field] ?? null), 'Catalog owns ' . $row['slug'] . '.' . $field);
$assert('products/a.html' === $products['a']['source_path'] && str_contains($products['a']['url'], '/products/a.html'), 'A retains its compiled detail-page identity.');
$assert('blocks-engine/whole-page-candidate/v1' === ($products['a']['whole_page_candidate'] ?? null), 'A retains its whole-page candidate.');
$assert(!empty($products['a']['controls']) && !empty($products['a']['presentation_graph']), 'A retains purchase controls and presentation.');
$assert(!empty($products['b']['bindings']), 'B retains its card binding.');
$assert(empty($products['c']['bindings']), 'Catalog-only C stays bindingless.');
$assert(!empty($collections['product_grids']) && !empty($collections['product_listings']) && !empty($collections['cart_links']), 'Grid, listing and cart declarations survive.');
$assert(array('a', 'b') === array_column($collections['product_grids'][0]['items'], 'product'), 'Grid items reference the merged product slugs.');
$assert($collections['product_grids'][0]['selector'] === $collections['product_listings'][0]['grid_selector'], 'Listing references the surviving grid.');
$assert(in_array('shop', array_column($plan['runtime_declarations'], 'capability'), true), 'The shop dependency survives.');
WordPressSitePlan::assertValid($plan);

// A detected row absent from the caller catalog must not disappear.
$catalog[1]['currency'] = 'pen';
$catalog[2]['currency'] = null;
$artifact['runtime_declarations'][0]['payload']['entities'] = array($catalog[1], $catalog[2]);
$detectedOnly = (new ArtifactCompiler())->compile($artifact)->toArray();
$detectedPlan = $detectedOnly['source_reports']['wordpress_site_plan'];
foreach ($detectedPlan['runtime_declarations'] as $declaration) {
    if ('products' !== ($declaration['type'] ?? null)) continue;
    $rows = array_column($declaration['payload']['entities'], null, 'slug');
    $assert(!array_key_exists('currency', $rows['b']) && !array_key_exists('currency', $rows['c']), 'Invalid caller currencies are dropped, including catalog-only rows.');
    $assert(3 === count($rows) && !empty($rows['a']['bindings']) && 'USD' === ($rows['a']['currency'] ?? null), 'Detected-only A survives with JSON-LD currency and its binding.');
}
$assert(str_contains(json_encode($detectedOnly, JSON_THROW_ON_ERROR), 'product_currency_invalid'), 'Invalid currency produces a diagnostic.');
WordPressSitePlan::assertValid($detectedPlan);
echo "caller-products-catalog: ok (A: catalog commerce + detail/card evidence; B: card binding; C: bindingless; grid/listing/cart/shop retained)\n";
