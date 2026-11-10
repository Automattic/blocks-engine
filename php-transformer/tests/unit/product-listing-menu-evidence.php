<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

$assert = static function (bool $condition, string $message): void {
    if (! $condition) throw new RuntimeException($message);
};

foreach (array(
    'menu' => array('currency' => '', 'cart' => '', 'controls' => '', 'store' => false),
    'purchase' => array('currency' => '', 'cart' => '<button type="button">Add to cart</button>', 'controls' => '', 'store' => true),
    'catalog' => array('currency' => '$', 'cart' => '', 'controls' => '<a href="/cart/">Cart</a><label>Sort by <select name="sort_by"><option value="manual">Featured</option><option value="price-ascending">Price: low to high</option></select></label>', 'store' => true),
) as $case => $options) {
    $rows = '';
    foreach (array('Noodles', 'Dumplings', 'Rice', 'Soup') as $index => $name) {
        $rows .= '<li class="row"><button class="toggle" type="button"><span class="name">' . $name . '</span></button><span class="price">' . $options['currency'] . (8 + $index) . '.50</span><div class="detail"><img class="photo" alt="' . $name . '" src="media/dish-' . ($index + 1) . '.png" width="400" height="400"></div>' . $options['cart'] . '</li>';
    }
    $html = '<!doctype html><html lang="en"><head><meta charset="utf-8"><title>Menu</title><style>body{margin:0;font-family:Georgia,serif;color:#222;background:#fff}header,footer{padding:16px;background:#eee}.items{list-style:none;margin:24px;padding:0}.row{display:flex;flex-wrap:wrap;gap:12px;padding:8px 0;border-bottom:1px solid #ddd}.toggle{border:0;background:none;font:inherit;font-weight:bold}.price{margin-left:auto}.detail{flex-basis:100%}.photo{width:120px;height:auto}</style></head><body><header><p>Example Kitchen</p></header><main><h1>Menu</h1>' . $options['controls'] . '<ul class="items">' . $rows . '</ul></main><footer><p>Example Kitchen</p></footer></body></html>';
    $result = (new ArtifactCompiler())->compile(array('entrypoint' => 'menu.html', 'files' => array('menu.html' => $html)))->toArray();
    $plan = $result['source_reports']['wordpress_site_plan'] ?? array();
    $declarations = $plan['runtime_declarations'] ?? array();
    $types = array_column($declarations, 'type');
    echo $case . ' runtime declarations: ' . json_encode($types) . PHP_EOL;
    $assert('failed' !== $result['status'] && !isset($result['source_reports']['wordpress_site_plan_diagnostics']), $case . ': compilation succeeds cleanly');
    $assert($options['store'] === in_array('product_grids', $types, true), $case . ': only commerce evidence declares a product grid');
    $assert($options['store'] === in_array('products', $types, true), $case . ': only commerce evidence creates products');
    if (! $options['store']) {
        $assert(!in_array('product_listings', $types, true), 'Menu has no product listing');
        $assert(!str_contains(strtolower(json_encode($result)), 'woocommerce'), 'Menu has no WooCommerce dependency');
        $assert(!str_contains(json_encode($declarations), 'generic/product-grids/v1') && !str_contains(json_encode($declarations), 'generic/product-listings/v1'), 'Menu has no commerce runtime schema');
        foreach (array('Noodles', 'Dumplings', 'Rice', 'Soup', '8.50', 'media/dish-1.png') as $content) {
            $assert(str_contains(json_encode($result, JSON_UNESCAPED_SLASHES), $content), 'Menu retains content: ' . $content);
        }
    }
}
echo "product-listing-menu-evidence: ok\n";
