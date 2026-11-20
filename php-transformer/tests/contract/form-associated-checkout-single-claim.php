<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

$assert = static function (bool $condition, string $message): void {
    if (! $condition) throw new RuntimeException($message);
};
// A cart drawer whose footer field and checkout submit sit outside the drawer
// form and join it through `form=` (#7204). The footer is not a second form,
// so the checkout control carries exactly one claim.
$html = <<<'HTML'
<!doctype html><html><head><title>Sample shirt</title></head><body>
<main><h1>Sample shirt</h1><p>$20.00</p>
<form action="/cart/add" method="post"><input type="hidden" name="id" value="1"><input type="number" name="quantity" value="1"><button type="submit" name="add">Add to cart</button></form></main>
<div class="drawer"><div id="Drawer" class="cart-drawer"><div class="drawer__inner" role="dialog" aria-modal="true" aria-label="Your cart" tabindex="-1">
<div class="drawer__header"><h2>Your cart</h2><button class="drawer__close" type="button" aria-label="Close">x</button></div>
<div class="drawer__items"><form action="/cart" id="CartDrawer-Form" method="post"><div id="CartDrawer-Items"><p role="status"></p></div></form></div>
<div class="drawer__footer"><div class="drawer__totals"><div class="totals" role="status"><h2>Estimated total</h2><p>$0.00</p></div><small>Taxes and shipping calculated at checkout</small></div>
<div class="field"><input type="text" id="CartDrawer-Reference" name="attributes[Reference]" form="CartDrawer-Form" placeholder="Reference" required><label for="CartDrawer-Reference">Reference</label></div>
<div class="drawer__ctas"><button type="submit" id="CartDrawer-Checkout" class="cart__checkout-button button" name="checkout" form="CartDrawer-Form">Check out</button></div></div>
</div></div></div>
</body></html>
HTML;
$path = 'products/sample-shirt/index.html';
$result = (new ArtifactCompiler())->compile(array('entrypoint' => $path, 'files' => array($path => $html)))->toArray();
$assert('failed' !== $result['status'], 'The product page compiles.');

$bindings = array();
foreach ($result['source_reports']['wordpress_site_plan']['runtime_declarations'] ?? array() as $declaration) {
    foreach ($declaration['payload']['entities'] ?? array() as $entity) {
        foreach ($entity['bindings'] ?? array() as $binding) {
            if (is_array($binding['position'] ?? null)) $bindings[] = $binding;
        }
    }
}
$assert(count($bindings) >= 2, 'The fixture exercises multiple plan bindings, got ' . count($bindings) . '.');
foreach ($bindings as $index => $binding) {
    $start = $binding['position']['offset'];
    $end = $start + $binding['position']['length'];
    foreach (array_slice($bindings, $index + 1) as $other) {
        if (($binding['source_path'] ?? null) !== ($other['source_path'] ?? null)) continue;
        $otherStart = $other['position']['offset'];
        $otherEnd = $otherStart + $other['position']['length'];
        $assert($end <= $otherStart || $otherEnd <= $start, sprintf('Plan bindings %s %d-%d and %s %d-%d overlap.', $binding['role'], $start, $end, $other['role'], $otherStart, $otherEnd));
    }
}
echo "form-associated-checkout-single-claim: ok\n";
