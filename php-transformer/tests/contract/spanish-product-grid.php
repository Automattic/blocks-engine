<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

$html = file_get_contents(dirname(__DIR__) . '/fixtures/spanish-product-grid.html');
$result = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array('index.html' => $html)))->toArray();
$grids = array_values(array_filter(
    $result['source_reports']['conversion_report']['fallback_diagnostics'] ?? array(),
    static fn (array $finding): bool => 'html_product_grid_fallback' === ($finding['diagnostic_code'] ?? null)
));
if (1 !== count($grids) || 3 !== ($grids[0]['product_count'] ?? null)) {
    throw new RuntimeException('Expected one Spanish grid with three products.');
}
$declarations = array_values(array_filter(
    $result['source_reports']['wordpress_site_plan']['runtime_declarations'] ?? array(),
    static fn (array $declaration): bool => 'product_grids' === ($declaration['type'] ?? null)
));
$slots = $declarations[0]['payload']['entities'][0]['item_template']['slots'] ?? array();
$buttons = array_values(array_filter($slots, static fn (array $slot): bool => 'add_to_cart' === ($slot['role'] ?? null)));
if (1 !== count($buttons)) {
    throw new RuntimeException('Spanish button must have add_to_cart role: ' . json_encode($slots));
}
foreach ($grids[0]['products'] ?? array() as $product) {
    if (true !== ($product['has_cart_control'] ?? null)) {
        throw new RuntimeException('Spanish products must have cart controls.');
    }
}
echo "spanish-product-grid: ok\n";
