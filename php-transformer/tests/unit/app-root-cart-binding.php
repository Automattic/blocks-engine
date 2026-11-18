<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

$assert = static function (bool $condition, string $message): void {
    if (! $condition) throw new RuntimeException($message);
};
$html = <<<'HTML'
<!doctype html><html><head><title>Shop</title></head><body>
<div id="app">
 <div class="announcement">Free delivery</div>
 <header><button id="open-cart" aria-label="Open bag">Bag (0)</button></header>
 <main><h1>Shop</h1><section><article><h2>Canvas tote</h2><p>$20.00</p><button>Add to bag</button></article><article><h2>Cotton cap</h2><p>$15.00</p><button>Add to bag</button></article></section><form id="newsletter"><label>Email <input type="email" name="email"></label><button>Subscribe</button></form></main>
 <footer>Our store</footer>
 <div class="cart-drawer" id="cart-drawer" aria-label="Shopping bag">
  <div class="drawer-head"><h3>Your bag <span id="drawer-count">(0)</span></h3><button id="close-cart" aria-label="Close bag">×</button></div>
  <div id="cart-items"></div>
  <div class="drawer-foot"><p>Subtotal <strong id="subtotal">$0.00</strong></p><button class="primary-btn" id="checkout">Continue to checkout →</button></div>
 </div>
 <div class="scrim" id="scrim"></div>
</div>
<script src="app.js"></script>
</body></html>
HTML;
$script = <<<'JS'
document.getElementById('app');
document.getElementById('cart-drawer');

JS;
$result = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array('index.html' => $html, 'app.js' => $script)))->toArray();
$carts = array_values(array_filter($result['fallbacks'] ?? array(), static fn (array $finding): bool => 'html_cart_view_fallback' === ($finding['diagnostic_code'] ?? '')));
$assert(1 === count($carts), 'One cart view is detected.');
$cart = $carts[0]['binding'] ?? array();
$assert(str_contains($cart['search_block_markup'] ?? '', 'id="cart-drawer"'), 'The cart binding retains the drawer identity.');
$assert(! str_contains($cart['search_block_markup'] ?? '', 'id="app"'), 'The cart does not replace the app root.');
$forms = array_filter($result['fallbacks'] ?? array(), static fn (array $finding): bool => 'html_form_fallback' === ($finding['diagnostic_code'] ?? ''));
$assert(1 === count($forms), 'The unrelated newsletter binding is not superseded by the cart.');
$bindings = array();
$collect = static function (array $value) use (&$collect, &$bindings): void {
    if ('generic/block-binding/v1' === ($value['schema'] ?? '') && isset($value['position'])) {
        $bindings[] = $value;
        return;
    }
    foreach ($value as $child) if (is_array($child)) $collect($child);
};
$collect($result['fallbacks'] ?? array());
$assert(count($bindings) >= 2, 'The fixture exercises multiple provider bindings.');
foreach ($bindings as $index => $binding) {
    $start = $binding['position']['offset'];
    $end = $start + $binding['position']['length'];
    foreach (array_slice($bindings, $index + 1) as $other) {
        $otherStart = $other['position']['offset'];
        $otherEnd = $otherStart + $other['position']['length'];
        $assert($end <= $otherStart || $otherEnd <= $start, 'Provider binding ranges do not overlap.');
    }
}
echo "app-root-cart-binding: ok\n";
