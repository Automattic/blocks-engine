<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\SchemaProductEvidence;

$assert = static function (bool $ok, string $message): void {
    if (!$ok) throw new RuntimeException($message);
};
$path = 'produtos/lamp-a/index.html';
$product = static fn (string $name): array => array('@type' => 'Product', 'name' => $name, 'offers' => array('@type' => 'Offer', 'price' => '89.9', 'priceCurrency' => 'BRL'));
$own = $product('Lamp A');
$own['@id'] = 'https://shop.example/produtos/lamp-a/';
$own['offers']['url'] = $own['@id'];
$related = array();
foreach (array('url', 'offers.url', 'mainEntityOfPage', '@id') as $index => $field) {
    $card = $product('Lamp ' . chr(66 + $index));
    $url = 'https://shop.example/produtos/lamp-' . chr(98 + $index) . '/';
    if ('offers.url' === $field) $card['offers']['url'] = $url;
    elseif ('mainEntityOfPage' === $field) $card[$field] = array('@type' => 'WebPage', '@id' => $url);
    else $card[$field] = $url;
    $related[] = $card;
}
$cases = array(
    'own only' => array(array($own), array('lamp-a'), true),
    'own plus related' => array(array_merge(array($own), $related), array('lamp-a'), true),
    'route-less ambiguity' => array(array($product('Lamp A'), $product('Lamp B')), array('lamp-a', 'lamp-b'), false),
    'self-routed ambiguity' => array(array($own, $own), array('lamp-a'), false),
    'listing without self claim' => array($related, array('lamp-b', 'lamp-c', 'lamp-d', 'lamp-e'), false),
);
foreach ($cases as $label => [$products, $expectedClaims, $detail]) {
    $scripts = '';
    foreach ($products as $index => $item) {
        // Match WebPage.mainEntity plus separate related Product scripts in the reduction.
        if (0 === $index && $detail) $item = array('@type' => 'WebPage', 'mainEntity' => $item);
        $scripts .= '<script type="application/ld+json">' . json_encode($item) . '</script>';
    }
    $html = '<!doctype html><html><head><title>Lamp A</title>' . $scripts . '</head><body><main><h1>Lamp A</h1><p>R$89.90</p><form id="product_form" method="post"><input type="hidden" name="variant_id" value="123"><input type="number" name="quantity" value="1" min="1"><button type="submit">Add to cart</button></form></main></body></html>';
    $diagnostics = $routes = $pageProducts = $detailPages = array();
    $rows = SchemaProductEvidence::rows(array(array('path' => $path, 'content' => $html)), SchemaProductEvidence::productSlug(...), SchemaProductEvidence::productPrice(...), $diagnostics, $routes, $pageProducts, $detailPages);
    $claims = $pageProducts[$path] ?? array();
    sort($claims);
    $assert($expectedClaims === $claims, $label . ': pageProducts: ' . json_encode($pageProducts));
    $assert(($detail ? array($path => '/produtos/lamp-a') : array()) === $detailPages, $label . ': detailPages: ' . json_encode($detailPages));
    $assert(count(array_unique(array_column($products, 'name'))) === count($rows), $label . ': all products remain evidence');
    if ('own plus related' !== $label) continue;
    $result = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array('index.html' => '<html><body>Shop</body></html>', $path => $html)))->toArray();
    $assert('failed' !== $result['status'], 'Related-products detail page compiles');
    $entities = array();
    foreach ($result['source_reports']['wordpress_site_plan']['runtime_declarations'] ?? array() as $declaration) {
        if ('products' === ($declaration['type'] ?? null)) $entities = array_column($declaration['payload']['entities'], null, 'slug');
    }
    $bindings = array_values(array_filter($entities['lamp-a']['bindings'] ?? array(), static fn (array $binding): bool => 'commerce_add_to_cart_form' === ($binding['role'] ?? null)));
    $assert(1 === count($bindings) && $path === $bindings[0]['source_path'], 'Own product gets the page purchase-form binding');
    foreach (array('lamp-b', 'lamp-c', 'lamp-d', 'lamp-e') as $slug) {
        $assert(isset($entities[$slug]), 'Related product remains available: ' . $slug);
        $assert(!in_array('commerce_add_to_cart_form', array_column($entities[$slug]['bindings'] ?? array(), 'role'), true), 'Related product does not own the purchase form: ' . $slug);
    }
}
echo "schema-product-related-page-claims: ok\n";
