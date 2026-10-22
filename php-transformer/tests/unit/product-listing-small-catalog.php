<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

$assert = static function (bool $condition, string $message): void {
    if (! $condition) throw new RuntimeException($message);
};
$controls = '<form method="get"><label for="sort">Sort by</label><select id="sort" name="sort_by"><option value="featured">Featured</option><option value="price-ascending">Price: low to high</option></select>'
    . '<fieldset><legend>Finish</legend><label><input type="checkbox" name="filter.finish" value="matte">Matte</label><label><input type="checkbox" name="filter.finish" value="gloss">Gloss</label></fieldset></form>';
$card = static fn (string $name, string $finish): string => '<article data-finish="' . $finish . '"><img src="' . strtolower($name) . '.jpg" alt="' . $name . '"><h2>' . $name . '</h2><p>$20.00</p></article>';
$compile = static function (string $content): array {
    return (new ArtifactCompiler())->compile(array('entrypoint' => 'catalog.html', 'files' => array('catalog.html' => '<!doctype html><html><head><title>Catalog</title></head><body><main><h1>Catalog</h1>' . $content . '</main></body></html>')))->toArray();
};
$entities = static function (array $result, string $type): array {
    foreach ($result['source_reports']['wordpress_site_plan']['runtime_declarations'] ?? array() as $declaration) {
        if ($type === ($declaration['type'] ?? null)) return $declaration['payload']['entities'] ?? array();
    }
    return array();
};

foreach (array(2, 0, 1, 3) as $count) {
    $cards = '';
    foreach (array_slice(array('Alpha', 'Beta', 'Gamma'), 0, $count) as $index => $name) {
        $cards .= $card($name, 0 === $index % 2 ? 'matte' : 'gloss');
    }
    $result = $compile('<section>' . $controls . (0 === $count ? '<div>No items available.</div>' : '<div class="grid" style="display:grid">' . $cards . '</div>') . '</section>');
    $assert('failed' !== $result['status'] && !isset($result['source_reports']['wordpress_site_plan_diagnostics']), 'Catalog composes cleanly: ' . json_encode($result['source_reports']['wordpress_site_plan_diagnostics'] ?? $result['status']));
    $grids = $entities($result, 'product_grids');
    $listings = $entities($result, 'product_listings');
    $assert(1 === count($grids) && $count === count($grids[0]['items']), 'Expected one grid with ' . $count . ' items: ' . json_encode($grids));
    $assert($count === count($entities($result, 'products')), 'Expected ' . $count . ' products.');
    $assert(1 === count($listings) && $listings[0]['grid_selector'] === $grids[0]['selector'], 'Expected one listing connected to its grid.');
    $assert(array('sort', 'facet') === array_column($listings[0]['controls'], 'kind'), 'Sort and checkbox facet survive: ' . json_encode($listings[0]['controls']));
    $assert('checkboxes' === $listings[0]['controls'][1]['shape'] && 'Finish' === $listings[0]['controls'][1]['label'], 'Checkbox facet shape and legend are retained.');
    $assert((0 === $count) === !isset($grids[0]['item_template']), 'Only nonempty grids have a repeatable card template.');
    $assert(3 === count($listings[0]['bindings']), 'Region and both controls have bindings.');
    foreach ($listings[0]['bindings'] as $binding) {
        $assert('' !== ($binding['search_block_markup'] ?? ''), 'Every listing binding has a page-owned block anchor.');
    }
}

$pricing = $compile('<section><div class="grid" style="display:grid">' . $card('Basic', 'matte') . $card('Plus', 'gloss') . $card('Premium', 'matte') . '</div></section>');
$assert(array() === $entities($pricing, 'product_grids') && array() === $entities($pricing, 'product_listings'), 'Three pricing cards without controls are not commerce.');
echo "product-listing-small-catalog: ok\n";
