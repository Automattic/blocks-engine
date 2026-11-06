<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/vendor/autoload.php';
use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

$assert = static function (bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); };
foreach (array('matching' => 4, 'mismatch' => 3, 'nonuniform' => 4, 'without count' => 4, 'declared group' => 4) as $case => $count) {
    $schema = array('@context' => 'https://schema.org', '@type' => 'Product', 'name' => 'Woven Cap', 'image' => '/front-large.jpg', 'offers' => array('@type' => 'AggregateOffer', 'lowPrice' => '27.99', 'highPrice' => '27.99', 'offerCount' => $count));
    if ('declared group' === $case) {
        $schema['@type'] = 'ProductGroup';
        $schema['category'] = 'Declared';
        $schema['image'] = array('/declared.jpg', '/declared-back.jpg');
        $schema['variesBy'] = 'color';
        $schema['hasVariant'] = array();
        foreach (array('Gold', 'Silver') as $color) $schema['hasVariant'][] = array('@type' => 'Product', 'name' => 'Woven Cap', 'color' => $color, 'offers' => array('@type' => 'Offer', 'price' => '30'));
    }
    if ('nonuniform' === $case) $schema['offers']['highPrice'] = '29.99';
    if ('without count' === $case) unset($schema['offers']['offerCount']);
    $detail = '<html><head><title>Woven Cap</title><script type="application/ld+json">' . json_encode($schema) . '</script></head><body><main>'
        . '<nav><a href="/caps">Caps</a> / <a href="/products/woven-cap">Woven Cap</a></nav><section class="product-detail"><div class="gallery">'
        . '<div class="slide"><img src="/back.jpg" srcset="/back-small.jpg 200w, /back.jpg 800w" alt="Back"></div>'
        . '<div class="slide"><img src="/front.jpg" srcset="/front-small.jpg 200w, /front-large.jpg 800w" alt="Front"></div></div>'
        . '<div class="thumbnails"><img src="/front-small.jpg"><img src="/back-small.jpg"></div>'
        . '<h1>Woven Cap</h1><p>$27.99</p><form action="/cart/add" method="post">'
        . '<label>Color<select name="color"><option value="">Choose Color</option><option value="red">Red</option><option value="blue">Blue</option></select></label>'
        . '<label>Size<select name="size"><option value="">Choose Size</option><option value="s">Small</option><option value="l">Large</option></select></label>'
        . '<button type="submit">Add to cart</button></form></section></main></body></html>';
    $listing = '<html><head><title>Caps</title></head><body><main><h1>Caps</h1><div class="grid">';
    foreach (array('Woven Cap', 'Other Cap', 'Third Cap', 'Fourth Cap') as $name) {
        $slug = strtolower(str_replace(' ', '-', $name));
        $listing .= '<div class="card" itemscope itemtype="https://schema.org/Product"><a href="/products/' . $slug . '"><img src="/card.jpg"><h3>' . $name . '</h3></a><p>$27.99</p><button>Add to cart</button></div>';
    }
    $listing .= '</div></main></body></html>';
    $result = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array('index.html' => '<html><body><a href="/caps">Shop</a></body></html>', 'caps.html' => $listing, 'products/woven-cap.html' => $detail)))->toArray();
    $product = array();
    foreach ($result['source_reports']['wordpress_site_plan']['runtime_declarations'] ?? array() as $declaration) {
        if ('products' === ($declaration['type'] ?? '')) foreach ($declaration['payload']['entities'] as $entity) if ('woven-cap' === $entity['slug']) $product = $entity;
    }
    $assert(!empty($product['controls']), "$case: purchase controls joined");
    if ('declared group' === $case) {
        $assert('Declared' === ($product['category'] ?? ''), 'Declared category wins');
        $assert(array('/declared.jpg', '/declared-back.jpg') === array_column($product['images'] ?? array(), 'src'), 'Declared gallery wins');
        $assert(2 === count($product['variants'] ?? array()) && 'gold' === $product['variants'][0]['attributes'][0]['value'] && '30' === $product['variants'][0]['regular_price'], 'Declared variants unchanged');
        continue;
    }
    $assert('Caps' === ($product['category'] ?? ''), "$case: breadcrumb listing category");
    $assert(array('/front-large.jpg', '/back.jpg') === array_column($product['images'] ?? array(), 'src'), "$case: schema image first, DOM order and responsive deduplication");
    $assert(array('Front', 'Back') === array_column($product['images'], 'alt'), "$case: gallery image row shape");
    if (in_array($case, array('matching', 'without count'), true)) {
        $assert(4 === count($product['variants'] ?? array()), 'Cartesian combinations');
        $assert(array(array('key' => 'color', 'label' => 'Color', 'value' => 'red', 'value_label' => 'Red'), array('key' => 'size', 'label' => 'Size', 'value' => 's', 'value_label' => 'Small')) === $product['variants'][0]['attributes'], 'Accessible labels and non-placeholder options');
        $assert('27.99' === $product['variants'][0]['regular_price'] && 'instock' === $product['variants'][0]['stock_status'], 'Variant price and stock');
    } else {
        $assert(!isset($product['variants']), 'Mismatching offerCount declines variants');
        $assert(in_array('product_page_variants_declined', array_column($result['diagnostics'], 'code'), true), 'Mismatch diagnostic');
    }
    $fields = array_column(array_filter($result['diagnostics'], static fn (array $d): bool => 'product_page_field_derived' === ($d['code'] ?? '')), 'field');
    $assert(in_array('images', $fields, true) && in_array('category', $fields, true), 'Derived field provenance');
}
echo "product-detail-page-evidence: ok\n";
