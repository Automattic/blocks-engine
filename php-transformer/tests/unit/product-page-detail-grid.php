<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

// Regression for blocks-engine#6351: a detail layout is not a one-item listing.
$schema = '<script type="application/ld+json">{"@context":"https://schema.org","@type":"Product","name":"Sample mug","sku":"1001","offers":{"@type":"Offer","price":"35.00","priceCurrency":"USD","availability":"https://schema.org/InStock"}}</script>';
$card = '<section class="product__info"><h1>Sample mug</h1><p class="price">$35.00</p><div class="quantity"><label for="Quantity-main">Quantity</label><input id="Quantity-main" type="number" name="quantity" value="1" min="1" form="product-form-main"></div><product-form class="product-form" data-section-id="main"><form method="post" action="/cart/add" id="product-form-main" class="form" data-type="add-to-cart-form"><input type="hidden" name="form_type" value="product"><input type="hidden" name="id" value="2001" class="product-variant-id"><div class="product-form__buttons"><button type="submit" name="add" class="product-form__submit button">Add to cart</button></div><input type="hidden" name="product-id" value="1001"></form></product-form></section>';
$path = 'products/sample/index.html';
foreach (array(array(true, 1), array(true, 2), array(false, 1)) as [$singleProduct, $cards]) {
    $html = '<!DOCTYPE html><html><head>' . ($singleProduct ? $schema : '') . '</head><body><main><div class="product grid">' . $card
        . (2 === $cards ? str_replace(array('Sample mug', '1001', '2001', '-main'), array('Other mug', '1002', '2002', '-other'), $card) : '') . '</div></main></body></html>';
    $result = (new ArtifactCompiler())->compile(array('entrypoint' => $path, 'files' => array($path => $html)))->toArray();
    if ('failed' === $result['status']) throw new RuntimeException('Detail grid must compile.');
    $bindings = array();
    foreach ($result['source_reports']['wordpress_site_plan']['runtime_declarations'] ?? array() as $declaration) {
        if ('products' !== ($declaration['type'] ?? null)) continue;
        foreach ($declaration['payload']['entities'] as $product) {
            foreach ($product['bindings'] ?? array() as $binding) {
                if ('commerce_add_to_cart_form' !== ($binding['role'] ?? null)) continue;
                if ('1001' !== ($product['sku'] ?? null)) throw new RuntimeException('Purchase must belong to sku 1001.');
                $bindings[] = $binding;
            }
        }
    }
    $detail = $singleProduct && 1 === $cards;
    if (($detail ? 1 : 0) !== count($bindings)) throw new RuntimeException('Detail layout must retain exactly one page purchase binding; listings must not acquire one: ' . json_encode(array($singleProduct, $cards, count($bindings))));
    $hasGrid = str_contains(json_encode($result, JSON_THROW_ON_ERROR), 'html_product_grid_fallback');
    if ($hasGrid === $detail) throw new RuntimeException('Only real listings must report a product grid.');
}
echo "product-page-detail-grid: ok\n";
