<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\SchemaProductEvidence;

$failures = 0;
$assert = static function (bool $ok, string $message) use (&$failures): void {
    if (!$ok) { ++$failures; fwrite(STDERR, "FAIL: $message\n"); }
};
$variant = static function (string $size, string $color, string $price, string $stock): array {
    return array('@type' => 'Product', '@id' => '/products/field-shirt?variant=' . $size . $color . '#variant',
        'name' => 'Field Shirt - ' . $size . ' / ' . $color, 'sku' => 'SH%23' . $size . $color,
        'image' => 'https://assets.example/' . strtolower($color) . '.jpg',
        'offers' => array('@type' => 'Offer', 'price' => $price, 'priceCurrency' => 'EUR', 'availability' => $stock, 'url' => 'https://store.example/products/field-shirt?variant=' . $size),
        'additionalProperty' => array(array('@type' => 'PropertyValue', 'name' => 'Size', 'value' => $size), array('@type' => 'PropertyValue', 'name' => 'Color', 'value' => $color)));
};
$group = array('@type' => 'ProductGroup', 'name' => 'Field Shirt', 'url' => 'https://store.example/products/field-shirt',
    'sku' => 'SH%23GROUP', 'productGroupID' => 'internal-42', 'description' => 'A <b>woven</b> shirt.', 'category' => 'Clothing > Shirts',
    'image' => array('https://assets.example/front.jpg', array('@type' => 'ImageObject', 'contentUrl' => 'https://assets.example/back.jpg', 'caption' => 'Back view'), 'https://assets.example/detail.jpg'),
    'keywords' => 'woven, everyday, woven',
    'hasVariant' => array($variant('Small', 'Stone', '20.00', 'http://schema.org/InStock'), $variant('Large', 'Stone', '24.00', 'https://schema.org/OutOfStock'), $variant('Small', 'Ink', '22.00', 'BackOrder')));
$group['hasVariant'][0]['offers']['priceSpecification'] = array('@type' => 'UnitPriceSpecification', 'priceType' => 'https://schema.org/StrikethroughPrice', 'price' => '30.00');
$html = static fn (array $schema): string => '<!doctype html><html><head><title>Catalog</title><script type="application/ld+json">' . json_encode($schema) . '</script></head><body><main><h1>Catalog</h1></main></body></html>';
$slug = static fn (string $value): string => strtolower(str_replace(' ', '-', $value));
$price = static fn (mixed $value): string => is_scalar($value) ? (string) $value : '';
$read = static function (array $schema, array &$diagnostics = array()) use ($html, $slug, $price): array {
    return SchemaProductEvidence::rows(array(array('path' => 'products/field-shirt.html', 'content' => $html($schema))), $slug, $price, $diagnostics);
};
$diagnostics = array();
$rows = $read($group, $diagnostics);
$row = $rows['field-shirt'] ?? array();
$assert(1 === count($rows), 'Only the group is a product row.');
$assert('SH#GROUP' === ($row['sku'] ?? null), 'Parent SKU is decoded.');
$assert('22.00' === ($row['regular_price'] ?? null), 'Parent uses the lowest variant regular price.');
$assert('A woven shirt.' === ($row['description'] ?? null) && 'Shirts' === ($row['category'] ?? null), 'Group content is retained.');
$assert(array('woven', 'everyday') === ($row['tags'] ?? null), 'Keywords are trimmed and deduplicated.');
$assert(array('https://assets.example/front.jpg', 'https://assets.example/back.jpg', 'https://assets.example/detail.jpg', 'https://assets.example/stone.jpg', 'https://assets.example/ink.jpg') === array_column($row['images'] ?? array(), 'src'), 'Group then variant gallery is deduplicated in order.');
$assert('Back view' === ($row['images'][1]['alt'] ?? null) && ($row['image'] ?? null) === ($row['images'][0]['src'] ?? null), 'ImageObject alt and legacy first image survive.');
$variants = $row['variants'] ?? array();
$assert(3 === count($variants), 'All declared variants are emitted.');
$assert('SH#SmallStone' === ($variants[0]['sku'] ?? null), 'Variant SKU is decoded.');
$assert('30.00' === ($variants[0]['regular_price'] ?? null) && '20.00' === ($variants[0]['sale_price'] ?? null), 'Strikethrough price is regular, offer price is sale.');
$assert(array('instock', 'outofstock', 'instock') === array_column($variants, 'stock_status'), 'Availability is normalized.');
$assert(array('key' => 'size', 'label' => 'Size', 'value' => 'small', 'value_label' => 'Small') === ($variants[0]['attributes'][0] ?? null), 'Attributes use the existing row shape.');
$assert(array() === $diagnostics, 'Declared variants have no diagnostic.');

$bare = $group;
unset($bare['sku']);
foreach ($bare['hasVariant'] as &$child) unset($child['additionalProperty']);
unset($child);
$diagnostics = array();
$simple = $read($bare, $diagnostics)['field-shirt'] ?? array();
$assert($simple && !isset($simple['variants']) && !isset($simple['sku']), 'Display names and productGroupID do not invent attributes or SKU.');
$assert(($simple['images'] ?? null) === ($row['images'] ?? null) && '22.00' === ($simple['regular_price'] ?? null), 'Undeclared variants keep gallery and lowest price.');
$assert(array('product_variants_undeclared') === array_column($diagnostics, 'code'), 'Undeclared variants are diagnosed.');
$explicit = $bare;
$explicit['productGroupID'] = array('@type' => 'PropertyValue', 'propertyID' => 'sku', 'value' => 'SH%23EXPLICIT');
$assert('SH#EXPLICIT' === ($read($explicit)['field-shirt']['sku'] ?? null), 'Only an explicitly labelled group ID supplies a SKU.');
$legacy = array('@type' => 'Product', 'name' => 'Notebook', 'url' => 'https://store.example/products/notebook', 'image' => 'https://assets.example/notebook.jpg', 'offers' => array('@type' => 'Offer', 'price' => '10.00'));
$assert(array('name' => 'Notebook', 'slug' => 'notebook', 'regular_price' => '10.00', 'source_path' => 'products/field-shirt.html', 'url' => 'https://store.example/products/notebook', 'image' => 'https://assets.example/notebook.jpg') === ($read($legacy)['notebook'] ?? null), 'Legacy single-image Product retains its fields and adds the source URL.');
$legacy['image'] = array('https://assets.example/notebook.jpg', array('@type' => 'ImageObject', 'url' => 'https://assets.example/notebook-back.jpg'));
$legacy['keywords'] = array('paper', 'notes');
$assert(2 === count($read($legacy)['notebook']['images'] ?? array()) && array('paper', 'notes') === ($read($legacy)['notebook']['tags'] ?? null), 'Plain Products retain galleries and list keywords.');
$duplicate = $group;
$duplicate['hasVariant'][2]['additionalProperty'] = $duplicate['hasVariant'][0]['additionalProperty'];
$diagnostics = array();
$assert(!isset($read($duplicate, $diagnostics)['field-shirt']['variants']) && count($diagnostics) === 1, 'Duplicate combinations decline variants.');
$partial = $group;
unset($partial['hasVariant'][1]['additionalProperty']);
$assert(!isset($read($partial)['field-shirt']['variants']), 'Every variant must declare attributes.');
$standard = $bare;
$standard['variesBy'] = array('https://schema.org/size', 'color', 'http://schema.org/material', 'pattern');
foreach ($standard['hasVariant'] as $i => &$child) { $child['size'] = (string) $i; $child['color'] = 'Stone'; $child['material'] = 'Cotton'; $child['pattern'] = 'Plain'; }
unset($child);
$assert(4 === count($read($standard)['field-shirt']['variants'][0]['attributes'] ?? array()), 'variesBy standard properties declare attributes.');
foreach (array('InStock', 'LimitedAvailability', 'PreOrder', 'BackOrder', 'OutOfStock', 'SoldOut', 'Discontinued') as $status) {
    foreach (array('', 'http://schema.org/', 'https://schema.org/') as $prefix) {
        $plain = $group['hasVariant'][0];
        $plain['offers']['availability'] = $prefix . $status;
        $plain['offers']['priceSpecification']['priceType'] = 'http://schema.org/ListPrice';
        $p = $read($plain)['field-shirt'] ?? array();
        $assert((in_array($status, array('OutOfStock', 'SoldOut', 'Discontinued'), true) ? 'outofstock' : 'instock') === ($p['stock_status'] ?? null), 'Plain Product availability: ' . $prefix . $status);
        $assert('30.00' === ($p['regular_price'] ?? null) && '20.00' === ($p['sale_price'] ?? null), 'Plain Product list-price sale.');
    }
}
foreach (array(array($group), array('@graph' => array($group)), array('@type' => 'ItemList', 'itemListElement' => array(array('@type' => 'ListItem', 'item' => $group)))) as $wrapper) {
    $assert($row === ($read($wrapper)['field-shirt'] ?? null), 'Groups work in arrays, graphs and item lists.');
}

$compile = static function (array $schema, string $body = '') use ($html): array {
    $content = str_replace('</main>', $body . '</main>', $html($schema));
    return (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array('index.html' => $content)))->toArray();
};
$entities = static function (array $result): array {
    foreach ($result['source_reports']['wordpress_site_plan']['runtime_declarations'] ?? array() as $declaration) {
        if ('products' === ($declaration['type'] ?? null)) return $declaration['payload']['entities'] ?? array();
    }
    return array();
};
$assert(3 === count($entities($compile($group))[0]['variants'] ?? array()), 'Compiler publishes variants.');
$result = $compile($bare);
$assert(str_contains(json_encode($result), 'product_variants_undeclared'), 'Compiler publishes the undeclared diagnostic.');
$cards = '<div class="grid">';
foreach (array('Field Shirt', 'Notebook', 'Pencil', 'Folder') as $name) $cards .= '<div class="card"><img src="https://assets.example/card.jpg" alt="' . $name . '"><h3>' . $name . '</h3><span class="price">$40.00</span></div>';
$cards .= '</div>';
$merged = array_column($entities($compile($group, $cards)), null, 'slug')['field-shirt'] ?? array();
$assert('40.00' === ($merged['regular_price'] ?? null) && 'https://assets.example/card.jpg' === ($merged['image'] ?? null) && !empty($merged['bindings']), 'Card prices, image and bindings win.');
$assert(($merged['variants'] ?? null) == $variants && ($merged['images'] ?? null) == ($row['images'] ?? null) && ($merged['sku'] ?? null) === 'SH#GROUP' && ($merged['tags'] ?? null) === $row['tags'], 'Schema enrichments fill missing card fields.');
if ($failures) exit(1);
echo "schema-product-group: ok\n";
