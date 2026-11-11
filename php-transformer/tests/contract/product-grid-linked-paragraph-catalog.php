<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

// Four captured cards from #4462, including opaque classes, paragraph names,
// separate image/details links and screen-reader price labels. No schema/cart.
$html = '<a href="/cart/">Cart</a>' . file_get_contents(dirname(__DIR__) . '/fixtures/commerce/linked-paragraph-catalog.html');
$report = (new HtmlTransformer())->transform($html, array());
$grids = array_values(array_filter($report->fallbacks, static fn (array $finding): bool => 'html_product_grid_fallback' === ($finding['diagnostic_code'] ?? null)));
if (1 !== count($grids) || 4 !== ($grids[0]['product_count'] ?? 0)) {
    throw new RuntimeException('Expected one grid with 4 products; html_product_grid_fallback=' . count($grids));
}
$expected = array(
    array('Silky Oak Serving Board', '$120.00', 'silky-oak-serving-board'),
    array('Corwie and Bluegum Cutting & Serving Board', '$150.00', 'corwie-and-bluegum-cutting-serving-board'),
    array('Blugum Chopping Board', '$150.00', 'blugum-chopping-board'),
    array('Bluegum & Tallow Chopping board', '$180.00', 'copy-of-aussy-bush-charcuterie-board'),
);
foreach ($expected as $index => [$name, $price, $slug]) {
    $product = $grids[0]['products'][$index] ?? array();
    if ($name !== ($product['name'] ?? null) || $price !== ($product['price'] ?? null)
        || '/media/p.png' !== ($product['image']['src'] ?? null)
        || '/product-page/' . $slug . '/index.html' !== ($product['url'] ?? null)) {
        throw new RuntimeException('Product fields must preserve the captured identity: ' . json_encode($product));
    }
}
// The new title path must not bypass the existing small-comparison and
// recurring-plan guards, even when every plan has an image and detail link.
$document = new DOMDocument();
@$document->loadHTML($html);
$cards = $document->getElementsByTagName('li');
$cards->item(3)->parentNode->removeChild($cards->item(3));
$smallCatalog = $document->saveHTML();
$recurringCatalog = str_replace(array('$120.00', '$150.00', '$180.00'), array('$120.00 per month', '$150.00 per month', '$180.00 per month'), $html);
foreach (array($smallCatalog, $recurringCatalog) as $comparison) {
    $findings = (new HtmlTransformer())->transform($comparison, array())->fallbacks;
    if (in_array('html_product_grid_fallback', array_column($findings, 'diagnostic_code'), true)) {
        throw new RuntimeException('Small comparisons and recurring plans must not become product grids.');
    }
}
echo "product-grid-linked-paragraph-catalog: ok (1 grid, 4 products with name, price, image and URL)\n";
