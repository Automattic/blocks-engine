<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

$assert = static function (bool $condition, string $message): void {
    if (! $condition) throw new RuntimeException($message);
};
foreach (array(
    'Portuguese label, checkout name' => array('go_to_checkout', 'Iniciar Compra', true, false),
    'English label' => array('go', 'Checkout', true, false),
    'no checkout evidence' => array('go', 'Iniciar Compra', false, false),
    'nested summary in enclosing form' => array('go_to_checkout', 'Iniciar Compra', true, true),
    'nested summary without checkout evidence' => array('go', 'Iniciar Compra', false, true),
) as $label => [$name, $value, $expectedCart, $nested]) {
    $html = '<!doctype html><html><head><title>Shop</title></head><body>
<div id="modal-cart" class="modal" style="display:none"><form action="/comprar/" method="post" class="cart-panel">
  <div class="modal-header">Carrinho de compras</div><div class="cart-list"></div>
  <div class="alert">O carrinho de compras está vazio.</div>
  <input type="tel" name="zipcode" placeholder="Seu CEP" aria-label="Seu CEP">
  <div class="cart-subtotal row"><span class="cart-subtotal-label">Subtotal (sem frete):</span><span class="cart-subtotal-price">R$0,00</span></div>
  <div class="cart-total row"><span class="cart-total-label">Total:</span><span class="cart-total-price">R$0,00</span></div>
  <input class="btn" type="submit" name="' . $name . '" value="' . $value . '">
</form></div></body></html>';
    if ($nested) {
        $html = '<!doctype html><html><head><title>Shop</title></head><body>
<form action="/comprar/" method="post" class="js-ajax-cart-panel h-100">
 <div class="js-modal-close modal-header"><div class="row"><div class="col p-3">Carrinho de compras</div></div></div>
 <div class="modal-body h-100">
  <div class="js-ajax-cart-list"></div>
  <div class="js-empty-ajax-cart"><div class="alert alert-info">O carrinho de compras está vazio.</div></div>
  <div class="cart-row">
   <div class="js-fulfillment-info" style="display: none"><input type="tel" class="form-control js-shipping-input" name="zipcode" placeholder="Seu CEP" aria-label="Seu CEP"></div>
   <div class="js-visible-on-cart-filled cart-totals-container" style="display:none">
    <div class="cart-subtotal row"><span class="cart-subtotal-label col">Subtotal (sem frete):</span><span class="js-cart-subtotal cart-subtotal-price col-auto">R$0,00</span></div>
   </div>
   <div class="js-cart-total-container cart-totals-container">
    <div class="cart-total row h4"><span class="cart-total-label col">Total:</span><span class="js-cart-total cart-total-price col-auto">R$0,00</span></div>
   </div>
   <div class="js-visible-on-cart-filled" style="display:none">
    <div class="js-ajax-cart-submit mb-3"><input class="btn btn-primary btn-block" type="submit" name="' . $name . '" value="' . $value . '"></div>
   </div>
  </div>
 </div>
</form>
</div>
</body></html>';
    }
    $result = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array('index.html' => $html)))->toArray();
    $carts = array_values(array_filter($result['fallbacks'] ?? array(), static fn (array $finding): bool => 'html_cart_view_fallback' === ($finding['diagnostic_code'] ?? '')));
    $forms = array_values(array_filter($result['fallbacks'] ?? array(), static fn (array $finding): bool => 'html_form_fallback' === ($finding['diagnostic_code'] ?? '') && 'unknown' === ($finding['form_intent'] ?? '')));
    $assert(count($carts) === ($expectedCart ? 1 : 0), $label . ': cart detection');
    $assert(count($forms) === ($expectedCart ? 0 : 1), $label . ': unknown drawer form only without a cart owner');
    foreach ($carts as $cart) {
        $assert('commerce_cart' === ($cart['binding']['role'] ?? ''), $label . ': commerce cart binding');
        $assert(($nested ? '' : 'site_chrome') === ($cart['placement'] ?? ''), $label . ': only hidden drawers are site chrome');
        if ($nested) {
            $assert('form' === ($cart['tag'] ?? ''), $label . ': enclosing form owns cart view');
            $assert(str_starts_with($cart['summary_selector'] ?? '', $cart['selector'] . ' > '), $label . ': summary is nested inside form scope');
        }
        $assert('cart_view_requires_commerce_runtime' === ($cart['reason'] ?? ''), $label . ': runtime requirement');
        $assert('body' !== ($cart['tag'] ?? '') && '' !== ($cart['selector'] ?? ''), $label . ': bounded cart selector');
        $assert('' !== ($cart['binding']['search_block_markup'] ?? ''), $label . ': bindable cart');
    }
}
echo "cart-drawer-checkout-name: ok\n";
