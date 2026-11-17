<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

$schema = '<script type="application/ld+json">{"@context":"https://schema.org","@type":"Product","name":"Sample mug","sku":"1001","offers":{"@type":"Offer","price":"35.00","priceCurrency":"USD","availability":"https://schema.org/InStock"}}</script>';
$quantity = '<div class="quantity"><label for="Quantity-main">Quantity</label><input id="Quantity-main" type="number" name="quantity" value="1" min="1" form="product-form-main"></div>';
$form = '<form method="post" action="/cart/add" id="product-form-main" class="form" data-type="add-to-cart-form">'
    . '<input type="hidden" name="form_type" value="product"><input type="hidden" name="id" value="2001" class="product-variant-id">'
    . '<div class="product-form__buttons"><button type="submit" name="add" class="product-form__submit button">Add to cart</button></div>'
    . '<input type="hidden" name="product-id" value="1001">%s</form>';
$path = 'products/sample/index.html';
foreach (array('product-form', 'unknown-purchase-shell', '', 'runtime-checkout') as $wrapper) {
    foreach (array(false, true) as $inside) {
        foreach (array(1, 0, 2) as $productCount) {
            $runtime = 'runtime-checkout' === $wrapper;
            $checkout = $runtime ? '<express-checkout data-token="sample"></express-checkout>' : '';
            $purchase = sprintf($form, ($inside ? $quantity : '') . $checkout);
            if ('' !== $wrapper) $purchase = '<' . $wrapper . ' class="product-form" data-section-id="main">' . $purchase . '</' . $wrapper . '>';
            $head = 0 === $productCount ? '' : $schema;
            if (2 === $productCount) $head .= str_replace(array('Sample mug', '1001'), array('Other mug', '1002'), $schema);
            if ($runtime) $head .= '<script>customElements.define("express-checkout", class extends HTMLElement {});</script>';
            $html = '<!DOCTYPE html><html><head>' . $head . '</head><body><main><section class="product__info"><h1>Sample mug</h1><p class="price">$35.00</p>'
                . ($inside ? '' : $quantity) . $purchase . '</section></main></body></html>';
            $result = (new ArtifactCompiler())->compile(array('entrypoint' => $path, 'files' => array($path => $html)))->toArray();
            if ('failed' === $result['status']) throw new RuntimeException('Product page must compile.');
            $bindings = array();
            foreach ($result['source_reports']['wordpress_site_plan']['runtime_declarations'] ?? array() as $declaration) {
                if ('products' !== ($declaration['type'] ?? null)) continue;
                foreach ($declaration['payload']['entities'] as $product) {
                    foreach ($product['bindings'] ?? array() as $binding) {
                        if ('commerce_add_to_cart_form' !== ($binding['role'] ?? null)) continue;
                        if ('1001' !== ($product['sku'] ?? null)) throw new RuntimeException('Purchase must belong to the schema product.');
                        $bindings[] = $binding;
                    }
                }
            }
            $expected = 1 === $productCount ? 1 : 0;
            if ($expected !== count($bindings)) throw new RuntimeException('Expected ' . $expected . ' purchase bindings: ' . json_encode(array($wrapper, $inside, $productCount, count($bindings))));
            foreach ($bindings as $binding) {
                if ($path !== ($binding['source_path'] ?? null) || !str_contains($binding['search_block_markup'] ?? '', 'Add to cart') || empty($binding['projected_anchor'])) {
                    throw new RuntimeException('Purchase binding must anchor the page-owned form.');
                }
            }
        }
    }
}
echo "product-page-cart-add-form: ok\n";
