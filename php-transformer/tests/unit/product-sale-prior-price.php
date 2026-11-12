<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

$pix = '<div class="item-price-container"><span class="item-price">R$31,68</span></div>'
    . '<div class="payment-discount-price-product-container"><span class="payment-discount-price-product">R$30,10</span> <span>com</span> <span>Pix</span></div>';
foreach (array(
    'payment alternative' => array($pix, '31.68', null),
    'hidden zero compare' => array($pix . '<span class="price-compare" style="display:none">R$0,00</span>', '31.68', null),
    'del ins' => array('<del>$40</del> <ins>$30</ins>', '40', '30'),
    'compare sale tokens' => array('<span class="price price--compare">$40</span><span class="price price--sale">$30</span>', '40', '30'),
    'hidden nonzero compare' => array($pix . '<span class="price-compare" hidden>$40</span>', '31.68', null),
    'zero compare' => array($pix . '<span class="price-compare">R$0,00</span>', '31.68', null),
    'explicit sale token' => array('<span class="price">$48</span><span class="price sale">$36</span>', '48', '36'),
    'discount beside prior price' => array('<s class="price">$40</s><span class="price discount">$30</span>', '40', '30'),
    'declared strike' => array('<span class="price" style="text-decoration: line-through">$40</span><span class="price--sale">$30</span>', '40', '30'),
) as $label => [$prices, $regular, $sale]) {
    $html = '<!DOCTYPE html><html><head><meta charset="utf-8"><title>Catalog</title></head><body><main><div style="display:grid"><article><h2>Desk Lamp</h2><img src="/lamp.jpg" alt="Lamp">'
        . $prices . '<a href="/products/lamp/">Details</a><button>Add to cart</button></article></div></main></body></html>';
    $result = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array('index.html' => $html)));
    $products = array();
    foreach ($result->toArray()['source_reports']['wordpress_site_plan']['runtime_declarations'] ?? array() as $declaration) {
        if ('products' === ($declaration['type'] ?? null)) {
            $products = array_merge($products, $declaration['payload']['entities']);
        }
    }
    if (1 !== count($products) || $regular !== ($products[0]['regular_price'] ?? $products[0]['price'] ?? null) || $sale !== ($products[0]['sale_price'] ?? null)) {
        throw new RuntimeException($label . ': unexpected seeded product fields: ' . json_encode($products));
    }
}
echo "product-sale-prior-price: ok\n";
