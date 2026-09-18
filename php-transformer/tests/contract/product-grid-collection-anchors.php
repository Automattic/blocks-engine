<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

/**
 * Contract coverage for a single document containing multiple distinct
 * product grids, each cart-control-less (so every product shares its grid
 * container's own `commerce_collection` anchor -- see
 * `CommerceFallbackReporter`). This is the specific gap blocks-engine#2024
 * shipped green without: it anchored a lone grid correctly but never proved
 * a page with more than one grid actually composes, so its anchor -- not
 * page-unique by construction -- reached `WordPressSitePlan` ambiguous and
 * destroyed the whole import (blocks-engine#2026, the revert). This file is
 * the "multi-grid" contract that gap needed.
 *
 * Every assertion here runs the *full* producer-to-plan pipeline
 * (`ArtifactCompiler` -> `WordPressSitePlan`), not just `HtmlTransformer`,
 * because the ambiguity blocks-engine#2026 measured only ever surfaced at
 * site-plan composition -- a single-page `HtmlTransformer::transform()` call
 * never exercises `WordPressSitePlan::canonicalEntityBindings()` at all.
 */

$assert = static function (bool $condition, string $message): void {
    if (! $condition) {
        throw new RuntimeException($message);
    }
};

/** @return array<string,mixed> */
function productGridCollectionAnchorsPlan(string $html): array
{
    $result = (new ArtifactCompiler())->compile(array(
        'entrypoint' => 'index.html',
        'files' => array('index.html' => $html),
    ))->toArray();
    return $result;
}

function productGridCollectionAnchorsCard(string $name, string $price, string $image): string
{
    return '<div class="card"><img src="https://cdn.example.test/img/' . $image . '.jpg" alt="' . $name . '"><h3>' . $name . '</h3><span class="price">$' . $price . '</span></div>';
}

// ---------------------------------------------------------------------
// Case A: two genuinely DISTINCT product grids on one page (different
// products, different card content) -- the "homepage grid + catalog grid"
// shape from the real capture that surfaced blocks-engine#2026, minus the
// desktop/mobile duplication. Every product in each grid must resolve to a
// unique, page-owned anchor, and each grid's anchor must be provably
// distinct from the other grid's.
// ---------------------------------------------------------------------

$gridA = '<div class="grid featured">'
    . productGridCollectionAnchorsCard('Alpha Item', '10.00', 'alpha')
    . productGridCollectionAnchorsCard('Beta Item', '11.00', 'beta')
    . productGridCollectionAnchorsCard('Gamma Item', '12.00', 'gamma')
    . productGridCollectionAnchorsCard('Delta Item', '13.00', 'delta')
    . '</div>';
$gridB = '<div class="grid catalog">'
    . productGridCollectionAnchorsCard('Echo Item', '20.00', 'echo')
    . productGridCollectionAnchorsCard('Foxtrot Item', '21.00', 'foxtrot')
    . productGridCollectionAnchorsCard('Golf Item', '22.00', 'golf')
    . productGridCollectionAnchorsCard('Hotel Item', '23.00', 'hotel')
    . '</div>';
$distinctGridsResult = productGridCollectionAnchorsPlan('<main><h1>Featured</h1>' . $gridA . '<h1>Catalog</h1>' . $gridB . '</main>');

$assert(
    'failed' !== $distinctGridsResult['status'] && ! isset($distinctGridsResult['source_reports']['wordpress_site_plan_diagnostics']),
    'A page with two distinct cart-control-less product grids composes cleanly: ' . json_encode($distinctGridsResult['source_reports']['wordpress_site_plan_diagnostics'] ?? array())
);
$distinctGridsPlan = $distinctGridsResult['source_reports']['wordpress_site_plan'] ?? array();
$assert(array() !== $distinctGridsPlan, 'Two distinct product grids still produce a WordPress site plan.');
$distinctGridsPage = current(array_filter($distinctGridsPlan['pages'] ?? array(), static fn (array $page): bool => 'index.html' === ($page['source_path'] ?? null)));
$distinctGridsCanonical = (string) ($distinctGridsPage['canonical_block_markup'] ?? '');
$distinctGridsProducts = current(array_filter($distinctGridsPlan['runtime_declarations'] ?? array(), static fn (array $declaration): bool => 'products' === ($declaration['type'] ?? null)))['payload']['entities'] ?? array();
$assert(8 === count($distinctGridsProducts), 'Both grids contribute all of their products to the products entity collection.');

$distinctGridsBySlug = array();
foreach ($distinctGridsProducts as $entity) {
    $distinctGridsBySlug[$entity['slug']] = $entity['bindings'][0] ?? array();
}
foreach ($distinctGridsBySlug as $slug => $binding) {
    $assert('generic/block-binding/v1' === ($binding['schema'] ?? null) && 'commerce_collection' === ($binding['role'] ?? null) && 1 === ($binding['occurrence'] ?? null), "Product {$slug} carries a commerce_collection binding at occurrence 1.");
    $assert(
        1 === substr_count($distinctGridsCanonical, (string) ($binding['search_block_markup'] ?? '###')),
        "Product {$slug}'s binding search markup appears exactly once in the page's canonical block markup."
    );
}
$gridASearch = $distinctGridsBySlug['alpha-item']['search_block_markup'] ?? '';
$gridBSearch = $distinctGridsBySlug['echo-item']['search_block_markup'] ?? '';
$assert('' !== $gridASearch && '' !== $gridBSearch && $gridASearch !== $gridBSearch, 'The two grids resolve to two distinct anchors, not the same one.');
foreach (array('alpha-item', 'beta-item', 'gamma-item', 'delta-item') as $slug) {
    $assert($gridASearch === ($distinctGridsBySlug[$slug]['search_block_markup'] ?? null), "Every product in grid A shares grid A's anchor ({$slug}).");
}
foreach (array('echo-item', 'foxtrot-item', 'golf-item', 'hotel-item') as $slug) {
    $assert($gridBSearch === ($distinctGridsBySlug[$slug]['search_block_markup'] ?? null), "Every product in grid B shares grid B's anchor ({$slug}).");
}
$distinctGridsBlockIndexes = array($distinctGridsBySlug['alpha-item']['position']['block_index'] ?? null, $distinctGridsBySlug['echo-item']['position']['block_index'] ?? null);
$assert(count(array_unique($distinctGridsBlockIndexes)) === 2, 'The two grids resolve to two distinct emitted block positions.');

// ---------------------------------------------------------------------
// Case B: a grid whose card content is genuinely, byte-for-byte duplicated
// elsewhere on the same page (e.g. a repeated "featured" rail), plus a
// third, distinct grid. This is the exact shape that made blocks-engine#2024
// ambiguous: two `commerce_collection` claims share identical search markup.
// Composition must still succeed -- no
// `wordpress_site_plan_not_self_contained` -- with the duplicate pair
// disambiguated by occurrence (1 and 2), exactly like blocks-engine#718/#1975
// already disambiguates two identical forms on one page.
// ---------------------------------------------------------------------

$duplicatedGridsResult = productGridCollectionAnchorsPlan('<main><h1>Featured</h1>' . $gridA . '<h1>Featured (repeated)</h1>' . $gridA . '<h1>Catalog</h1>' . $gridB . '</main>');

$assert(
    'failed' !== $duplicatedGridsResult['status'] && ! isset($duplicatedGridsResult['source_reports']['wordpress_site_plan_diagnostics']),
    'A page with a byte-duplicated product grid still composes -- no ambiguous canonical source-page anchor: ' . json_encode($duplicatedGridsResult['source_reports']['wordpress_site_plan_diagnostics'] ?? array())
);
$duplicatedGridsPlan = $duplicatedGridsResult['source_reports']['wordpress_site_plan'] ?? array();
$assert(array() !== $duplicatedGridsPlan, 'A page with a byte-duplicated product grid still produces a WordPress site plan.');
$duplicatedGridsPage = current(array_filter($duplicatedGridsPlan['pages'] ?? array(), static fn (array $page): bool => 'index.html' === ($page['source_path'] ?? null)));
$duplicatedGridsCanonical = (string) ($duplicatedGridsPage['canonical_block_markup'] ?? '');
$duplicatedGridsProducts = current(array_filter($duplicatedGridsPlan['runtime_declarations'] ?? array(), static fn (array $declaration): bool => 'products' === ($declaration['type'] ?? null)))['payload']['entities'] ?? array();

// The two duplicated grids share every product name/slug, so the compiler's
// own product dedup (ArtifactCompiler::runtimeDeclarationsFromFallbacks())
// folds them into one entity per product carrying both grids' bindings --
// proving each grid's own claim resolved independently rather than one
// clobbering the other.
$duplicatedGridBindings = current(array_filter($duplicatedGridsProducts, static fn (array $entity): bool => 'alpha-item' === ($entity['slug'] ?? null)))['bindings'] ?? array();
$assert(2 === count($duplicatedGridBindings), 'The duplicated product resolved two distinct grid-occurrence bindings, not one clobbering the other.');
$duplicatedGridOccurrences = array_map(static fn (array $binding): mixed => $binding['occurrence'] ?? null, $duplicatedGridBindings);
sort($duplicatedGridOccurrences);
$assert(array(1, 2) === $duplicatedGridOccurrences, 'The duplicated grid pair resolves to deterministic occurrence indexes 1 and 2, not an ambiguous shared claim.');
foreach ($duplicatedGridBindings as $binding) {
    $assert(
        'commerce_collection' === ($binding['role'] ?? null) && is_array($binding['position'] ?? null) && is_string($binding['search_block_markup'] ?? null),
        'Every duplicated-grid binding still carries a full, resolved position and search markup.'
    );
    $range = substr($duplicatedGridsCanonical, (int) $binding['position']['offset'], (int) $binding['position']['length']);
    $assert($range === $binding['search_block_markup'], 'Every duplicated-grid binding position resolves to its exact canonical block markup.');
}
$duplicatedGridBlockIndexes = array_map(static fn (array $binding): mixed => $binding['position']['block_index'] ?? null, $duplicatedGridBindings);
$assert(count(array_unique($duplicatedGridBlockIndexes)) === 2, 'The two duplicated-grid occurrences resolve to two distinct emitted blocks.');

// The unrelated third grid is completely unaffected by the duplicate pair.
$catalogBinding = current(array_filter($duplicatedGridsProducts, static fn (array $entity): bool => 'echo-item' === ($entity['slug'] ?? null)))['bindings'][0] ?? array();
$assert(1 === count(current(array_filter($duplicatedGridsProducts, static fn (array $entity): bool => 'echo-item' === ($entity['slug'] ?? null)))['bindings'] ?? array()), 'The unrelated third grid keeps exactly one binding per product.');
$assert(1 === ($catalogBinding['occurrence'] ?? null), 'The unrelated third grid is anchored at its own, unambiguous first occurrence.');

// ---------------------------------------------------------------------
// Case C: the same two distinct grids as Case A, but on two SEPARATE pages
// (the "homepage grid + catalog grid on a different page" shape) --
// composition must succeed across pages exactly as it does within one page.
// ---------------------------------------------------------------------

$multiPageResult = (new ArtifactCompiler())->compile(array(
    'entrypoint' => 'index.html',
    'files' => array(
        'index.html' => '<main><h1>Featured</h1>' . $gridA . '</main>',
        'catalog.html' => '<main><h1>Catalog</h1>' . $gridB . '</main>',
    ),
))->toArray();
$assert(
    'failed' !== $multiPageResult['status'] && ! isset($multiPageResult['source_reports']['wordpress_site_plan_diagnostics']),
    'Two distinct product grids on two separate pages compose cleanly: ' . json_encode($multiPageResult['source_reports']['wordpress_site_plan_diagnostics'] ?? array())
);
$multiPagePlan = $multiPageResult['source_reports']['wordpress_site_plan'] ?? array();
$assert(2 === count($multiPagePlan['pages'] ?? array()), 'Both pages materialize in the site plan.');
$multiPageProducts = current(array_filter($multiPagePlan['runtime_declarations'] ?? array(), static fn (array $declaration): bool => 'products' === ($declaration['type'] ?? null)))['payload']['entities'] ?? array();
$multiPageAlpha = current(array_filter($multiPageProducts, static fn (array $entity): bool => 'alpha-item' === ($entity['slug'] ?? null)))['bindings'][0] ?? array();
$multiPageEcho = current(array_filter($multiPageProducts, static fn (array $entity): bool => 'echo-item' === ($entity['slug'] ?? null)))['bindings'][0] ?? array();
$assert('index.html' === ($multiPageAlpha['source_path'] ?? null) && 'catalog.html' === ($multiPageEcho['source_path'] ?? null), 'Each page-owned grid anchors on its own page, not the other page.');

fwrite(STDOUT, "product-grid-collection-anchors contract passed\n");
