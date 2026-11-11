<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\RuntimeDeclarations;
use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\RuntimeEntityManifest;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\WordPressSitePlan;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\WordPressSitePlanResolver;

$assert = static function (bool $ok, string $message): void {
    if (!$ok) throw new RuntimeException($message);
};
$purchase = '<form method="post"><input type="hidden" name="variant_id" value="123"><input type="number" name="quantity" value="1"><button type="submit">Add to cart</button></form>';
$product = static fn (string $path, string $sku = 'lamp'): array => array('@context' => 'https://schema.org', '@type' => 'Product', 'name' => 'Desk Lamp', 'sku' => $sku, 'url' => 'https://example.test' . $path, 'offers' => array('@type' => 'Offer', 'price' => '40.00'));
$page = static fn (array $schema, string $body = ''): string => '<html><head><title>Shop</title><script type="application/ld+json">' . json_encode($schema) . '</script></head><body><main><h1>Shop</h1>' . $body . '</main></body></html>';
$files = array('index.html' => '<main><h1>Shop</h1></main>', 'products/lamp.html' => $page($product('/products/lamp'), $purchase), 'catalog.html' => $page(array('@type' => 'ItemList', 'itemListElement' => array(array('@type' => 'ListItem', 'item' => $product('/products/lamp'))))));
$compile = static fn (array $files): array => (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => $files))->toArray();
$planOf = static function (array $result): array {
    return $result['source_reports']['wordpress_site_plan'] ?? throw new RuntimeException(json_encode($result['diagnostics']));
};
$candidates = static function (array $plan): array {
    $found = array();
    foreach ($plan['pages'] as $page) foreach ($page['whole_page_candidates'] ?? array() as $candidate) $found[$page['source_path']] = $candidate;
    ksort($found);
    return $found;
};
$result = $compile($files);
$plan = $planOf($result);
$claims = $candidates($plan);
$assert(array('products/lamp.html') === array_keys($claims), 'Only the detail page has a whole-page candidate.');
$numericProduct = $product('/products/1234');
$numericProduct['name'] = '1234';
$numericResult = $compile(array('index.html' => '<main><h1>Shop</h1></main>', 'products/1234.html' => $page($numericProduct, $purchase)));
$assert(array('products/1234.html') === array_keys($candidates($planOf($numericResult))), 'A numeric product slug compiles and claims its detail page.');

// WebPage.mainEntity is common product evidence, and source route casing must
// use the same canonicalization as the product URL before claiming the page.
$squareFiles = array('index.html' => '<main><h1>Shop</h1></main>');
foreach (array('first-thing/ABC123' => array('First Thing', '45'), 'second-thing-/DEF456' => array('Second Thing', '35')) as $route => [$name, $price]) {
    $schemaProduct = array('@type' => 'Product', '@id' => 'https://example.test/item/' . $route . '#product', 'name' => $name, 'url' => 'https://example.test/item/' . $route, 'offers' => array('@type' => 'Offer', 'price' => $price));
    $schema = array('@type' => 'WebPage', 'mainEntity' => $schemaProduct);
    if ('first-thing/ABC123' === $route) $schema = array('@graph' => array($schema));
    $squareFiles['item/' . $route . '/index.html'] = $page($schema, $purchase);
}
$squareResult = $compile($squareFiles);
$squarePlan = $planOf($squareResult);
$squareCandidates = $candidates($squarePlan);
$assert(array('item/first-thing/ABC123/index.html', 'item/second-thing-/DEF456/index.html') === array_keys($squareCandidates), 'mainEntity products with uppercase IDs and trailing-dash slugs claim their detail pages.');
$squareEntities = array();
foreach ($squareResult['source_reports']['compiled_site']['runtime_declarations'] as $declaration) {
    if ('products' === ($declaration['type'] ?? null)) $squareEntities = array_merge($squareEntities, $declaration['payload']['entities']);
}
$assert(2 === count($squareEntities), 'Both mainEntity products are declared.');
WordPressSitePlan::assertValid($squarePlan);
WordPressSitePlan::assertValid($plan);
$assert($claims === $candidates($planOf($compile(array_reverse($files, true)))), 'Candidate identities are stable under file reordering.');

// Deduplicated translated evidence must not claim the canonical row's page.
$negative = $files;
$negative['translations/lampe.html'] = $page($product('/translations/lampe'), $purchase);
$negative['index.html'] = $page($product('/', 'home'), $purchase);
$negative['many.html'] = $page(array('@graph' => array($product('/many', 'one'), $product('/other', 'two'))), $purchase);
$negative['catalog.html'] = $page(array('@type' => 'ItemList', 'itemListElement' => array(array('@type' => 'ListItem', 'item' => $product('/products/lamp')), array('@type' => 'ListItem', 'item' => $product('/other', 'two')))), $purchase);
$assert($claims === $candidates($planOf($compile($negative))), 'Home, multi-product listing and translated duplicates stay page-owned.');
$noBinding = $files;
$noBinding['products/lamp.html'] = $page($product('/products/lamp'));
$assert(array() === $candidates($planOf($compile($noBinding))), 'Schema alone cannot authorize a page handoff.');
$wrongRoute = $files;
$wrongRoute['products/lamp.html'] = $page($product('/elsewhere'), $purchase);
$assert(array() === $candidates($planOf($compile($wrongRoute))), 'A product URL for another page cannot claim this page.');
$singleListing = $files;
$singleListing['products/lamp.html'] = $page(array('@type' => 'ItemList', 'itemListElement' => array(array('@type' => 'ListItem', 'item' => $product('/products/lamp')))), $purchase);
$assert(array() === $candidates($planOf($compile($singleListing))), 'Even a single-item listing with a matching route stays page-owned.');
$group = $product('/products/lamp');
$group['@type'] = 'ProductGroup';
$groupFiles = $files;
$groupFiles['products/lamp.html'] = $page($group, $purchase);
$assert($claims === $candidates($planOf($compile($groupFiles))), 'A single ProductGroup follows the same contract.');

$compact = $result;
$declarations = $compact['source_reports']['compiled_site']['runtime_declarations'];
foreach ($declarations as &$declaration) {
    if ('products' !== ($declaration['type'] ?? null)) continue;
    $rows = $declaration['payload']['entities'];
    $assert($claims['products/lamp.html']['entity_id'] === $rows[0]['id'] && '/products/lamp' === $rows[0]['source_route'], 'Candidate references the stable product row and exact route.');
    $manifest = RuntimeEntityManifest::fromEntities('generic/products/v1', $rows);
    $declaration['payload'] = $manifest['payload'];
    $compact['source_reports']['compiled_site']['runtime_entity_records'] = $manifest['records'];
}
unset($declaration);
$compact['source_reports']['compiled_site']['runtime_declarations'] = RuntimeDeclarations::normalizeList($declarations);
$compactPlan = (new WordPressSitePlan())->fromCompilerResult($compact);
$assert($claims === $candidates($compactPlan), 'Compact entity records preserve the candidate unchanged.');
$replayed = json_decode(json_encode($compactPlan, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);
$resolved = (new WordPressSitePlanResolver())->resolve($replayed, array('theme_uri' => 'https://example.test/theme', 'approved_plan_identity' => $compactPlan['plan_identity']));
$assert($claims === $candidates($resolved) && $compactPlan['plan_identity'] === $resolved['plan_identity'], 'Compact checkpoint replay preserves candidate and approved identity.');

// Importers pass captures under an entry root; detail claims must survive it, and
// unclaimed rows must not inherit a listing page's route.
$rooted = array();
foreach ($negative as $path => $content) $rooted['website/' . $path] = $content;
$rootedResult = (new ArtifactCompiler())->compile(array('entrypoint' => 'website/index.html', 'files' => $rooted))->toArray();
$assert(array('website/products/lamp.html') === array_keys($candidates($planOf($rootedResult))), 'An entry-rooted detail page keeps its candidate.');
foreach ($rootedResult['source_reports']['compiled_site']['runtime_declarations'] as $declaration) {
    if ('products' !== ($declaration['type'] ?? null)) continue;
    foreach ($declaration['payload']['entities'] as $row) $assert(isset($row['whole_page_candidate']) ? '/products/lamp' === ($row['source_route'] ?? null) : !isset($row['source_route']), 'Only claimed rows carry a source route: ' . $row['slug']);
}
// Bare Products use the entry-relative page route even without an explicit URL.
$bareProduct = array('@context' => 'https://schema.org', '@type' => 'Product', 'name' => 'Widget', 'image' => 'https://example.test/widget.jpg', 'offers' => array('@type' => 'AggregateOffer', 'lowPrice' => '30.00', 'highPrice' => '30.00', 'priceCurrency' => 'USD', 'offerCount' => 1));
$bareResult = (new ArtifactCompiler())->compile(array('entrypoint' => 'website/index.html', 'files' => array(
    'website/index.html' => '<main><h1>Shop</h1></main>',
    'website/shop/p/widget/index.html' => $page($bareProduct, '<div class="purchase"><h1>Widget</h1><p>$30.00</p><button type="button" aria-label="Add to cart">Add to cart</button></div>'),
)))->toArray();
$bareEntities = array();
foreach ($bareResult['source_reports']['compiled_site']['runtime_declarations'] as $declaration) {
    if ('products' === ($declaration['type'] ?? null)) $bareEntities = array_merge($bareEntities, $declaration['payload']['entities']);
}
$assert(1 === count($bareEntities), 'The bare Product yields one product entity.');
$assert('blocks-engine/whole-page-candidate/v1' === ($bareEntities[0]['whole_page_candidate'] ?? null), 'A bare Product with a formless purchase binding claims its detail page.');
$assert('/shop/p/widget' === ($bareEntities[0]['source_route'] ?? null), 'The bare Product candidate uses the entry-root-relative route.');
$assert('/shop/p/widget' === ($bareEntities[0]['url'] ?? null), 'The default product URL uses the entry-root-relative route.');
$assert(array('website/shop/p/widget/index.html') === array_keys($candidates($planOf($bareResult))), 'The bare Product candidate reaches the site plan.');
echo "product-whole-page-candidate: ok\n";
