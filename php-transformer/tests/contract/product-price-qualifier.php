<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

/**
 * A product card whose price element carries a qualifier beside the amount
 * (`<span class="price"><small>from </small>$40.00</span>`, the usual way a
 * store shows a variable product's lowest price) is still a product: the price
 * element is the leaf that carries the amount, because its only child is a
 * qualifier rather than another price. A wrapper around separate regular and
 * sale amounts still yields the individual amounts, not its joined text.
 *
 * A block-level price element whose qualifier has its own look becomes a
 * group of blocks while a plain price stays one block. The grid still repeats
 * from one item template: the price is one slot every card fills whole.
 */

$assert = static function (bool $condition, string $message): void {
    if (! $condition) {
        throw new RuntimeException($message);
    }
};

$card = static fn (string $name, string $price): string => '<article class="card"><img src="/media/' . strtolower($name[0]) . '.png" alt="' . $name . '" width="200" height="200">'
    . '<div class="card-body"><h3 class="card-title">' . $name . '</h3>'
    . '<div class="card-foot">' . $price . '<button type="button" class="card-atc" aria-label="Add ' . $name . ' to cart">Add to cart</button></div></div></article>';
$shop = '<!doctype html><html><head><title>Shop</title></head><body><main><h1>Shop</h1><div class="grid">'
    . $card('Alpha Lamp', '<span class="price">$10.00</span>')
    . $card('Beta Chair', '<span class="price"><small>from </small>$40.00</span>')
    . $card('Gamma Rug', '<span class="price"><del>$30.00</del> <ins>$24.00</ins></span>')
    . $card('Delta Vase', '<span class="price"><small>from </small>$<!-- -->15.00</span>')
    . '</div></main></body></html>';

$result = (new ArtifactCompiler())->compile(array('entrypoint' => 'shop.html', 'files' => array('shop.html' => $shop)))->toArray();
$plan = $result['source_reports']['wordpress_site_plan'] ?? array();
$products = current(array_filter($plan['runtime_declarations'] ?? array(), static fn (array $declaration): bool => 'products' === ($declaration['type'] ?? null))) ?: array();
$byName = array_column($products['payload']['entities'] ?? array(), null, 'name');

$assert(array('Alpha Lamp', 'Beta Chair', 'Delta Vase', 'Gamma Rug') === array_values(array_intersect(array('Alpha Lamp', 'Beta Chair', 'Delta Vase', 'Gamma Rug'), array_keys($byName))), 'Every card is a declared product, including the "from" priced ones: ' . json_encode(array_keys($byName)));
$price = static fn (string $name): string => (string) json_encode(array_intersect_key($byName[$name] ?? array(), array('price' => 1, 'regular_price' => 1, 'sale_price' => 1)));
$assert(str_contains($price('Beta Chair'), '40') && ! str_contains($price('Beta Chair'), 'from'), 'A "from" price yields its amount: ' . $price('Beta Chair'));
$assert(str_contains($price('Delta Vase'), '15'), 'A "from" price split by a comment yields its amount: ' . $price('Delta Vase'));
$assert(str_contains($price('Gamma Rug'), '30') && ! str_contains($price('Gamma Rug'), '24'), 'A wrapper around separate amounts is not read as one joined price: ' . $price('Gamma Rug'));

$grids = current(array_filter($plan['runtime_declarations'] ?? array(), static fn (array $declaration): bool => 'product_grids' === ($declaration['type'] ?? null))) ?: array();
$items = $grids['payload']['entities'][0]['items'] ?? array();
$assert(4 === count($items), 'The grid declares all four cards as items: ' . count($items));

// A block-level price with a styled qualifier is drawn as several blocks;
// plain prices stay one.
$styled = '<!doctype html><html><head><title>Shop</title><link rel="stylesheet" href="shop.css"></head><body><main><h1>Shop</h1><div class="grid">'
    . $card('Alpha Lamp', '<div class="price">$10.00</div>')
    . $card('Beta Chair', '<div class="price"><small>from </small>$<!-- -->40.00</div>')
    . $card('Gamma Rug', '<div class="price">$24.00</div>')
    . '</div></main></body></html>';
$css = '.grid{display:grid;grid-template-columns:repeat(3,1fr);gap:16px}.price{font-weight:800;font-size:1.2rem}.price small{font-weight:500;font-size:.75rem;color:#777}';
$styledPlan = (new ArtifactCompiler())->compile(array('entrypoint' => 'shop.html', 'files' => array('shop.html' => $styled, 'shop.css' => $css)))->toArray()['source_reports']['wordpress_site_plan'] ?? array();
$styledGrid = (current(array_filter($styledPlan['runtime_declarations'] ?? array(), static fn (array $declaration): bool => 'product_grids' === ($declaration['type'] ?? null))) ?: array())['payload']['entities'][0] ?? array();
$assert(3 === count($styledGrid['items'] ?? array()), 'A grid with a styled "from" price still declares every card: ' . count($styledGrid['items'] ?? array()));
$priceSlot = current(array_filter($styledGrid['item_template']['slots'] ?? array(), static fn (array $slot): bool => str_contains((string) json_encode(array_column($styledGrid['items'], 'slots')), '"' . $slot['id'] . '"') && str_contains((string) preg_replace('/\s+/', '', (string) ($styledGrid['items'][1]['slots'][$slot['id']]['text'] ?? '')), '$40.00'))) ?: array();
$betaPrice = (string) preg_replace('/\s+/', '', (string) ($styledGrid['items'][1]['slots'][$priceSlot['id'] ?? '']['text'] ?? ''));
$assert('from$40.00' === $betaPrice && '$10.00' === trim((string) ($styledGrid['items'][0]['slots'][$priceSlot['id'] ?? '']['text'] ?? '')), 'One slot carries each card\'s whole price, its qualifier included: ' . json_encode($styledGrid['item_template']['slots'] ?? array()));

echo "product-price-qualifier: ok\n";
