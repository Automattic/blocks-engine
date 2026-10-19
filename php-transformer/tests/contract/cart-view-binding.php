<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

/**
 * Contract coverage for a client-side cart page: a script-owned region with an
 * order summary (amount rows, a total and a checkout control) and an empty
 * line-items mount the script fills from storage.
 *
 * The producer must recognize the cart view, never read its order summary as
 * a product, and declare a `generic/carts/v1` entity whose binding anchors on
 * the region the script looks up — so a shop provider replacing that anchor
 * also retires the source cart rendering. A cart is declared only beside a
 * products collection. Runs the full `ArtifactCompiler` -> `WordPressSitePlan`
 * pipeline because bindings are projected at site-plan composition.
 */

$assert = static function (bool $condition, string $message): void {
    if (! $condition) {
        throw new RuntimeException($message);
    }
};

$card = static fn (string $name, string $price): string => '<article class="card"><h3>' . $name . '</h3><span class="price">$' . $price . '</span><button type="button" class="btn" data-add-to-cart>Add to cart</button></article>';
$shop = '<!doctype html><html><head><title>Shop</title></head><body><main><h1>Shop</h1><div class="grid">'
    . $card('Alpha Item', '10.00') . $card('Beta Item', '12.00') . $card('Gamma Item', '14.00')
    . '</div></main><script src="app.js"></script></body></html>';
$cart = '<!doctype html><html><head><title>Cart</title></head><body><main>'
    . '<section class="hero"><h1>Your cart</h1><p>Review your order.</p></section>'
    . '<section class="section" data-cart-root><div class="wrap">'
    . '<div class="cart-empty" data-cart-empty hidden><h2>Your cart is empty</h2><a href="shop.html">Start shopping</a></div>'
    . '<div class="cart-grid"><div class="cart-lines" data-cart-lines></div>'
    . '<aside class="cart-summary" data-cart-summary hidden><h3>Order summary</h3>'
    . '<div class="row"><span>Subtotal</span><span data-subtotal>$0.00</span></div>'
    . '<div class="row"><span>Shipping</span><span>Free</span></div>'
    . '<div class="row total"><span>Total</span><span data-total>$0.00</span></div>'
    . '<button class="btn" data-checkout>Proceed to checkout</button></aside></div>'
    . '</div></section></main><script src="app.js"></script></body></html>';
$script = "var root = document.querySelector('[data-cart-root]'); if (root) { root.querySelector('[data-cart-lines]').textContent = ''; root.querySelector('[data-cart-summary]').hidden = false; }";

$compile = static fn (array $files): array => (new ArtifactCompiler())->compile(array('entrypoint' => 'shop.html', 'files' => $files))->toArray();
$findings = static function (array $result, string $code): array {
    $found = array();
    $walk = static function (mixed $node) use (&$walk, &$found, $code): void {
        if (! is_array($node)) {
            return;
        }
        if ($code === ($node['diagnostic_code'] ?? null) && 'cart.html' === ($node['source_path'] ?? $node['source'] ?? null)) {
            $found[($node['selector'] ?? '') . "\n" . ($node['id'] ?? '')] = $node;
        }
        foreach ($node as $child) {
            $walk($child);
        }
    };
    $walk($result['source_reports']['html_diagnostics'] ?? $result);
    return array_values($found);
};
$declaration = static fn (array $plan, string $type): ?array => current(array_filter($plan['runtime_declarations'] ?? array(), static fn (array $declaration): bool => $type === ($declaration['type'] ?? null))) ?: null;

// Case A: a store with products and a cart page.
$result = $compile(array('shop.html' => $shop, 'cart.html' => $cart, 'app.js' => $script));
$assert('failed' !== $result['status'] && ! isset($result['source_reports']['wordpress_site_plan_diagnostics']), 'The store composes cleanly: ' . json_encode($result['source_reports']['wordpress_site_plan_diagnostics'] ?? array()));
$plan = $result['source_reports']['wordpress_site_plan'] ?? array();

$assert(array() === $findings($result, 'html_commerce_controls_fallback'), 'The order summary is not read as a product card with commerce controls.');
$cartView = $findings($result, 'html_cart_view_fallback');
$assert(1 === count(array_unique(array_map(static fn (array $finding): string => (string) ($finding['selector'] ?? ''), $cartView))), 'The cart page carries exactly one cart view finding: ' . json_encode(array_column($cartView, 'selector')));
$assert('main:nth-of-type(1) > section:nth-of-type(2)' === ($cartView[0]['selector'] ?? null), 'The cart view scope is the script-owned region, not the summary: ' . json_encode($cartView[0]['selector'] ?? null));

$carts = $declaration($plan, 'carts');
$assert(null !== $carts, 'A generic/carts/v1 entity collection is declared beside the products.');
$cartEntities = $carts['payload']['entities'] ?? array();
$assert(1 === count($cartEntities) && 'cart.html' === ($cartEntities[0]['source_path'] ?? null), 'Exactly one cart entity is declared for the cart page.');
$binding = $cartEntities[0]['bindings'][0] ?? array();
$assert('generic/block-binding/v1' === ($binding['schema'] ?? null) && 'commerce_cart' === ($binding['role'] ?? null) && 1 === ($binding['occurrence'] ?? null), 'The cart entity carries a commerce_cart block binding.');
$assert(array('[data-cart-root]') === ($binding['superseded_runtime_selectors'] ?? null), 'The binding supersedes the script lookup of the cart region: ' . json_encode($binding['superseded_runtime_selectors'] ?? null));

$cartPage = current(array_filter($plan['pages'] ?? array(), static fn (array $page): bool => 'cart.html' === ($page['source_path'] ?? null)));
$canonical = (string) ($cartPage['canonical_block_markup'] ?? '');
$search = (string) ($binding['search_block_markup'] ?? '');
$assert('' !== $search && 1 === substr_count($canonical, $search), 'The binding anchor appears exactly once in the cart page markup.');
$assert(str_contains($search, 'data-cart-root') && str_contains($search, 'data-cart-lines') && str_contains($search, 'Order summary') && str_contains($search, 'Your cart is empty'), 'The anchor covers the whole script-owned region: empty state, line items and summary.');
$assert(! str_contains($search, 'Review your order.'), 'The page hero outside the cart region stays outside the anchor.');

$shopDependency = current(array_filter($plan['runtime_declarations'] ?? array(), static fn (array $declaration): bool => 'dependency' === ($declaration['kind'] ?? null) && 'shop' === ($declaration['capability'] ?? null)));
$assert(false !== $shopDependency, 'The shop dependency is declared.');

// Case B: the same cart page with no products anywhere declares no cart.
$cartOnly = $compile(array('shop.html' => '<!doctype html><html><body><main><h1>Shop</h1><p>Coming soon.</p></main></body></html>', 'cart.html' => $cart, 'app.js' => $script));
$cartOnlyPlan = $cartOnly['source_reports']['wordpress_site_plan'] ?? array();
$assert(1 <= count($findings($cartOnly, 'html_cart_view_fallback')), 'The cart view is still reported without products.');
$assert(null === $declaration($cartOnlyPlan, 'carts') && null === $declaration($cartOnlyPlan, 'products'), 'Without products no cart is declared, so no store is requested for nothing to sell.');

echo "cart-view-binding: ok\n";
