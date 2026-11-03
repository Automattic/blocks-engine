<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};
$html = file_get_contents(dirname(__DIR__) . '/fixtures/product-page-header-cart-link.html');
$result = (new ArtifactCompiler())->compile(array(
    'entrypoint' => 'products/sample-shirt.html',
    'files' => array('products/sample-shirt.html' => $html),
))->toArray();
$assert('failed' !== $result['status'], 'Product page must compile.');

// The header's cart link sits inside page chrome, so it must not turn the page
// wrapper into a second formless purchase region beside the product card.
$findings = array_values(array_filter(
    $result['source_reports']['conversion_report']['fallback_diagnostics'] ?? array(),
    static fn (array $finding): bool => 'html_commerce_controls_fallback' === ($finding['diagnostic_code'] ?? null)
));
$assert(1 === count($findings), 'Exactly one commerce controls fallback expected, got ' . count($findings) . ': ' . json_encode(array_column($findings, 'selector')));
$assert(str_contains($findings[0]['selector'] ?? '', ' > main:nth-of-type(1) > '), 'Purchase region must be the product card inside main, got: ' . ($findings[0]['selector'] ?? ''));

$plan = $result['source_reports']['wordpress_site_plan'] ?? array();
$rows = array();
foreach ($plan['runtime_declarations'] ?? array() as $declaration) {
    if ('products' === ($declaration['type'] ?? null)) $rows = array_column($declaration['payload']['entities'], null, 'slug');
}
$bindings = array_values(array_filter($rows['sample-shirt']['bindings'] ?? array(), static fn (array $binding): bool => 'commerce_add_to_cart_form' === ($binding['role'] ?? null)));
$assert(1 === count($bindings), 'The single product must carry exactly one purchase binding, got ' . count($bindings) . '.');
echo "product-page-header-cart-link: ok\n";
