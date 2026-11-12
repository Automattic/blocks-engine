<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

$assert = static function (bool $condition, string $message): void {
    if (! $condition) throw new RuntimeException($message);
};
foreach (array(
    'Portuguese label, checkout name' => array('go_to_checkout', 'Iniciar Compra', true),
    'English label' => array('go', 'Checkout', true),
    'no checkout evidence' => array('go', 'Iniciar Compra', false),
) as $label => [$name, $value, $expectedCart]) {
    $html = '<!doctype html><html><head><title>Shop</title></head><body>
<div id="modal-cart" class="modal" style="display:none"><form action="/comprar/" method="post" class="cart-panel">
  <div class="modal-header">Carrinho de compras</div><div class="cart-list"></div>
  <div class="alert">O carrinho de compras está vazio.</div>
  <input type="tel" name="zipcode" placeholder="Seu CEP" aria-label="Seu CEP">
  <div class="cart-subtotal row"><span class="cart-subtotal-label">Subtotal (sem frete):</span><span class="cart-subtotal-price">R$0,00</span></div>
  <div class="cart-total row"><span class="cart-total-label">Total:</span><span class="cart-total-price">R$0,00</span></div>
  <input class="btn" type="submit" name="' . $name . '" value="' . $value . '">
</form></div></body></html>';
    $result = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array('index.html' => $html)))->toArray();
    $carts = array_values(array_filter($result['fallbacks'] ?? array(), static fn (array $finding): bool => 'html_cart_view_fallback' === ($finding['diagnostic_code'] ?? '')));
    $forms = array_values(array_filter($result['fallbacks'] ?? array(), static fn (array $finding): bool => 'html_form_fallback' === ($finding['diagnostic_code'] ?? '') && 'unknown' === ($finding['form_intent'] ?? '')));
    $assert(count($carts) === ($expectedCart ? 1 : 0), $label . ': cart detection');
    $assert(count($forms) === ($expectedCart ? 0 : 1), $label . ': unknown drawer form only without a cart owner');
    foreach ($carts as $cart) {
        $assert('commerce_cart' === ($cart['binding']['role'] ?? ''), $label . ': commerce cart binding');
        $assert('site_chrome' === ($cart['placement'] ?? ''), $label . ': hidden drawer is site chrome');
        $assert('cart_view_requires_commerce_runtime' === ($cart['reason'] ?? ''), $label . ': runtime requirement');
        $assert('body' !== ($cart['tag'] ?? '') && '' !== ($cart['selector'] ?? ''), $label . ': bounded cart selector');
        $assert('' !== ($cart['binding']['search_block_markup'] ?? ''), $label . ': bindable cart');
    }
}
echo "cart-drawer-checkout-name: ok\n";
