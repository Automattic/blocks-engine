<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

/**
 * Contract coverage for a one-product grid: a grid-like container whose only
 * product card carries strong evidence on its own — the card's own add-to-cart
 * control, or schema.org Product/Offer structure — is recognized as a
 * one-product grid. Repetition is only required for the weaker
 * image-corroborated catalog evidence, so a lone card with just an image, a
 * name and a price yields no grid finding.
 *
 * The one-product grid declares like every multi-card grid: a
 * `generic/product-grids/v1` entity with the `commerce_product_grid` binding
 * on the grid, the card's `commerce_controls` binding, the card as a
 * `generic/collection-item-template/v1` item template whose product-field
 * leaves are the named slots while every other leaf stays a literal, and the
 * product's value for every slot.
 */

$assert = static function (bool $condition, string $message): void {
    if (! $condition) {
        throw new RuntimeException($message);
    }
};

$cardWithControl = static fn (): string => '<div class="card">'
    . '<a class="card__link" href="/products/widget"><img src="/media/widget.png" alt="Widget"><div class="card-body"><h3>Widget</h3><div class="price">$65.00</div></div></a>'
    . '<button class="card-atc" aria-label="Add Widget to cart"><span>Add to Cart</span></button>'
    . '</div>';
$page = static fn (string $card): string => '<!doctype html><html><head><title>Compound notes</title></head><body><main>'
    . '<h1>Research notes</h1><p>Our lab keeps a short list of the compounds referenced in this article.</p>'
    . '<section class="related"><h2>Compounds referenced in this article</h2><div class="grid">' . $card . '</div></section>'
    . '<p>Contact the front desk to arrange equipment loans.</p></main></body></html>';

$compile = static fn (string $html): array => (new ArtifactCompiler())->compile(array('entrypoint' => 'article.html', 'files' => array('article.html' => $html)))->toArray();
$declaration = static fn (array $plan, string $type): ?array => current(array_filter($plan['runtime_declarations'] ?? array(), static fn (array $declaration): bool => $type === ($declaration['type'] ?? null))) ?: null;

// A lone card with its own add-to-cart control is a one-product grid.
$result = $compile($page($cardWithControl()));
$assert('failed' !== $result['status'] && ! isset($result['source_reports']['wordpress_site_plan_diagnostics']), 'The page composes cleanly: ' . json_encode($result['source_reports']['wordpress_site_plan_diagnostics'] ?? array()));
$plan = $result['source_reports']['wordpress_site_plan'] ?? array();

$findings = array_column((new HtmlTransformer())->transform($page($cardWithControl()), array())->fallbacks, 'product_count', 'diagnostic_code');
$assert(1 === ($findings['html_product_grid_fallback'] ?? 0), 'The grid finding carries exactly one product: ' . json_encode($findings));

$grids = $declaration($plan, 'product_grids');
$assert(null !== $grids, 'A one-product grid is declared: ' . json_encode(array_column($plan['runtime_declarations'] ?? array(), 'type')));
$entities = $grids['payload']['entities'] ?? array();
$assert(1 === count($entities) && 'article.html' === ($entities[0]['source_path'] ?? null), 'Exactly one product grid entity is declared.');
$grid = $entities[0];

$items = $grid['items'] ?? array();
$assert(1 === count($items) && 'widget' === ($items[0]['product'] ?? null), 'The grid declares exactly one product: ' . json_encode($items));

$template = $grid['item_template'] ?? array();
$assert('generic/collection-item-template/v1' === ($template['schema'] ?? null), 'The grid carries a generic/collection-item-template/v1 item template.');
$templateMarkup = (string) ($template['block_markup'] ?? '');
$assert(str_starts_with($templateMarkup, '<!-- wp:') && str_contains($templateMarkup, 'Widget') && str_contains($templateMarkup, 'Add to Cart'), "The template is the card's final block markup.");
$roles = array_count_values(array_column($template['slots'] ?? array(), 'role'));
$assert(1 === ($roles['name'] ?? 0) && 1 === ($roles['price'] ?? 0) && 1 === ($roles['image'] ?? 0) && 1 === ($roles['add_to_cart'] ?? 0), 'The name, price, image and add-to-cart leaves are the named slots, and every other leaf stays a literal: ' . json_encode(array_column($template['slots'] ?? array(), 'role')));
$nameSlot = current(array_filter($template['slots'], static fn (array $slot): bool => 'name' === $slot['role']))['id'];
$priceSlot = current(array_filter($template['slots'], static fn (array $slot): bool => 'price' === $slot['role']))['id'];
$assert('Widget' === ($items[0]['slots'][$nameSlot]['text'] ?? null) && '$65.00' === ($items[0]['slots'][$priceSlot]['text'] ?? null), "The item carries the product's name and price.");

// The card's add-to-cart control is bound the way every multi-card grid's is.
$products = array_column($declaration($plan, 'products')['payload']['entities'] ?? array(), null, 'slug');
$assert(1 === count($products) && 'Widget' === ($products['widget']['name'] ?? null) && '65.00' === ($products['widget']['regular_price'] ?? null), 'The products collection declares the one product: ' . json_encode(array_keys($products)));
$control = $products['widget']['bindings'][0] ?? array();
$assert('generic/block-binding/v1' === ($control['schema'] ?? null) && 'commerce_controls' === ($control['role'] ?? null) && str_contains((string) ($control['search_block_markup'] ?? ''), 'Add to Cart'), "The card's add-to-cart control carries a commerce_controls binding: " . json_encode($control));

$gridBinding = $grid['bindings'][0] ?? array();
$assert('commerce_product_grid' === ($gridBinding['role'] ?? null), 'The grid carries a commerce_product_grid binding.');
$pageMarkup = (string) (current(array_filter($plan['pages'] ?? array(), static fn (array $compiledPage): bool => 'article.html' === ($compiledPage['source_path'] ?? null)))['canonical_block_markup'] ?? '');
$search = (string) ($gridBinding['search_block_markup'] ?? '');
$assert('' !== $search && 1 === substr_count($pageMarkup, $search) && str_contains($search, 'Widget'), 'The grid anchor appears exactly once in the page markup.');

// Schema.org Product evidence qualifies the lone card without any control.
$schemaCard = '<div class="card" itemscope itemtype="https://schema.org/Product">'
    . '<a class="card__link" href="/products/widget"><img src="/media/widget.png" alt="Widget"><div class="card-body"><h3 itemprop="name">Widget</h3><div class="price" itemprop="price">$65.00</div></div></a>'
    . '</div>';
$schemaGrid = $declaration($compile($page($schemaCard))['source_reports']['wordpress_site_plan'] ?? array(), 'product_grids')['payload']['entities'][0] ?? array();
$assert(1 === count($schemaGrid['items'] ?? array()) && 'generic/collection-item-template/v1' === ($schemaGrid['item_template']['schema'] ?? null), 'A lone schema.org Product card is a one-product grid with an item template: ' . json_encode($schemaGrid));

// Control case: a lone image-only card (no cart control, no schema) yields no
// grid finding — the image-corroborated path keeps its repetition thresholds.
$loneCard = '<div class="card">'
    . '<a class="card__link" href="/products/widget"><img src="/media/widget.png" alt="Widget"><div class="card-body"><h3>Widget</h3><div class="price">$65.00</div></div></a>'
    . '</div>';
$controlPage = $page($loneCard);
$controlPlan = $compile($controlPage)['source_reports']['wordpress_site_plan'] ?? array();
$assert(null === $declaration($controlPlan, 'product_grids') && null === $declaration($controlPlan, 'products'), 'A lone image-only card declares no grid and no products: ' . json_encode(array_column($controlPlan['runtime_declarations'] ?? array(), 'type')));
$controlFindings = array_column((new HtmlTransformer())->transform($controlPage, array())->fallbacks, 'diagnostic_code');
$assert(! in_array('html_product_grid_fallback', $controlFindings, true), 'A lone image-only card yields no grid finding: ' . json_encode($controlFindings));

echo "product-grid-single-card: ok\n";
