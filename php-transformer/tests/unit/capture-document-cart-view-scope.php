<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

$assert = static function (bool $condition, string $message): void {
    if (! $condition) throw new RuntimeException($message);
};
$cards = '<div class="grid">';
foreach (array('Alpha', 'Beta', 'Gamma') as $name) {
    $cards .= '<article><h3>' . $name . '</h3><span>$10.00</span><button data-add-to-cart>Add to cart</button></article>';
}
$cards .= '</div>';
$html = '<!doctype html><html><head><title>Shop</title></head><body>'
    . '<div class="capture-document" data-blocks-engine-capture-document-scope>'
    . '<h1>Our shop</h1><p>Explore our collection.</p>' . $cards
    . '<div id="mini-cart" style="display:none;position:absolute"><ul><li>Empty Cart</li></ul>'
    . '<div><span>Subtotal</span><span>$0.00</span></div><a href="/checkout">Checkout</a></div></div>'
    . '<script>document.querySelector(".capture-document").querySelector("#mini-cart");</script>'
    . '</body></html>';
$result = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array('index.html' => $html)))->toArray();
$assert('failed' !== $result['status'], 'The capture compiles successfully.');
$findings = array_values(array_filter($result['fallbacks'] ?? array(), static fn (array $finding): bool => 'html_cart_view_fallback' === ($finding['diagnostic_code'] ?? null)));
$assert(array() === $findings, 'The hidden mini-cart cannot borrow the document runtime scope: ' . json_encode(array_column($findings, 'selector')));
$plan = $result['source_reports']['wordpress_site_plan'] ?? array();
$products = array_filter($plan['runtime_declarations'] ?? array(), static fn (array $declaration): bool => 'products' === ($declaration['type'] ?? null));
$assert(array() !== $products, 'Products are declared, so an erroneous cart declaration would be observable.');
$carts = array_filter($plan['runtime_declarations'] ?? array(), static fn (array $declaration): bool => 'carts' === ($declaration['type'] ?? null));
$assert(array() === $carts, 'No carts declaration can replace the capture document wrapper.');
$page = current($plan['pages'] ?? array());
$markup = (string) ($page['canonical_block_markup'] ?? '');
$assert(str_contains($markup, '<!-- wp:heading') && str_contains($markup, 'Our shop'), 'The page heading stays native.');
$assert(str_contains($markup, '<!-- wp:paragraph') && str_contains($markup, 'Explore our collection.'), 'The page paragraph stays native.');
$assert(! str_contains($markup, 'wp:woocommerce/cart'), 'The page is not replaced by a WooCommerce cart.');
// A locally proven visible cart remains bindable below the capture boundary,
// even when the document wrapper is the only runtime DOM target.
$visible = str_replace(
    array('style="display:none;position:absolute"', '<a href="/checkout">Checkout</a>'),
    array('', '<div><span>Total</span><span>$0.00</span></div><a href="/checkout">Checkout</a>'),
    $html
);
$visibleResult = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array('index.html' => $visible)))->toArray();
$visibleFindings = array_values(array_filter($visibleResult['fallbacks'] ?? array(), static fn (array $finding): bool => 'html_cart_view_fallback' === ($finding['diagnostic_code'] ?? null)));
$assert(1 === count($visibleFindings), 'The visible cart is still recognized.');
$assert('div:nth-of-type(1) > div:nth-of-type(2)' === ($visibleFindings[0]['selector'] ?? null), 'The cart scope stops below the runtime-targeted capture wrapper.');
$assert('commerce_cart' === ($visibleFindings[0]['binding']['role'] ?? null), 'The visible cart remains bindable.');
echo "capture-document-cart-view-scope: ok\n";
