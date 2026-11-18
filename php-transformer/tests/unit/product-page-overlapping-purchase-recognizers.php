<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\RuntimeEntityCollector;

$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};
$html = '<html><head><script type="application/ld+json">{"@type":"Product","name":"Ring","offers":{"@type":"Offer","price":1,"priceCurrency":"USD"}}</script></head><body><main><article><h1>Ring</h1><span>$1.00</span><div><button>Add to cart</button><input name="qty"><button>Subscribe</button></div></article></main></body></html>';
$odooCta = '<div id="o_wsale_product_cta_section" class="o_wsale_product_details_content_section">
  <input id="wsale_user_email" type="hidden" value="">
  <div id="o_wsale_cta_wrapper">
    <div id="add_to_cart_wrap">
      <button name="add_to_cart" data-action="add_to_cart" data-product-id="3">Add to cart</button>
      <div class="css_quantity input-group">
        <button name="minus_button" aria-label="Remove one">-</button>
        <input type="text" name="add_qty" value="1">
        <button aria-label="Add one">+</button>
      </div>
    </div>
    <div id="product_option_block"><button class="o_add_wishlist_dyn" data-product-id="3">Add to wishlist</button></div>
  </div>
</div>';
$odooHtml = str_replace('<div><button>Add to cart</button><input name="qty"><button>Subscribe</button></div>', $odooCta, $html);
foreach (array($html, $odooHtml) as $html) {
$result = (new ArtifactCompiler())->compile(array('entrypoint' => 'products/ring.html', 'files' => array('products/ring.html' => $html)))->toArray();
$errors = array_filter($result['source_reports']['wordpress_site_plan_diagnostics'] ?? array(), static fn (array $diagnostic): bool => 'error' === ($diagnostic['severity'] ?? ''));
$assert(array() === $errors, 'No site-plan errors: ' . json_encode($errors));
$assert('failed' !== $result['status'], 'Overlapping purchase recognizers must not fail compilation.');
$plan = $result['source_reports']['wordpress_site_plan'] ?? array();
$bindings = array();
$formBindings = array();
foreach ($plan['runtime_declarations'] ?? array() as $declaration) {
    if ('forms' === ($declaration['type'] ?? null)) {
        foreach ($declaration['payload']['entities'] ?? array() as $entity) {
            foreach ($entity['bindings'] ?? array() as $binding) $formBindings[] = $binding;
        }
    }
    if ('products' !== ($declaration['type'] ?? null)) continue;
    foreach ($declaration['payload']['entities'] ?? array() as $entity) {
        foreach ($entity['bindings'] ?? array() as $binding) {
            if ('commerce_add_to_cart_form' === ($binding['role'] ?? null)) $bindings[] = $binding;
        }
    }
}
$assert(array() === $formBindings, 'A purchase placement is not also claimed by a form provider.');
$assert(1 === count($bindings), 'One purchase placement has exactly one binding across recognizers.');
$binding = $bindings[0];
$assert('products/ring.html' === $binding['source_path'], 'The binding names its source page.');
$assert('' !== trim($binding['search_block_markup']), 'The purchase anchor is nonempty.');
$pages = array_column($plan['pages'] ?? array(), null, 'source_path');
$assert(str_contains($pages['products/ring.html']['canonical_block_markup'] ?? '', $binding['search_block_markup']), 'The purchase anchor occurs in canonical markup.');
}

// Reproduce the overlapping findings from the capture: different source-DOM
// selectors can finalize to the same canonical block placement. Current
// typeless-button recognition need not emit the generic finding itself.
$purchase = current(array_filter($result['fallbacks'], static fn (array $fallback): bool => 'html_commerce_controls_fallback' === ($fallback['diagnostic_code'] ?? '')));
$form = array(
    'diagnostic_code' => 'html_form_fallback',
    'source' => 'products/ring.html',
    'selector' => '#o_wsale_product_cta_section',
    'controls' => array(array('tag' => 'input', 'type' => 'text', 'name' => 'add_qty')),
    'binding' => array_merge($purchase['binding'], array('role' => 'form')),
);
$collector = new RuntimeEntityCollector(array(array('path' => 'products/ring.html', 'content' => $odooHtml)), 'products/ring.html', static fn (): bool => true, array());
$collections = array_column($collector->collections(array($form, $purchase)), null, 'type');
$assert(array() === $collections['forms']['entities'], 'Shop ownership releases a generic form with the same canonical placement, not just the same selector.');
$assert(1 === count($collections['products']['entities'][0]['bindings'] ?? array()), 'The overlapping placement retains one purchase binding.');

echo "product-page-overlapping-purchase-recognizers: ok\n";
