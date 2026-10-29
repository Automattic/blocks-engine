<?php
declare(strict_types=1);

/** Translated detail pages and cards share source identity and purchase ownership (#3682, #3724). */
require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};
foreach (array(
    array('productGroupID', true, 'a'),
    array('sku', false, 'b'),
    array('@id', false, 'b'),
    array('alternates-only', true, 'b'),
) as [$identity, $alternates, $translatedSlug]) {
    $files = array();
    $purchase = '<div><label><input type="radio" name="Size" value="S">S</label><button type="button">Add to cart</button></div>';
    foreach (array('' => 'Socks', 'fr/' => 'Chaussettes') as $prefix => $name) {
        $product = array('@type' => 'ProductGroup', 'name' => $name, 'variesBy' => 'size', 'hasVariant' => array());
        if ('alternates-only' !== $identity) $product[$identity] = '1';
        foreach (array('S', 'L') as $size) $product['hasVariant'][] = array('@type' => 'Product', 'name' => $name . ' ' . $size, 'size' => $size, 'offers' => array('@type' => 'Offer', 'price' => '20'));
        $pageSlug = '' === $prefix ? 'a' : $translatedSlug;
        $head = $alternates ? '<link rel="alternate" hreflang="x-default" href="/p/a/"><link rel="alternate" hreflang="en" href="/p/a/"><link rel="alternate" hreflang="fr" href="/fr/p/' . $translatedSlug . '/">' : '';
        $files[$prefix . 'p/' . $pageSlug . '/index.html'] = '<html lang="' . ('' === $prefix ? 'en' : 'fr') . '"><head><title>' . $name . '</title>' . $head . '<script type="application/ld+json">' . json_encode($product) . '</script></head><body><main><h1>' . $name . '</h1>' . $purchase . '</main></body></html>';
        $files[$prefix . 'index.html'] = '<html><head><title>Catalog</title></head><body><main><h1>Catalog</h1><div class="grid" style="display:grid"><article><h2>' . $name . '</h2><img src="/socks.jpg" alt="' . $name . '"><p>$20.00</p><a href="/' . $prefix . 'p/' . $pageSlug . '/"><span>Link</span></a><button>Add to Cart</button></article></div></main></body></html>';
    }
    $expected = null;
    foreach (array($files, array_reverse($files, true)) as $input) {
        $result = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => $input))->toArray();
        $entities = $grids = array();
        foreach ($result['source_reports']['wordpress_site_plan']['runtime_declarations'] ?? array() as $declaration) {
            if ('products' === ($declaration['type'] ?? null)) $entities = $declaration['payload']['entities'];
            if ('product_grids' === ($declaration['type'] ?? null)) $grids = $declaration['payload']['entities'];
        }
        $assert(1 === count($entities), $identity . ': expected one product, got ' . json_encode(array_column($entities, 'slug')));
        $entity = $entities[0];
        $canonicalSlug = $alternates ? 'a' : 'b';
        $canonicalName = $alternates ? 'Socks' : 'Chaussettes';
        $assert($canonicalSlug === $entity['slug'] && $canonicalName === $entity['name'], $identity . ': x-default wins, otherwise stable source order');
        $assert(2 === count($entity['variants'] ?? array()), $identity . ': two declared variants');
        $assert(($alternates ? '/p/a' : '/fr/p/b') === rtrim($entity['url'] ?? '', '/'), $identity . ': real source URL');
        $paths = array_column($entity['bindings'] ?? array(), 'source_path');
        $assert(in_array('index.html', $paths, true) && in_array('fr/index.html', $paths, true), $identity . ': both card bindings survive');
        $purchaseBindings = array_values(array_filter($entity['bindings'] ?? array(), static fn (array $binding): bool => 'commerce_add_to_cart_form' === ($binding['role'] ?? '')));
        $purchasePaths = array_column($purchaseBindings, 'source_path');
        sort($purchasePaths, SORT_STRING);
        $assert(array('fr/p/' . $translatedSlug . '/index.html', 'p/a/index.html') === $purchasePaths, $identity . ': both detail pages own exactly one purchase binding, never card descendants');
        $assert(2 === count($grids), $identity . ': both grids are declared');
        foreach ($grids as $grid) foreach ($grid['items'] ?? array() as $item) $assert($canonicalSlug === $item['product'], $identity . ': grid item identity');
        if (null !== $expected) $assert($expected === $entities, $identity . ': input order is irrelevant');
        $expected = $entities;
    }
    // A translated page with a second, distinct schema product is ambiguous,
    // even when its first product was merged into the canonical page's group.
    $translatedPath = 'fr/p/' . $translatedSlug . '/index.html';
    $files[$translatedPath] = str_replace('</head>', '<script type="application/ld+json">{"@type":"Product","name":"Other","offers":{"price":"10"}}</script></head>', $files[$translatedPath]);
    // A page without schema must not inherit ownership from language links.
    $files['unowned/index.html'] = '<html><head><title>Unowned</title><link rel="alternate" hreflang="en" href="/p/a/"></head><body><main><h1>Unowned</h1>' . $purchase . '</main></body></html>';
    $result = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => $files))->toArray();
    $purchasePaths = array();
    foreach ($result['source_reports']['wordpress_site_plan']['runtime_declarations'] ?? array() as $declaration) {
        if ('products' !== ($declaration['type'] ?? null)) continue;
        foreach ($declaration['payload']['entities'] as $entity) {
            foreach ($entity['bindings'] ?? array() as $binding) {
                if ('commerce_add_to_cart_form' === ($binding['role'] ?? '')) $purchasePaths[] = $binding['source_path'];
            }
        }
    }
    $assert(array('p/a/index.html') === $purchasePaths, $identity . ': ambiguous and schema-less pages receive no purchase binding');
}
$result = (new ArtifactCompiler())->compile(array('entrypoint' => 'products/desk-lamp.html', 'files' => array(
    'products/desk-lamp.html' => '<html><head><title>Desk Lamp</title><script type="application/ld+json">{"@type":"ProductGroup","name":"Desk Lamp","offers":{"@type":"Offer","price":"20"}}</script></head><body><main><h1>Desk Lamp</h1></main></body></html>',
)))->toArray();
$entities = array();
foreach ($result['source_reports']['wordpress_site_plan']['runtime_declarations'] ?? array() as $declaration) {
    if ('products' === ($declaration['type'] ?? null)) $entities = $declaration['payload']['entities'];
}
$assert(array('desk-lamp') === array_column($entities, 'slug'), 'URL-less ProductGroup on products/desk-lamp.html uses the extensionless slug');
echo "product-translated-identity: ok\n";
