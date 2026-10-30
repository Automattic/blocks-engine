<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

/**
 * Product cards where only some carry an extra row between their fields (a
 * "Blend of" chip row above the price, say) still repeat from one item
 * template: the row is an optional slot and the fields after it keep their
 * roles in every card.
 */

$assert = static function (bool $condition, string $message): void {
    if (! $condition) {
        throw new RuntimeException($message);
    }
};

$card = static fn (string $name, string $note, array $parts, string $price): string => '<div class="card"><a class="card-link" href="/products/' . strtolower(strtok($name, ' ')) . '"><img src="/media/' . strtolower($name[0]) . '.png" alt="' . $name . '" width="200" height="200">'
    . '<div class="card-body"><h3>' . $name . '</h3><div class="sub">' . $note . '</div>'
    . ( array() === $parts ? '' : '<div class="card-blend"><span class="card-blend-label">Blend of</span>' . implode('', array_map(static fn (string $part): string => '<span class="chip">' . $part . '</span>', $parts)) . '</div>' )
    . '<div class="card-foot"><div class="price">$' . $price . '</div></div></div></a>'
    . '<button type="button" class="card-atc" aria-label="Add ' . $name . ' to cart">Add to cart</button></div>';
$shop = '<!doctype html><html><head><title>Shop</title></head><body><main><h1>Shop</h1><div class="grid">'
    . $card('Alpha Mix', 'Research blend', array('Rose', 'Iris'), '30.00')
    . $card('Beta Oil', 'Single note', array(), '12.00')
    . $card('Gamma Mix', 'Research blend', array('Oak', 'Moss', 'Amber'), '45.00')
    . $card('Delta Oil', 'Single note', array(), '14.00')
    . '</div></main></body></html>';

$plan = (new ArtifactCompiler())->compile(array('entrypoint' => 'shop.html', 'files' => array('shop.html' => $shop)))->toArray()['source_reports']['wordpress_site_plan'] ?? array();
$grid = (current(array_filter($plan['runtime_declarations'] ?? array(), static fn (array $declaration): bool => 'product_grids' === ($declaration['type'] ?? null))) ?: array())['payload']['entities'][0] ?? array();
$assert(4 === count($grid['items'] ?? array()), 'Cards with and without the optional row form one grid: ' . count($grid['items'] ?? array()));

$slots = $grid['item_template']['slots'] ?? array();
$byRole = static fn (string $role): array => array_values(array_filter($slots, static fn (array $slot): bool => $role === $slot['role']));
$assert(1 === count($byRole('price')) && ! isset($byRole('price')[0]['optional']), 'The price is one required slot in every card: ' . json_encode($slots));
$assert(1 === count($byRole('name')) && 1 === count($byRole('add_to_cart')), 'Name and add-to-cart stay recognized slots: ' . json_encode($slots));
$priceSlot = $byRole('price')[0]['id'];
foreach ($grid['items'] as $item) {
    $assert(isset($item['slots'][$priceSlot]), 'Every card fills the price slot: ' . json_encode($item));
}
$optional = array_values(array_filter($slots, static fn (array $slot): bool => true === ($slot['optional'] ?? false)));
$assert(array() !== $optional, 'The blend row is optional: ' . json_encode($slots));
$betaHasBlend = array() !== array_intersect(array_column($optional, 'id'), array_keys($grid['items'][1]['slots']));
$assert(! $betaHasBlend, 'A card without the row fills none of its slots.');
$gammaText = implode(' ', array_column($grid['items'][2]['slots'], 'text'));
$assert(str_contains($gammaText, 'Moss'), 'A card with the row carries its own chips: ' . $gammaText);

echo "product-grid-optional-row: ok\n";
