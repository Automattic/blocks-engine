<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\SchemaProductEvidence;

$assert = static function (bool $ok, string $message): void {
    if (!$ok) throw new RuntimeException($message);
};
$child = array('@type' => 'Product', 'name' => 'Canvas Bag', 'sku' => 'BAG%231',
    'offers' => array('price' => '24.00', 'availability' => 'https://schema.org/OutOfStock',
        'priceSpecification' => array('priceType' => 'https://schema.org/ListPrice', 'price' => '30.00')));
$group = array('@type' => 'ProductGroup', 'name' => 'Canvas Bag', 'sku' => 'GROUP',
    'url' => 'https://store.example/products/canvas-bag', 'hasVariant' => array($child));
$default = array('@type' => 'PropertyValue', 'name' => 'Title', 'value' => 'Default Title');
$cases = array('no attributes' => array($group, false));
$group['hasVariant'][0]['additionalProperty'] = array($default);
$cases['default title'] = array($group, false);
$declaredDefault = $group;
$declaredDefault['variesBy'] = 'title';
$cases['explicitly declared title axis'] = array($declaredDefault, true);
$group['hasVariant'][] = array_replace($child, array('sku' => 'BAG2', 'additionalProperty' => array(array_replace($default, array('value' => 'Other')))));
$cases['two variants'] = array($group, true);
$group['hasVariant'] = array(array_replace($child, array('size' => 'One Size')));
$group['variesBy'] = 'https://schema.org/size';
$cases['one real declared attribute'] = array($group, true);
$group['hasVariant'][0]['additionalProperty'] = array($default);
$cases['default title alongside real attribute'] = array($group, true);
unset($group['variesBy']);
$group['hasVariant'][0]['additionalProperty'] = array(array_replace($default, array('name' => 'Size', 'value' => 'One Size')));
$cases['one real additional property'] = array($group, true);

foreach ($cases as $case => [$schema, $variable]) {
    $path = 'products/canvas-bag.html';
    $html = '<html><head><script type="application/ld+json">' . json_encode($schema) . '</script></head><body><main><div class="purchase"><h1>Canvas Bag</h1><p>$24.00</p><button type="button" aria-label="Add to cart">Add to cart</button></div></main></body></html>';
    $diagnostics = array();
    $rows = SchemaProductEvidence::rows(array(array('path' => $path, 'content' => $html)),
        static fn (string $value): string => strtolower(str_replace(' ', '-', $value)),
        static fn (mixed $value): string => is_scalar($value) ? (string) $value : '', $diagnostics);
    $result = (new ArtifactCompiler())->compile(array('entrypoint' => $path, 'files' => array($path => $html)))->toArray();
    $entities = array();
    foreach ($result['source_reports']['wordpress_site_plan']['runtime_declarations'] ?? array() as $declaration) {
        if ('products' === ($declaration['type'] ?? null)) $entities = $declaration['payload']['entities'];
    }
    $assert(1 === count($entities) && 1 === count($rows), "$case: only the group becomes a product.");
    foreach (array($rows['canvas-bag'], $entities[0]) as $row) {
        $assert($variable === isset($row['variants']), "$case: expected product kind.");
        if (!$variable) {
            $assert(!isset($row['attributes']), "$case: no product attributes.");
            $assert('BAG#1' === ($row['sku'] ?? null), "$case: variant SKU replaces group SKU.");
            $assert('30.00' === $row['regular_price'] && '24.00' === ($row['sale_price'] ?? null), "$case: variant regular and sale prices survive.");
            $assert('outofstock' === ($row['stock_status'] ?? null), "$case: variant stock survives.");
        }
    }
    $assert(array() === $diagnostics, "$case: no undeclared-variant warning.");
    $bindings = array_values(array_filter($entities[0]['bindings'] ?? array(), static fn (array $binding): bool => 'commerce_add_to_cart_form' === ($binding['role'] ?? null)));
    $assert(1 === count($bindings) && $path === $bindings[0]['source_path'] && '' !== trim($bindings[0]['search_block_markup']), "$case: purchase binding survives.");
}
echo "schema-product-group-single-variant: ok\n";
