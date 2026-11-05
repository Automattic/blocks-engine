<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};
foreach (array(
    'Stripe issue shape' => array('https://js.stripe.com/v3/elements-inner-payment-method-messaging-123.html#stripeAccount=source-merchant', true),
    'PayPal buttons' => array('https://www.paypal.com/sdk/js?client-id=source-merchant', true),
    'PayPal messaging' => array('https://www.paypalobjects.com/upstream/assets/messaging/frame.html', true),
    'Klarna messaging' => array('https://x.klarnacdn.net/osk/frame.html', true),
    'Klarna OSM' => array('https://x.klarnacdn.net/osm/frame.html', true),
    'Afterpay messaging' => array('https://static.afterpay.com/messaging/iframe.html', true),
    'YouTube' => array('https://www.youtube.com/embed/dQw4w9WgXcQ', false),
    'Vimeo' => array('https://player.vimeo.com/video/123456', false),
    'map' => array('https://maps.google.com/maps?q=London&output=embed', false),
    'lookalike host' => array('https://js.stripe.com.example.test/v3/elements-inner-payment-method-messaging.html', false),
    'ordinary processor page' => array('https://www.paypal.com/business', false),
) as $case => [$src, $omitted]) {
    $html = '<html><head><script type="application/ld+json">{"@type":"Product","name":"Field Tee","offers":{"price":"27.99","priceCurrency":"USD"}}</script></head><body><main><h1>Field Tee</h1>'
        . '<div class="product-price"><p class="product-price-value">$27.99</p>'
        . '<div class="product-payment-method-messaging StripeElement" style="width:634px;height:150px;border:1px solid black">'
        . '<div class="__PrivateStripeElement" style="margin:-4px 0"><iframe title="Payment plans" width="634" height="150" src="' . htmlspecialchars($src, ENT_QUOTES) . '"></iframe></div></div></div>'
        . '<form method="post"><input type="number" name="quantity" value="1"><button>Add to cart</button></form></main></body></html>';
    $result = (new ArtifactCompiler())->compile(array('entrypoint' => 'products/field-tee.html', 'files' => array('products/field-tee.html' => $html)))->toArray();
    $assert('failed' !== $result['status'], $case . ': compiles');
    $plan = $result['source_reports']['wordpress_site_plan'];
    $markup = $plan['pages'][0]['canonical_block_markup'];
    $assert(1 === preg_match('/<!-- wp:paragraph\b[^>]*-->.*?\$27\.99/s', $markup), $case . ': price paragraph remains');
    $bindings = array();
    foreach ($plan['runtime_declarations'] as $declaration) {
        if ('products' !== ($declaration['type'] ?? null)) continue;
        foreach ($declaration['payload']['entities'] as $entity) $bindings = array_merge($bindings, $entity['bindings'] ?? array());
    }
    $assert(in_array('commerce_add_to_cart_form', array_column($bindings, 'role'), true), $case . ': purchase controls rebound');
    $diagnostics = json_encode($result['fallbacks'] ?? array());
    if ($omitted) {
        foreach (array('<iframe', 'product-payment-method-messaging', 'StripeElement', '__PrivateStripeElement', '634', '150px', 'source-merchant') as $removed) {
            $assert(!str_contains($markup, $removed), $case . ': absent ' . $removed);
        }
        $assert(str_contains($diagnostics, 'html_payment_processor_widget_omitted'), $case . ': diagnostic exists');
    } else {
        $assert(str_contains($markup, '<iframe') || str_contains($markup, '<!-- wp:embed'), $case . ': content embed retained');
        $assert(!str_contains($diagnostics, 'html_payment_processor_widget_omitted'), $case . ': no payment diagnostic');
    }
}
echo "payment-processor-widget-omission: ok\n";
