<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/vendor/autoload.php';
use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ProductDetailPageEvidence;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Support\SourceDom;

$assert = static function (bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); };
foreach (array('matching' => 25, 'mismatch' => 24, 'nonuniform' => 25, 'without count' => 25, 'declared group' => 25) as $case => $count) {
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
        . '<div class="purchase-column"><img src="/badge.jpg" alt="Badge"><h1>Woven Cap</h1><p>$27.99</p><form action="/cart/add" method="post">'
        . '<fieldset class="variant-option form-item select" data-variant-option-name="Color"><div class="variant-option-title title"> Color: </div><div class="variant-select-wrapper"><select class="variant-select" name="variant-option-Color-select"><option value="">Select Color</option><option value="Heather Slate">Heather Slate</option><option value="Red">Red</option><option value="Blue">Blue</option><option value="Green">Green</option><option value="Black">Black</option></select></div></fieldset>'
        . '<fieldset class="variant-option form-item select" data-variant-option-name="Size"><div class="variant-option-title title"> Size: </div><div class="variant-select-wrapper"><select class="variant-select" name="variant-option-Size-select"><option value="">Select Size</option><option value="S">Small</option><option value="M">Medium</option><option value="L">Large</option><option value="XL">Extra Large</option><option value="XXL">Double Extra Large</option></select></div></fieldset>'
        . '<button type="submit">Add to cart</button></form></div></section></main></body></html>';
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
    $assert(array('/front.jpg', '/back.jpg', '/badge.jpg') === array_column($product['images'] ?? array(), 'src'), "$case: schema image first, DOM order and responsive deduplication");
    $assert(array('Front', 'Back', 'Badge') === array_column($product['images'], 'alt'), "$case: gallery image row shape");
    if (in_array($case, array('matching', 'without count'), true)) {
        $assert(25 === count($product['variants'] ?? array()), 'Cartesian combinations');
        $assert(array(array('key' => 'color', 'label' => 'Color', 'value' => 'heather-slate', 'value_label' => 'Heather Slate'), array('key' => 'size', 'label' => 'Size', 'value' => 's', 'value_label' => 'Small')) === $product['variants'][0]['attributes'], 'Accessible labels and non-placeholder options');
        foreach ($product['variants'] as $variant) {
            $assert(array('Color', 'Size') === array_column($variant['attributes'], 'label'), 'Every combination retains group labels');
            $assert(2 === count(array_filter(array_column($variant['attributes'], 'value_label'))), 'Every attribute retains its display value');
        }
        $assert('27.99' === $product['variants'][0]['regular_price'] && 'instock' === $product['variants'][0]['stock_status'], 'Variant price and stock');
    } else {
        $assert(!isset($product['variants']), 'Mismatching offerCount declines variants');
        $assert(in_array('product_page_variants_declined', array_column($result['diagnostics'], 'code'), true), 'Mismatch diagnostic');
    }
    $fields = array_column(array_filter($result['diagnostics'], static fn (array $d): bool => 'product_page_field_derived' === ($d['code'] ?? '')), 'field');
    $assert(in_array('images', $fields, true) && in_array('category', $fields, true), 'Derived field provenance');
}
// Captured Squarespace shape: metadata-only primary, lazy sibling gallery, native listing.
$gallery = '<div class="product-gallery"><div class="product-gallery-thumbnails" role="group">';
foreach (array('AAA', 'BBB', 'CCC', 'DDD', 'EEE') as $photo) {
    $gallery .= '<button><img class="product-gallery-thumbnails-item-image" data-src="/media/shirt-front-' . $photo . '.webp" data-image="/media/shirt-front-' . $photo . '.webp" alt="" src="/media/shirt-front-' . $photo . '-t1.webp"></button>';
}
$gallery .= '</div><div class="product-gallery-slides">';
foreach (array('AAA', 'BBB', 'CCC', 'DDD', 'EEE') as $index => $photo) {
    $gallery .= '<img class="product-gallery-slides-item-image" data-src="/media/shirt-front-' . $photo . '.webp" data-image="/media/shirt-front-' . $photo . '.webp"' . (0 === $index ? ' src="/media/shirt-front-' . $photo . '-s1.webp"' : '') . '>';
}
$gallery .= '</div></div>';
$schema = array('@context' => 'https://schema.org', '@type' => 'Product', 'name' => 'Shirt', 'image' => '/media/shirt-front-AAA-2.webp', 'offers' => array('@type' => 'Offer', 'price' => '27.99'));
$detail = '<html><head><title>Shirt</title><meta property="og:image" content="/media/shirt-front-AAA-2.webp"><link rel="image_src" href="/media/shirt-front-AAA-2.webp"><meta itemprop="image" content="/media/shirt-front-AAA-2.webp"><script type="application/ld+json">' . json_encode($schema) . '</script></head><body><main><nav class="product-nav"><a href="/listing/index.html" class="product-nav-breadcrumb-link"> He Wants Me To Be Happy </a><span>›</span><a href="/listing/p/shirt/index.html" class="product-nav-breadcrumb-link"> Shirt </a></nav><section>' . $gallery . '<div class="purchase-column"><img src="/badge.webp"><h1>Shirt</h1><form action="/cart/add" method="post"><label>Size<select name="size"><option value="">Select Size</option><option value="S">Small</option></select></label><button type="submit">Add to cart</button></form></div></section></main></body></html>';
$result = (new ArtifactCompiler())->compile(array('entrypoint' => 'website/index.html', 'files' => array(
    'website/index.html' => '<html><body><a href="/listing/index.html">Shop</a></body></html>',
    'website/listing/index.html' => '<html><body><main><h1>He Wants Me To Be Happy</h1><a href="/listing/p/shirt/index.html">Shirt</a></main></body></html>',
    'website/listing/p/shirt/index.html' => $detail,
)))->toArray();
$product = array();
foreach ($result['source_reports']['wordpress_site_plan']['runtime_declarations'] ?? array() as $declaration) {
    if ('products' === ($declaration['type'] ?? '')) foreach ($declaration['payload']['entities'] as $entity) if ('shirt' === $entity['slug']) $product = $entity;
}
$assert('/media/shirt-front-AAA-2.webp' === ($product['image'] ?? ''), 'Metadata-only declared image retained');
$assert(array_map(static fn (string $photo): string => '/media/shirt-front-' . $photo . '.webp', array('AAA', 'BBB', 'CCC', 'DDD', 'EEE')) === array_column($product['images'] ?? array(), 'src'), 'Five lazy gallery photos in DOM order, without badge or metadata-only rendition');
$assert('He Wants Me To Be Happy' === ($product['category'] ?? ''), 'Entry-root-relative breadcrumb category without grid fallbacks');
// Exercise the caption fallback without metadata-provided accessible names.
foreach (array(
    'legend wins' => array('<fieldset><legend> Color: </legend><div>Wrong caption</div>%s</fieldset>', 'Color'),
    'nested group' => array('<fieldset><legend>Wrong outer caption</legend><div role="group"><div> Size: </div><div>%s</div><div>Wrong later caption</div></div></fieldset>', 'Size'),
    'control-free caption' => array('<fieldset><div><button>Wrong control text</button></div><div> Color: </div><div>%s</div></fieldset>', 'Color'),
    'no caption' => array('<fieldset>%s<div>Wrong later caption</div></fieldset>', ''),
    'no group' => array('<div>Wrong ungrouped caption%s</div>', ''),
) as $case => [$markup, $expectedLabel]) {
    $html = '<html><body><form>' . sprintf($markup, '<select name="not-a-label"><option value="">Not a label</option><option value="red">Red</option></select>') . '</form></body></html>';
    $document = new DOMDocument();
    $document->loadHTML($html);
    $select = $document->getElementsByTagName('select')->item(0);
    $form = $document->getElementsByTagName('form')->item(0);
    $row = array('source_path' => 'product.html', 'slug' => 'cap', 'url' => '/product');
    $diagnostics = array();
    ProductDetailPageEvidence::fill($row, array('offers' => array('price' => '27.99')), array('selector' => SourceDom::elementSelector($form), 'controls' => array(array('tag' => 'select', 'selector' => SourceDom::elementSelector($select), 'options' => array(array('value' => '', 'label' => 'Not a label'), array('value' => 'red', 'label' => 'Red'))))), array(array('path' => 'product.html', 'content' => $html)), array(), '', $diagnostics);
    $assert($expectedLabel === ($row['variants'][0]['attributes'][0]['label'] ?? ''), "$case: bounded option-group caption");
}
echo "product-detail-page-evidence: ok\n";
