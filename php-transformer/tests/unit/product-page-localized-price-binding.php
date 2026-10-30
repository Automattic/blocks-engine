<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

$product = '{"@type":"Product","name":"Sac noir","offers":{"price":"30.00"}}';
foreach (array(
    'localized product' => array($product, "30,00\u{00a0}$", 1),
    'localized product group' => array('{"@type":"ProductGroup","name":"Sac noir","hasVariant":[' . $product . ']}', "30,00\u{00a0}$", 1),
    'English product' => array($product, '$30.00', 1),
    'bare number' => array($product, '30,00', 0),
    'no schema product' => array('{}', "30,00\u{00a0}$", 0),
    'multiple products' => array('[' . $product . ',{"@type":"Product","name":"Other","offers":{"price":"10.00"}}]', "30,00\u{00a0}$", 0),
) as $case => [$schema, $price, $expected]) {
    // The control's semantic action, not a translated-label table, identifies it.
    $html = '<head><meta charset="utf-8"><script type="application/ld+json">' . $schema . '</script></head><main><div><h1>Sac noir</h1><p>' . $price . '</p><button type="button" data-action="add-to-cart">Ajouter au panier</button></div></main>';
    $result = (new ArtifactCompiler())->compile(array('entrypoint' => 'products/sac.html', 'files' => array('products/sac.html' => $html)))->toArray();
    if ('failed' === $result['status']) throw new RuntimeException($case . ': compilation failed.');
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
        if ('products/sac.html' !== $binding['source_path'] || '' === trim($binding['search_block_markup'])) {
            throw new RuntimeException($case . ': missing purchase anchor.');
        }
    }
}
echo "product-page-localized-price-binding: ok\n";
