<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

$purchase = '<div class="purchase"><h1>Black Tote Bag</h1><p>$30.00</p><button type="button" aria-label="Add to cart">Add to cart</button></div>';
$product = '{"@type":"Product","name":"Black Tote Bag","offers":{"price":"30.00"}}';
foreach (array(
    'single product' => array($product, 1),
    'single product group' => array('{"@type":"ProductGroup","name":"Black Tote Bag","hasVariant":[' . $product . ']}', 1),
    'two products' => array('[' . $product . ',{"@type":"Product","name":"Other","offers":{"price":"10.00"}}]', 0),
    'no products' => array('{}', 0),
) as $case => [$schema, $expected]) {
    $html = '<head><script type="application/ld+json">' . $schema . '</script></head><main>' . $purchase . '</main>';
    $result = (new ArtifactCompiler())->compile(array('entrypoint' => 'products/tote.html', 'files' => array('products/tote.html' => $html)))->toArray();
    if ('failed' === $result['status']) throw new RuntimeException($case . ': compilation failed.');
    if (1 === $expected) {
        $findings = array_filter($result['source_reports']['conversion_report']['fallback_diagnostics'] ?? array(), static fn (array $finding): bool =>
            'html_commerce_controls_fallback' === ($finding['diagnostic_code'] ?? null)
        );
        if (1 !== count($findings)) throw new RuntimeException($case . ': expected one purchase diagnostic.');
    }
    $bindings = array();
    foreach ($result['source_reports']['wordpress_site_plan']['runtime_declarations'] ?? array() as $declaration) {
        if ('products' !== ($declaration['type'] ?? null)) continue;
        foreach ($declaration['payload']['entities'] as $entity) {
            foreach ($entity['bindings'] ?? array() as $binding) {
                if ('commerce_add_to_cart_form' === ($binding['role'] ?? null)) $bindings[] = $binding;
            }
        }
    }
    if ($expected !== count($bindings)) throw new RuntimeException($case . ': expected ' . $expected . ' purchase bindings, got ' . count($bindings));
    foreach ($bindings as $binding) {
        if ('products/tote.html' !== $binding['source_path'] || '' === trim($binding['search_block_markup'])) {
            throw new RuntimeException($case . ': missing purchase anchor.');
        }
    }
}
echo "product-page-formless-single-variant: ok\n";
