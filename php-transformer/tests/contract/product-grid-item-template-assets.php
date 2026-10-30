<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\RuntimeEntityManifest;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\WordPressSitePlan;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\WordPressSitePlanResolver;

/**
 * Contract coverage for local media inside a product grid's item template.
 *
 * A shop provider renders the item template's card and each product's slot
 * values in place of the page's own cards, so that markup must reference
 * published theme assets exactly as the page does: declared tokens in the
 * canonical plan, theme URLs once the plan is resolved. A card image left at
 * its capture-relative path (`media/alpha.png`) resolves against the page URL
 * and 404s on the imported site.
 */

$assert = static function (bool $condition, string $message): void {
    if (! $condition) {
        throw new RuntimeException($message);
    }
};

$png = (string) base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==');
$card = static fn (string $name, string $image, string $price): string => '<article class="card"><img src="media/' . $image . '.png" alt="' . $name . '">'
    . '<h3 class="card-title">' . $name . '</h3><div class="card-foot"><span class="price">$' . $price . '</span>'
    . '<button type="button" class="btn" data-add="' . $image . '">Add to cart</button></div></article>';
$shop = '<!doctype html><html><head><title>Shop</title></head><body><main><h1>Shop</h1><div class="grid" data-grid>'
    . $card('Alpha Lamp', 'alpha', '10.00')
    . $card('Beta Chair', 'beta', '12.00')
    . $card('Gamma Rug', 'gamma', '14.00')
    . '</div></main><script src="app.js"></script></body></html>';

$result = (new ArtifactCompiler())->compile(array('entrypoint' => 'shop.html', 'files' => array(
    'shop.html' => $shop,
    'app.js' => "var grid = document.querySelector('[data-grid]'); if (grid) { grid.innerHTML = grid.innerHTML; }",
    'media/alpha.png' => $png,
    'media/beta.png' => $png . 'b',
    'media/gamma.png' => $png . 'c',
)))->toArray();
$assert('failed' !== $result['status'] && ! isset($result['source_reports']['wordpress_site_plan_diagnostics']), 'The store composes cleanly: ' . json_encode($result['source_reports']['wordpress_site_plan_diagnostics'] ?? array()));
$plan = $result['source_reports']['wordpress_site_plan'] ?? array();

$gridEntities = static function (array $declarations, array $records): array {
    foreach ($declarations as $declaration) {
        if ('product_grids' !== ($declaration['type'] ?? null)) continue;
        return RuntimeEntityManifest::SCHEMA === ($declaration['payload']['schema'] ?? null) ? RuntimeEntityManifest::resolve($declaration['payload'], $records) : ($declaration['payload']['entities'] ?? array());
    }
    return array();
};
/** Every markup an item template carries: the template card, then each slot value. */
$templateMarkups = static function (array $grid): array {
    $markups = array((string) ($grid['item_template']['block_markup'] ?? ''));
    foreach ($grid['items'] ?? array() as $item) foreach ($item['slots'] ?? array() as $slot) if (is_string($slot['markup'] ?? null)) $markups[] = $slot['markup'];
    return $markups;
};
$imageMarkups = static fn (array $markups): array => array_values(array_filter($markups, static fn (string $markup): bool => str_contains($markup, '<img')));

$grids = $gridEntities($plan['runtime_declarations'] ?? array(), $plan['runtime_entity_records'] ?? array());
$assert(1 === count($grids) && is_array($grids[0]['item_template'] ?? null), 'The shop grid is declared with an item template.');
$canonical = $imageMarkups($templateMarkups($grids[0]));
// The template card plus one image slot value per product.
$assert(4 === count($canonical), 'The template card and every product image slot carry an image: ' . count($canonical));
foreach ($canonical as $markup) {
    $assert(! str_contains($markup, 'src="media/'), 'Canonical item template markup holds no capture-relative media path: ' . $markup);
    $assert(str_contains($markup, WordPressSitePlan::TOKEN_PREFIX), 'Canonical item template markup references media through a declared token: ' . $markup);
}

$resolved = (new WordPressSitePlanResolver())->resolve($plan, array('theme_uri' => 'https://example.test/theme'));
$resolvedGrids = array();
foreach ($resolved['runtime_entity_resolution'] ?? array() as $resolution) if ('product_grids' === ($resolution['type'] ?? null)) $resolvedGrids = $resolution['entities'];
if (array() === $resolvedGrids) $resolvedGrids = $gridEntities($resolved['runtime_declarations'] ?? array(), $resolved['runtime_entity_records'] ?? array());
$assert(1 === count($resolvedGrids), 'The resolved plan carries the product grid.');
$images = $imageMarkups($templateMarkups($resolvedGrids[0]));
$assert(4 === count($images), 'The resolved item template keeps every image.');
foreach (array('alpha', 'beta', 'gamma') as $name) {
    $assert(array() !== array_filter($images, static fn (string $markup): bool => (bool) preg_match('~src="https://example\.test/theme/[^"]*' . $name . '[^"]*\.png"~', $markup)), 'The ' . $name . ' slot image resolves to its published theme asset: ' . json_encode($images));
}
foreach ($images as $markup) $assert(! str_contains($markup, WordPressSitePlan::TOKEN_PREFIX) && ! str_contains($markup, 'src="media/'), 'Resolved item template markup holds neither tokens nor capture paths: ' . $markup);

echo "product-grid item template assets contract passed\n";
