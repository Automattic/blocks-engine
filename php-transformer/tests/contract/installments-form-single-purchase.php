<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};

// #7423: payment terms carry item metadata, not a second purchase surface.
$html = <<<'HTML'
<!DOCTYPE html><html lang="en"><head><title>Product</title>
<script type="application/ld+json">{"@context":"https://schema.org","@type":"Product","name":"Product","offers":{"@type":"Offer","price":"25.00","priceCurrency":"USD"}}</script>
</head><body><main>
<h1>Product</h1>
<div class="price-descriptors">
  <p>$25.00</p>
  <form method="post" action="/cart/add" id="product-form-installments" class="shopify-product-form">
    <input type="hidden" name="form_type" value="product">
    <input type="hidden" name="utf8" value="✓">
    <input type="hidden" name="id" value="50244189356324">
    <shopify-payment-terms variant-id="50244189356324" shopify-meta="…"></shopify-payment-terms>
    <p>Installment options are shown at checkout.</p>
  </form>
</div>
<div class="buy-buttons-row">
  <form method="post" action="/cart/add" class="shopify-product-form">
    <input type="hidden" name="id" value="50244189356324">
    <label>Quantity<input type="number" name="quantity" value="1" min="1"></label>
    <button type="submit">Add to cart</button>
  </form>
</div>
</main></body></html>
HTML;

$result = (new ArtifactCompiler())->compile(array('entrypoint' => 'products/product.html', 'files' => array('products/product.html' => $html)))->toArray();
$plan = $result['source_reports']['wordpress_site_plan'];
$purchases = array();
foreach ($plan['runtime_declarations'] as $declaration) {
    if ('products' !== ($declaration['type'] ?? null)) continue;
    foreach ($declaration['payload']['entities'] as $entity) {
        foreach ($entity['bindings'] ?? array() as $binding) {
            if ('commerce_add_to_cart_form' === ($binding['role'] ?? null)) $purchases[] = $binding;
        }
    }
}
$assert(1 === count($purchases), 'Exactly one add-to-cart surface is emitted.');
$assert(str_contains($purchases[0]['search_block_markup'], 'quantity'), 'The purchase surface belongs to the quantity form.');
$markup = implode("\n", array_column($plan['pages'], 'canonical_block_markup'));
$assert(str_contains($markup, 'Installment options are shown at checkout.'), 'Visible installments content survives.');

echo "installments-form-single-purchase: ok\n";
