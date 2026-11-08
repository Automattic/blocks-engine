<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};
// A cart link in page chrome (semantic <header>, or a div header that only
// navigates to the cart) and hidden data inputs are not purchase controls, so
// neither may turn an ancestor wrapper into a second formless purchase region.
foreach (array(
    'product-page-header-cart-link' => 'Add to Cart',
    'product-page-div-chrome-cart-link' => 'quantity',
) as $fixture => $purchaseControl) {
    $html = file_get_contents(dirname(__DIR__) . '/fixtures/' . $fixture . '.html');
    $result = (new ArtifactCompiler())->compile(array(
        'entrypoint' => 'products/sample-shirt.html',
        'files' => array('products/sample-shirt.html' => $html),
    ))->toArray();
    $assert('failed' !== $result['status'], $fixture . ': product page must compile.');

    $findings = array_values(array_filter(
        $result['source_reports']['conversion_report']['fallback_diagnostics'] ?? array(),
        static fn (array $finding): bool => 'html_commerce_controls_fallback' === ($finding['diagnostic_code'] ?? null)
    ));
    $assert(1 === count($findings), $fixture . ': exactly one commerce controls fallback expected, got ' . count($findings) . ': ' . json_encode(array_column($findings, 'selector')));
    $assert(str_contains(json_encode($findings[0]['controls'] ?? array()), $purchaseControl), $fixture . ': purchase region must own the product control, got: ' . ($findings[0]['selector'] ?? ''));

    $plan = $result['source_reports']['wordpress_site_plan'] ?? array();
    $rows = array();
    foreach ($plan['runtime_declarations'] ?? array() as $declaration) {
        if ('products' === ($declaration['type'] ?? null)) $rows = array_column($declaration['payload']['entities'], null, 'slug');
    }
    $bindings = array_values(array_filter($rows['sample-shirt']['bindings'] ?? array(), static fn (array $binding): bool => 'commerce_add_to_cart_form' === ($binding['role'] ?? null)));
    $assert(1 === count($bindings), $fixture . ': the single product must carry exactly one purchase binding, got ' . count($bindings) . '.');
}
echo "product-page-header-cart-link: ok\n";
