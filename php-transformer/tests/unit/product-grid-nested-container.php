<?php
declare(strict_types=1);

/** Regression for nested one-product grids (#3210), through artifact compilation. */
require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

$failures = 0;
$assert = static function (bool $ok, string $message) use (&$failures): void {
    if ($ok) return;
    ++$failures;
    fwrite(STDERR, 'FAIL: ' . $message . PHP_EOL);
};
$card = static fn (string $name, string $price): string => '<div class="product"><h1>' . $name
    . '</h1><span class="price">$' . $price . '</span><form><button type="submit">Add to cart</button></form></div>';
$html = '<!doctype html><html><head><title>Product notes</title></head><body><main>'
    . '<div class="small-12 columns"><div class="product-grid-container">' . $card('Alpha Shorts', '30.00') . '</div></div>'
    . '<ul class="grid"><li>' . $card('Beta Shirt', '40.00') . '</li><li>' . $card('Gamma Hat', '20.00') . '</li></ul>'
    . '<div class="grid">' . $card('Delta Socks', '10.00') . '</div>'
    . '</main></body></html>';
$result = (new ArtifactCompiler())->compile(['entrypoint' => 'index.html', 'files' => ['index.html' => $html]])->toArray();
$assert('failed' !== $result['status'], 'Compilation succeeds.');
$findings = array_values(array_filter($result['fallbacks'] ?? [], static fn (array $finding): bool => 'html_product_grid_fallback' === ($finding['diagnostic_code'] ?? null)));
$assert(3 === count($findings), 'Exactly three grids: inner single-card, two-card control, and sibling single-card.');
$alpha = array_values(array_filter($findings, static fn (array $finding): bool => in_array('Alpha Shorts', array_column($finding['products'], 'name'), true)));
$assert(1 === count($alpha), 'Exactly one finding claims Alpha Shorts (trunk emits two).');
$innerSelector = 'main:nth-of-type(1) > div:nth-of-type(1) > div:nth-of-type(1)';
$assert($innerSelector === ($alpha[0]['container_selector'] ?? null), 'The innermost qualifying container owns Alpha Shorts.');
$assert($innerSelector . ' > div:nth-of-type(1)' === ($alpha[0]['products'][0]['source_selector'] ?? null), 'The finding identifies the actual product card.');
$multi = array_values(array_filter($findings, static fn (array $finding): bool => 2 === ($finding['product_count'] ?? null)));
$assert(1 === count($multi) && ['Beta Shirt', 'Gamma Hat'] === array_column($multi[0]['products'] ?? [], 'name'), 'The separate two-card list is unchanged.');
$delta = array_values(array_filter($findings, static fn (array $finding): bool => ['Delta Socks'] === array_column($finding['products'], 'name')));
$assert(1 === count($delta), 'A sibling single-card grid remains independent.');
$entities = static function (string $type) use ($result): array {
    $entities = [];
    foreach ($result['source_reports']['wordpress_site_plan']['runtime_declarations'] ?? [] as $declaration) {
        if ($type === ($declaration['type'] ?? null)) {
            $entities = array_merge($entities, $declaration['payload']['entities'] ?? []);
        }
    }
    return $entities;
};
$grids = $entities('product_grids');
$assert(3 === count($grids), 'Exactly three product grids are declared.');
$items = array_map(static fn (array $grid): array => array_column($grid['items'] ?? [], 'product'), $grids);
usort($items, static fn (array $a, array $b): int => strcmp($a[0] ?? '', $b[0] ?? ''));
$assert([['alpha-shorts'], ['beta-shirt', 'gamma-hat'], ['delta-socks']] === $items, 'Declared grids claim each product exactly once, preserving the controls.');
foreach ($grids as $grid) {
    $assert('commerce_product_grid' === ($grid['bindings'][0]['role'] ?? null), 'Each declared grid has a commerce grid binding.');
    $assert('generic/collection-item-template/v1' === ($grid['item_template']['schema'] ?? null), 'Each grid retains its item template.');
}
$products = $entities('products');
$assert(4 === count($products), 'Exactly four products are declared.');
$bySlug = array_column($products, null, 'slug');
foreach (['alpha-shorts' => ['Alpha Shorts', '30.00'], 'beta-shirt' => ['Beta Shirt', '40.00'], 'gamma-hat' => ['Gamma Hat', '20.00'], 'delta-socks' => ['Delta Socks', '10.00']] as $slug => [$name, $price]) {
    $assert($name === ($bySlug[$slug]['name'] ?? null) && $price === ($bySlug[$slug]['regular_price'] ?? null), 'Declared product name and price: ' . $slug);
}
if ($failures) exit(1);
echo "product-grid-nested-container: ok\n";
