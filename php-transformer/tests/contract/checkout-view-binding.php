<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

/**
 * Contract coverage for a client-side checkout page: a script-owned region
 * holding customer contact fields, a billing address, a payment mount and an
 * order summary (labelled subtotal/total rows the script fills in).
 *
 * The producer must recognize the checkout view from the role of the region —
 * never a vendor tag name — read each field's role from the element's
 * `label`/`placeholder`/`name` (a captured checkout carries no `<input>` at
 * all), accept summary rows whose amounts are still empty, and declare a
 * `generic/checkouts/v1` entity beside the products collection whose binding
 * anchors on the region the script looks up. A provider replacing that anchor
 * retires the source checkout rendering and the unsupported fallbacks it
 * covered, and the same region is never also declared as a cart. Runs the
 * full `ArtifactCompiler` -> `WordPressSitePlan` pipeline because bindings are
 * projected at site-plan composition.
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
$script = "var root = document.querySelector('.checkout'); if (root) { root.textContent = 'loaded'; }";

// The captured shape: every field is a textless script-defined custom element
// carrying its role in a `label` attribute, payment is a bare mount, and the
// summary amounts are empty until the script runs.
$customElementCheckout = '<main><h1>Checkout</h1>'
    . '<x-checkout class="checkout"><div class="checkout-container"><x-form><div class="columns">'
    . '<div class="column">'
    . '<x-customer-email label="Email" placeholder="your@email.com" required></x-customer-email>'
    . '<x-customer-name label="Name" placeholder="Your full name" required></x-customer-name>'
    . '<x-order-address label="Billing Address" required></x-order-address>'
    . '<x-payment></x-payment>'
    . '<x-order-submit type="primary"><x-button type="primary">Purchase</x-button></x-order-submit>'
    . '</div>'
    . '<div class="column">'
    . '<x-order-summary><x-line-items></x-line-items>'
    . '<x-line-item-total><div class="item"><span class="desc">Subtotal</span><span class="price"><x-total></x-total></span></div></x-line-item-total>'
    . '<x-line-item-total><div class="item"><span class="title">Total</span><span class="price"><x-total></x-total></span></div></x-line-item-total>'
    . '</x-order-summary>'
    . '</div>'
    . '</div></x-form></div></x-checkout></main>';
$customElementScope = 'main:nth-of-type(1) > x-checkout:nth-of-type(1)';

// The same roles on native controls: a form with typed inputs, a payment
// region and a labelled summary.
$nativeInputCheckout = '<main><h1>Checkout</h1>'
    . '<form class="checkout" method="post"><div class="columns">'
    . '<div class="column">'
    . '<input type="email" name="email" placeholder="your@email.com" required>'
    . '<input type="text" name="name" placeholder="Your full name" required>'
    . '<input type="text" name="billing_address" placeholder="Billing Address" required>'
    . '<div class="payment-fields" id="payment"></div>'
    . '<button type="submit">Purchase</button>'
    . '</div>'
    . '<div class="column">'
    . '<div class="order-summary">'
    . '<div class="row"><span class="desc">Subtotal</span><span class="amount">$0.00</span></div>'
    . '<div class="row"><span class="desc">Total</span><span class="amount">$0.00</span></div>'
    . '</div>'
    . '</div>'
    . '</div></form></main>';
$nativeInputScope = 'main:nth-of-type(1) > form:nth-of-type(1)';

// A captured page carries no source scripts: the custom-element checkout is
// recognized from its element names alone, a native form only through a script
// that looks it up.
$checkoutPage = static fn (string $body, bool $script = true): string => '<!doctype html><html><head><title>Checkout</title></head><body>' . $body . ($script ? '<script src="app.js"></script>' : '') . '</body></html>';

$compile = static fn (array $files): array => (new ArtifactCompiler())->compile(array('entrypoint' => 'shop.html', 'files' => $files))->toArray();
$findings = static function (array $result, string $code): array {
    $found = array();
    $walk = static function (mixed $node) use (&$walk, &$found, $code): void {
        if (! is_array($node)) {
            return;
        }
        if ($code === ($node['diagnostic_code'] ?? null) && 'checkout.html' === ($node['source_path'] ?? $node['source'] ?? null)) {
            $found[($node['selector'] ?? '') . "\n" . ($node['id'] ?? '')] = $node;
        }
        foreach ($node as $child) {
            $walk($child);
        }
    };
    $walk($result['source_reports']['html_diagnostics'] ?? $result);
    return array_values($found);
};
$declaration = static fn (array $plan, string $type): ?array => current(array_filter($plan['runtime_declarations'] ?? array(), static fn (array $row): bool => $type === ($row['type'] ?? null))) ?: null;

foreach (array(
    'custom elements' => array('body' => $customElementCheckout, 'scope' => $customElementScope, 'script' => false),
    'native inputs' => array('body' => $nativeInputCheckout, 'scope' => $nativeInputScope, 'script' => true),
) as $shape => $row) {
    $label = '[' . $shape . '] ';

    // Case A: a store with products and a checkout page.
    $result = $compile(array('shop.html' => $shop, 'checkout.html' => $checkoutPage($row['body'], $row['script']), 'app.js' => $script));
    $assert('failed' !== $result['status'] && ! isset($result['source_reports']['wordpress_site_plan_diagnostics']), $label . 'The store composes cleanly: ' . json_encode($result['source_reports']['wordpress_site_plan_diagnostics'] ?? array()));
    $plan = $result['source_reports']['wordpress_site_plan'] ?? array();

    $assert(array() === $findings($result, 'html_product_grid_fallback') && array() === $findings($result, 'html_commerce_controls_fallback'), $label . 'The order summary is not read as a product or commerce controls.');
    $assert(array() === $findings($result, 'html_cart_view_fallback'), $label . 'The checkout region is not also declared as a cart.');

    $checkoutViews = $findings($result, 'html_checkout_view_fallback');
    $assert(1 === count($checkoutViews), $label . 'The checkout page carries exactly one checkout view finding: ' . json_encode(array_column($checkoutViews, 'selector')));
    $assert($row['scope'] === ($checkoutViews[0]['selector'] ?? null), $label . 'The checkout view scope is the script-owned region: ' . json_encode($checkoutViews[0]['selector'] ?? null));

    $checkouts = $declaration($plan, 'checkouts');
    $assert(null !== $checkouts, $label . 'A generic/checkouts/v1 entity collection is declared beside the products.');
    $assert('generic/checkouts/v1' === ($checkouts['payload']['schema'] ?? null), $label . 'The checkout collection carries the generic/checkouts/v1 schema.');
    $checkoutEntities = $checkouts['payload']['entities'] ?? array();
    $assert(1 === count($checkoutEntities) && 'checkout.html' === ($checkoutEntities[0]['source_path'] ?? null), $label . 'Exactly one checkout entity is declared for the checkout page.');
    $binding = $checkoutEntities[0]['bindings'][0] ?? array();
    $assert('generic/block-binding/v1' === ($binding['schema'] ?? null) && 'commerce_checkout' === ($binding['role'] ?? null) && 1 === ($binding['occurrence'] ?? null), $label . 'The checkout entity carries a commerce_checkout block binding.');
    $assert(($row['script'] ? array('.checkout') : null) === ($binding['superseded_runtime_selectors'] ?? null), $label . 'The binding supersedes the script lookup of the checkout region: ' . json_encode($binding['superseded_runtime_selectors'] ?? null));

    $checkoutPageRow = current(array_filter($plan['pages'] ?? array(), static fn (array $page): bool => 'checkout.html' === ($page['source_path'] ?? null)));
    $canonical = (string) ($checkoutPageRow['canonical_block_markup'] ?? '');
    $search = (string) ($binding['search_block_markup'] ?? '');
    $assert('' !== $search && 1 === substr_count($canonical, $search), $label . 'The binding anchor appears exactly once in the checkout page markup.');
    $assert(str_contains($search, 'Billing Address') && str_contains($search, 'Subtotal') && str_contains($search, 'Total') && str_contains($search, 'Purchase'), $label . 'The anchor covers the whole script-owned region: customer fields, address, payment and summary.');

    // The fallbacks the bound region covered are superseded: no unsupported
    // finding remains inside the checkout scope.
    $unsupported = array_filter(
        array_merge($findings($result, 'html_unsupported_element'), $findings($result, 'html_custom_element_runtime_unproven')),
        static fn (array $finding): bool => '' !== (string) ($finding['selector'] ?? '') && (str_starts_with((string) $finding['selector'], $row['scope'] . ' > ') || $row['scope'] === (string) $finding['selector'])
    );
    $assert(array() === $unsupported, $label . 'The unsupported findings inside the bound region are superseded: ' . json_encode(array_column($unsupported, 'selector')));

    $shopDependency = current(array_filter($plan['runtime_declarations'] ?? array(), static fn (array $row): bool => 'dependency' === ($row['kind'] ?? null) && 'shop' === ($row['capability'] ?? null)));
    $assert(false !== $shopDependency, $label . 'The shop dependency is declared.');
}

// Case B: the same checkout page with no products anywhere declares no checkout.
$checkoutOnly = $compile(array(
    'shop.html' => '<!doctype html><html><body><main><h1>Shop</h1><p>Coming soon.</p></main></body></html>',
    'checkout.html' => $checkoutPage($customElementCheckout),
    'app.js' => $script,
));
$checkoutOnlyPlan = $checkoutOnly['source_reports']['wordpress_site_plan'] ?? array();
$assert(1 <= count($findings($checkoutOnly, 'html_checkout_view_fallback')), 'The checkout view is still reported without products.');
$assert(null === $declaration($checkoutOnlyPlan, 'checkouts') && null === $declaration($checkoutOnlyPlan, 'carts'), 'Without products no checkout is declared, so no store is requested for nothing to sell.');

echo "checkout-view-binding: ok\n";
