<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

/**
 * A catalog card that publishes no price ("Price on request") is still a
 * product when it is the same kind of card as its priced siblings: it is
 * declared with its captured price label and no price, so its detail route
 * is still materialized. A card
 * of another kind, or one without its own detail link, is not admitted.
 */

$assert = static function (bool $condition, string $message): void {
    if (! $condition) {
        throw new RuntimeException($message);
    }
};

$card = static fn (string $slug, string $name, string $price, string $class = 'card'): string => '<article class="' . $class . '"><a class="card-image" href="/products/' . $slug . '/"><img src="/media/' . $slug . '.png" alt="' . $name . '" width="200" height="200"></a>'
    . '<div class="card-heading"><h3><a href="/products/' . $slug . '/">' . $name . '</a></h3><span>' . $price . '</span></div><p class="card-category">Lighting</p></article>';
$page = static fn (string $title, string $body): string => '<!doctype html><html><head><title>' . $title . '</title></head><body><header><a href="/cart/">Cart</a></header><main>' . $body . '</main></body></html>';
$files = array(
    'index.html' => $page('Home', '<h1>Home</h1><a href="/shop/">Shop</a>'),
    'shop/index.html' => $page('Shop', '<h1>Shop</h1><div class="grid">'
        . $card('alpha-lamp', 'Alpha Lamp', '€79.00')
        . $card('beta-vase', 'Beta Vase', 'Price on request')
        . $card('gamma-tray', 'Gamma Tray', '€26.90')
        . $card('delta-bin', 'Delta Bin', '€18.50')
        . $card('epsilon-bowl', 'Epsilon Bowl', '€42.00')
        . $card('zeta-note', 'Zeta Note', 'Coming soon', 'note')
        . '</div>'),
);
foreach (array('alpha-lamp' => 'Alpha Lamp', 'beta-vase' => 'Beta Vase', 'gamma-tray' => 'Gamma Tray', 'delta-bin' => 'Delta Bin', 'epsilon-bowl' => 'Epsilon Bowl', 'zeta-note' => 'Zeta Note') as $slug => $name) {
    $files['products/' . $slug . '/index.html'] = $page($name, '<h1>' . $name . '</h1><img src="/media/' . $slug . '.png" alt="' . $name . '">');
}

$result = (new ArtifactCompiler())->compile(array("entrypoint" => "index.html", "files" => $files))->toArray();
$plan = $result["source_reports"]["wordpress_site_plan"] ?? array();
$assert(array() !== $plan, "A site plan is produced: " . json_encode($result["source_reports"]["wordpress_site_plan_diagnostics"] ?? array()));
$products = array();
foreach ($plan['runtime_declarations'] ?? array() as $declaration) {
    if ('products' === ($declaration['type'] ?? null)) {
        foreach ($declaration['payload']['entities'] ?? array() as $entity) {
            $products[$entity['slug']] = $entity;
        }
    }
}

$assert(isset($products['alpha-lamp'], $products['gamma-tray']) && '79.00' === ($products['alpha-lamp']['regular_price'] ?? null), 'Priced cards stay priced products: ' . json_encode(array_keys($products)));
$assert(isset($products['beta-vase']), 'A price-on-request card of the same kind is a product: ' . json_encode(array_keys($products)));
$assert(! array_key_exists('regular_price', $products['beta-vase']) && 'Beta Vase' === $products['beta-vase']['name'] && 'Price on request' === ($products['beta-vase']['price_label'] ?? null), 'The price-on-request product keeps its captured label and no invented price: ' . json_encode($products['beta-vase']));
$assert('/products/beta-vase/' === ($products['beta-vase']['url'] ?? null) && '/media/beta-vase.png' === ($products['beta-vase']['image'] ?? null), 'The price-on-request product keeps its detail route and image: ' . json_encode(array_diff_key($products['beta-vase'], array('bindings' => true))));
$assert(! isset($products['zeta-note']), 'An unpriced card of another kind is not a product: ' . json_encode(array_keys($products)));

$grid = (current(array_filter($plan['runtime_declarations'] ?? array(), static fn (array $declaration): bool => 'product_grids' === ($declaration['type'] ?? null))) ?: array())['payload']['entities'][0] ?? array();
$assert(array('alpha-lamp', 'beta-vase', 'gamma-tray', 'delta-bin', 'epsilon-bowl') === array_column($grid['items'] ?? array(), 'product'), 'The grid repeats every card, including the price-on-request one: ' . json_encode(array_column($grid['items'] ?? array(), 'product')));

echo "product-grid-price-on-request: ok\n";
