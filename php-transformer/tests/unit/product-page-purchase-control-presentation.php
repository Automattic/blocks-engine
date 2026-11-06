<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\FormPresentationGraphBuilder;

$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};
$html = '<!doctype html><html><head><title>Desk Lamp</title>'
    . '<script type="application/ld+json">{"@context":"https://schema.org","@type":"Product","name":"Desk Lamp","url":"https://example.test/products/desk-lamp","offers":{"@type":"Offer","price":"40.00","priceCurrency":"USD"}}</script>'
    . '</head><body><main><h1>Desk Lamp</h1><p>$40.00</p>'
    . '<form method="post" action="/cart/add">'
    . '<label>Finish<select name="finish" style="border-radius:13px"><option>Brass</option><option>Black</option></select></label>'
    . '<label>Quantity<input type="number" name="quantity" min="1" value="1"></label>'
    . '<button type="submit" style="border:2px solid black">Add to cart</button>'
    . '</form></main></body></html>';
$result = (new ArtifactCompiler())->compile(array(
    'entrypoint' => 'products/desk-lamp.html',
    'files' => array('products/desk-lamp.html' => $html),
))->toArray();
$assert('failed' !== $result['status'], 'Product detail page compiles.');
$product = array();
foreach ($result['source_reports']['wordpress_site_plan']['runtime_declarations'] ?? array() as $declaration) {
    if ('products' !== ($declaration['type'] ?? null)) continue;
    foreach ($declaration['payload']['entities'] as $entity) {
        if ('desk-lamp' === ($entity['slug'] ?? null)) $product = $entity;
    }
}
$controls = $product['controls'] ?? array();
$assert(array('select', 'input', 'button') === array_column($controls, 'tag'), 'Product carries the normalized purchase controls in source order.');
$assert('number' === ($controls[1]['type'] ?? null), 'Quantity remains a number input.');
$assert('submit' === ($controls[2]['type'] ?? null), 'Purchase button remains a submit control.');
$graph = $product['presentation_graph'] ?? array();
FormPresentationGraphBuilder::assertValid($graph);
$assert(true !== ($graph['truncated'] ?? false), 'Product presentation graph is complete.');
$styles = array();
foreach ($graph['controls'] as $row) {
    $assert(isset($controls[$row['index']]), 'Presentation indexes address the product control list.');
    $styles[$row['index']] = $row['control']['styles'] ?? array();
}
$assert('13px' === ($styles[0]['border_radius'] ?? null), 'Select border radius survives the product handoff.');
$assert('2px solid black' === ($styles[2]['border'] ?? null), 'Submit border survives the product handoff.');
echo "product-page-purchase-control-presentation: ok\n";
