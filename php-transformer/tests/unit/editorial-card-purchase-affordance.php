<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

$assert = static function (bool $condition, string $message): void {
    if (! $condition) {
        throw new RuntimeException($message);
    }
};

$card = '<a href="/blog/guide/"><img src="g.webp" alt=""><span>Guides</span><h3>How to perform the rites</h3><p>Understand the main rites in order, from preparation to completion.</p><span>Open the full guide</span></a>';
$schema = '<script type="application/ld+json">{"@context":"https://schema.org","@type":"Product","name":"Guide","offers":{"@type":"Offer","price":"20","priceCurrency":"GBP"}}</script>';
$cases = array(
    'editorial' => array('', false),
    'purchase-button' => array('<button>Add to cart</button>', true),
    'cart-navigation' => array('<a href="/cart/">View cart</a>', true),
    'fragment-buy' => array('<a href="#">Buy now</a>', true),
    'purchase-link' => array('<a href="/product/">Add to cart</a>', true),
    'aria-purchase' => array('<a href="/product/" aria-label="Buy now">Details</a>', true),
    'value-purchase' => array('<a href="/product/" value="Buy now">Details</a>', true),
    'inline-purchase' => array('<a href="/product/"><span>Buy now</span></a>', true),
    'checkout-navigation' => array('<a href="/checkout/">Continue</a>', true),
    'order-action' => array('<a href="/product/">Order now</a>', true),
    'short-prose' => array('<a href="/blog/guide/">Read the rites in order</a>', false),
    'card-heading' => array('<a href="/blog/guide/"><h3>Buy a better future</h3><p>Our editorial guide.</p></a>', false),
    'identity-only' => array('<a class="order" href="/blog/guide/">Read guide</a>', false),
);
foreach ($cases as $case => [$control, $hasPurchase]) {
    $result = (new ArtifactCompiler())->compile(array(
        'entrypoint' => 'index.html',
        'files' => array('index.html' => '<!doctype html><html><head><title>Guides</title>' . $schema . '</head><body><main><h1>Guides</h1>' . $card . $control . '</main></body></html>'),
    ))->toArray();
    $plan = $result['source_reports']['wordpress_site_plan'] ?? array();
    $assert(array() !== $plan, $case . ': site plan exists');
    $declarations = $plan['runtime_declarations'] ?? array();
    $types = array_column($declarations, 'type');
    if ($hasPurchase) {
        $assert(in_array('products', $types, true), $case . ': purchase control still declares products');
    } else {
        $assert(! in_array('products', $types, true), $case . ': editorial prose must not declare products');
        foreach ($declarations as $declaration) {
            $assert('shop' !== ($declaration['capability'] ?? null), $case . ': editorial prose must not declare shop');
        }
    }
}

echo "editorial-card-purchase-affordance: ok\n";
