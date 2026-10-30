<?php
declare(strict_types=1);

/** Inert commerce content is accounted for only as template metadata (#3208). */
require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

$failures = 0;
$assert = static function (bool $ok, string $message) use (&$failures): void {
    if ($ok) return;
    ++$failures;
    fwrite(STDERR, 'FAIL: ' . $message . PHP_EOL);
};
$grid = <<<'HTML'
<ul class="grid">
  <li><a href="/products/beta-item"><img src="b.jpg" alt=""><h3>Beta Item</h3></a><span class="price">$24.00</span><button>Add to cart</button></li>
</ul>
HTML;
$template = '<aside class="drawer"><div><template><div>' . $grid . '</div></template></div></aside>';
$compile = static function (string $content): array {
    $html = '<!doctype html><html><head><title>Catalog</title></head><body><main>' . $content . '</main></body></html>';
    return (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array('index.html' => $html)))->toArray();
};
$findings = static fn (array $result, string $code): array => array_values(array_filter(
    $result['source_reports']['conversion_report']['fallback_diagnostics'] ?? array(),
    static fn (array $finding): bool => $code === ($finding['diagnostic_code'] ?? null)
));
$entities = static function (array $result, string $type): array {
    $entities = array();
    foreach ($result['source_reports']['wordpress_site_plan']['runtime_declarations'] ?? array() as $declaration) {
        if ($type === ($declaration['type'] ?? null)) {
            $entities = array_merge($entities, $declaration['payload']['entities'] ?? array());
        }
    }
    return $entities;
};
foreach (array(
    'plain and template' => array($grid . $template, 1, 1),
    'template only' => array($template, 0, 1),
    'plain only' => array($grid, 1, 0),
    // Closed/hidden authored UI is not inert template scaffolding.
    'hidden drawer' => array('<aside hidden aria-hidden="true">' . $grid . '</aside>', 1, 0),
) as $label => [$content, $expectedGrids, $expectedTemplates]) {
    $result = $compile($content);
    $grids = $findings($result, 'html_product_grid_fallback');
    $assert($expectedGrids === count($grids), "$label: only authored grids are reported");
    $assert($expectedGrids === count($findings($result, 'html_commerce_controls_fallback')), "$label: only authored commerce controls are reported");
    $assert($expectedTemplates === count($findings($result, 'html_template_metadata')), "$label: template is accounted for exactly once");
    foreach ($grids as $finding) {
        $assert(!str_contains($finding['selector'] ?? '', 'template'), "$label: no template grid finding");
        $assert(1 === ($finding['product_count'] ?? null), "$label: grid has exactly one product");
    }
    $products = $entities($result, 'products');
    $assert($expectedGrids === count($products), "$label: only authored products are declared");
    $assert($expectedGrids === count($entities($result, 'product_grids')), "$label: only authored grids are declared");
    foreach ($products as $product) {
        $assert('Beta Item' === ($product['name'] ?? null) && '24.00' === ($product['regular_price'] ?? null), "$label: product data is preserved");
    }
    $assert(!str_contains(json_encode($result, JSON_THROW_ON_ERROR), 'runtime_product_grid_binding_declined'), "$label: no declined grid bindings");
    $assert(!str_contains(json_encode($result, JSON_THROW_ON_ERROR), 'page_owned_collection_anchor_unresolved'), "$label: no unresolved collection anchors");
}

$cart = '<section><h3>Order summary</h3><div><span>Subtotal</span><span>$24.00</span></div><div><span>Total</span><span>$24.00</span></div><button>Proceed to checkout</button></section>';
$result = $compile($grid . $cart . '<template><div>' . $cart . '</div></template>');
$assert(1 === count($findings($result, 'html_cart_view_fallback')), 'Only the authored cart view is reported');
$assert(1 === count($entities($result, 'carts')), 'Only the authored cart is declared');
$assert(1 === count($findings($result, 'html_template_metadata')), 'Cart template is accounted for exactly once');

if ($failures) exit(1);
echo "product-grid-template-content: ok\n";
