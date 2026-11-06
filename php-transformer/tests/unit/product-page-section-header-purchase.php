<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

$purchase = '<div class="purchase"><div class="quantity"><button type="button">−</button><input type="number" name="quantity" min="1" value="1"><button type="button">+</button></div><div class="options"><label><input type="radio" name="plan" value="once" checked>One-time</label><label><input type="radio" name="plan" value="subscription">Subscription</label></div><button type="button">Add to Cart</button></div>';
$html = '<html><head><script type="application/ld+json">{"@type":"Product","name":"Canvas Bag","offers":{"price":"24.00","priceCurrency":"USD"}}</script></head><body>'
    . '<header><a href="/cart">Cart</a></header><main><h1>Canvas Bag</h1><p>$24.00</p>'
    . '<section class="desktop"><h2>Purchase</h2>' . $purchase . '</section>'
    . '<article class="mobile"><header><h2>Purchase</h2>' . $purchase . '</header></article></main>'
    . '<footer>' . $purchase . '</footer></body></html>';
$path = 'products/canvas-bag.html';
$result = (new ArtifactCompiler())->compile(array('entrypoint' => $path, 'files' => array($path => $html)))->toArray();
if ('failed' === $result['status']) throw new RuntimeException('Product page must compile.');
$findings = array_values(array_filter(
    $result['source_reports']['conversion_report']['fallback_diagnostics'] ?? array(),
    static fn (array $finding): bool => 'html_commerce_controls_fallback' === ($finding['diagnostic_code'] ?? null)
));
if (2 !== count($findings)) throw new RuntimeException('Expected both device purchase diagnostics, not page chrome: ' . json_encode(array_column($findings, 'selector')));
$bindings = array();
foreach ($result['source_reports']['wordpress_site_plan']['runtime_declarations'] ?? array() as $declaration) {
    if ('products' !== ($declaration['type'] ?? null)) continue;
    if (1 !== count($declaration['payload']['entities'])) throw new RuntimeException('Expected one schema product.');
    foreach ($declaration['payload']['entities'][0]['bindings'] ?? array() as $binding) {
        if ('commerce_add_to_cart_form' === ($binding['role'] ?? null)) $bindings[] = $binding;
    }
}
if (2 !== count($bindings)) throw new RuntimeException('Expected two device purchase bindings, got ' . count($bindings));
foreach ($bindings as $binding) {
    if ($path !== ($binding['source_path'] ?? null) || !str_contains($binding['search_block_markup'] ?? '', 'Add to Cart')) {
        throw new RuntimeException('Each binding must anchor its page-owned purchase box.');
    }
}
if ($bindings[0]['projected_anchor'] === $bindings[1]['projected_anchor']) throw new RuntimeException('Device placements must have distinct anchors.');
foreach (array('section:nth-of-type(1)', 'article:nth-of-type(1) > header:nth-of-type(1)') as $placement) {
    if (1 !== count(array_filter($findings, static fn (array $finding): bool => str_contains($finding['selector'] ?? '', $placement)))) {
        throw new RuntimeException('Expected a purchase diagnostic at ' . $placement);
    }
}
echo "product-page-section-header-purchase: ok\n";
