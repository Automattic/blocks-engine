<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

/**
 * Contract coverage for a product grid a source script redraws: the producer
 * must declare it as a `generic/product-grids/v1` entity a shop provider can
 * replace with its own product loop. The entity carries:
 *
 * - a `commerce_product_grid` binding on the grid that supersedes the
 *   script's grid hook, so the source script stops redrawing the cards;
 * - the card as an item template: one card's block markup plus its slots,
 *   the leaves that show a product field or differ between cards;
 * - each product's value for every slot.
 *
 * A card that leaves a container empty makes the slots below it optional. A
 * grid is declared only beside a products collection. Runs the full
 * `ArtifactCompiler` -> `WordPressSitePlan` pipeline because bindings are
 * projected at site-plan composition.
 */

$assert = static function (bool $condition, string $message): void {
    if (! $condition) {
        throw new RuntimeException($message);
    }
};

$card = static fn (string $name, string $note, array $chips, string $price, string $tone): string => '<article class="card"><div class="card-art ' . $tone . '">' . strtoupper($name[0]) . '</div>'
    . '<div class="card-body"><h3 class="card-title">' . $name . '</h3><p class="card-note">' . $note . '</p>'
    . '<div class="card-meta">' . implode('', array_map(static fn (string $chip): string => '<span class="chip ' . $tone . '">' . $chip . '</span>', $chips)) . '</div>'
    . '<div class="card-foot"><span class="price">$' . $price . '</span><button type="button" class="btn" data-add="' . strtolower(strtok($name, ' ')) . '">Add to cart</button></div></div></article>';
$shop = '<!doctype html><html><head><title>Shop</title></head><body><main><h1>Shop</h1><p class="lede">Everything we stock.</p><div class="grid" data-grid>'
    . $card('Alpha Lamp', 'Brass', array('Warm', 'Dimmable'), '10.00', 'tone-a')
    . $card('Beta Chair', 'Oak', array('Seats 1', 'Stackable'), '12.00', 'tone-b')
    . $card('Gamma Rug', 'Wool', array(), '14.00', 'tone-c')
    . '</div></main><script src="app.js"></script></body></html>';
$script = "var grid = document.querySelector('[data-grid]'); if (grid) { grid.innerHTML = grid.innerHTML; }";

$compile = static fn (array $files): array => (new ArtifactCompiler())->compile(array('entrypoint' => 'shop.html', 'files' => $files))->toArray();
$declaration = static fn (array $plan, string $type): ?array => current(array_filter($plan['runtime_declarations'] ?? array(), static fn (array $declaration): bool => $type === ($declaration['type'] ?? null))) ?: null;

// Case A: a script-redrawn grid with a card that has no chips.
$result = $compile(array('shop.html' => $shop, 'app.js' => $script));
$assert('failed' !== $result['status'] && ! isset($result['source_reports']['wordpress_site_plan_diagnostics']), 'The store composes cleanly: ' . json_encode($result['source_reports']['wordpress_site_plan_diagnostics'] ?? array()));
$plan = $result['source_reports']['wordpress_site_plan'] ?? array();
$assert(null !== $declaration($plan, 'products'), 'The products collection is declared.');

$grids = $declaration($plan, 'product_grids');
$assert(null !== $grids, 'A generic/product-grids/v1 entity collection is declared beside the products.');
$entities = $grids['payload']['entities'] ?? array();
$assert(1 === count($entities) && 'shop.html' === ($entities[0]['source_path'] ?? null), 'Exactly one product grid entity is declared for the shop page.');
$grid = $entities[0];

$binding = $grid['bindings'][0] ?? array();
$assert('generic/block-binding/v1' === ($binding['schema'] ?? null) && 'commerce_product_grid' === ($binding['role'] ?? null), 'The grid carries a commerce_product_grid block binding.');
$assert(array('[data-grid]') === ($binding['superseded_runtime_selectors'] ?? null), 'The binding supersedes the script hook that redraws the grid: ' . json_encode($binding['superseded_runtime_selectors'] ?? null));
$shopPage = current(array_filter($plan['pages'] ?? array(), static fn (array $page): bool => 'shop.html' === ($page['source_path'] ?? null)));
$search = (string) ($binding['search_block_markup'] ?? '');
$assert('' !== $search && 1 === substr_count((string) ($shopPage['canonical_block_markup'] ?? ''), $search), 'The grid anchor appears exactly once in the shop page markup.');
$assert(str_contains($search, 'Alpha Lamp') && str_contains($search, 'Gamma Rug') && ! str_contains($search, 'Everything we stock.'), 'The anchor covers the cards and nothing outside the grid.');

$template = $grid['item_template'] ?? array();
$assert('generic/collection-item-template/v1' === ($template['schema'] ?? null), 'The grid carries a generic item template.');
$templateMarkup = (string) ($template['block_markup'] ?? '');
$assert(str_starts_with($templateMarkup, '<!-- wp:') && str_contains($search, $templateMarkup) && str_contains($templateMarkup, 'Dimmable'), 'The template is one card from the page, chosen with its chips present.');

$slots = array();
foreach ($template['slots'] ?? array() as $slot) {
    $slots[$slot['role'] . ':' . implode('.', $slot['path'])] = $slot;
}
$roles = array_count_values(array_column($template['slots'] ?? array(), 'role'));
$assert(1 === ($roles['name'] ?? 0) && 1 === ($roles['price'] ?? 0) && 1 === ($roles['add_to_cart'] ?? 0) && 1 === ($roles['description'] ?? 0), 'The name, price, description and add-to-cart leaves are recognized slots: ' . json_encode(array_keys($slots)));
$chipSlots = array_values(array_filter($template['slots'] ?? array(), static fn (array $slot): bool => 'field' === $slot['role'] && true === ($slot['optional'] ?? false)));
$assert(2 === count($chipSlots) && true === ($chipSlots[0]['varies_class_name'] ?? false), 'The chips are optional generic fields whose class names vary per item: ' . json_encode($chipSlots));
$artSlot = array_values(array_filter($template['slots'] ?? array(), static fn (array $slot): bool => 'field' === $slot['role'] && ! isset($slot['optional'])));
$assert(1 === count($artSlot) && array(0) === $artSlot[0]['path'], 'The varying card art is a required generic field: ' . json_encode($artSlot));

$items = $grid['items'] ?? array();
$assert(array('alpha-lamp', 'beta-chair', 'gamma-rug') === array_column($items, 'product'), 'Each item names its product by slug, in grid order: ' . json_encode(array_column($items, 'product')));
$nameSlot = current(array_filter($template['slots'], static fn (array $slot): bool => 'name' === $slot['role']))['id'];
$chipSlot = $chipSlots[0]['id'];
$assert('Beta Chair' === ($items[1]['slots'][$nameSlot]['text'] ?? null), 'An item carries its product name.');
$assert(! isset($items[1]['slots'][$nameSlot]['markup']), 'A product field slot does not repeat markup the provider fills from product data.');
$assert('Seats 1' === ($items[1]['slots'][$chipSlot]['text'] ?? null) && str_contains((string) ($items[1]['slots'][$chipSlot]['markup'] ?? ''), 'Seats 1') && str_contains((string) ($items[1]['slots'][$chipSlot]['class_name'] ?? ''), 'tone-b'), 'A generic field carries its text, markup and per-item class.');
$assert(! isset($items[2]['slots'][$chipSlot]), 'The card without chips has no value for the optional chip slots.');

// Case B: cards a provider cannot repeat from one template declare no grid.
$mixed = '<!doctype html><html><head><title>Shop</title></head><body><main><h1>Shop</h1><div class="grid" data-grid>'
    . $card('Alpha Lamp', 'Brass', array('Warm'), '10.00', 'tone-a')
    . '<article class="card"><h3>Beta Chair</h3><span class="price">$12.00</span><button type="button" class="btn">Add to cart</button><ul><li>Oak</li><li>Ash</li></ul></article>'
    . $card('Gamma Rug', 'Wool', array('Soft'), '14.00', 'tone-c')
    . '</div></main><script src="app.js"></script></body></html>';
$mixedPlan = $compile(array('shop.html' => $mixed, 'app.js' => $script))['source_reports']['wordpress_site_plan'] ?? array();
$assert(null !== $declaration($mixedPlan, 'products'), 'Mixed-shape cards still declare their products.');
$assert(null === $declaration($mixedPlan, 'product_grids'), 'Cards with no shared shape declare no product grid.');

echo "product-grid-item-template: ok\n";
