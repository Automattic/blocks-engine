<?php
declare(strict_types=1);

/** Regression for product grids swallowed by generated custom-element hosts (#3209). */
require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

$failures = 0;
$assert = static function (bool $ok, string $message) use (&$failures): void {
    if ($ok) return;
    ++$failures;
    fwrite(STDERR, 'FAIL: ' . $message . PHP_EOL);
};
$compile = static function (string $content): array {
    $html = '<!doctype html><html><head><meta charset="UTF-8"></head><body><main><h1>Catalog</h1><section>'
        . $content . '</section></main></body></html>';
    return (new ArtifactCompiler())->compile(['entrypoint' => 'index.html', 'files' => ['index.html' => $html]])->toArray();
};
$cards = '';
foreach (['Alpha', 'Beta', 'Gamma', 'Delta'] as $name) {
    $cards .= '<li><a href="/products/' . strtolower($name) . '"><img src="' . strtolower($name)
        . '.jpg" alt=""><h3>' . $name . '</h3></a><span class="price">$10.00</span></li>';
}
$grid = '<ul class="grid">' . $cards . '</ul>';
$header = '<div class="related-header"><h2>You may also like</h2></div>';

// Also prove that neither the host name nor any class is part of the rule.
foreach ([
    'issue host' => '<related-items class="related">' . $header . $grid . '</related-items>',
    'neutral host' => '<catalog-panel>' . $header . str_replace(' class="grid"', '', $grid) . '</catalog-panel>',
    'ordinary container' => '<div>' . $header . $grid . '</div>',
] as $label => $content) {
    $result = $compile($content);
    $plan = $result['source_reports']['wordpress_site_plan'] ?? [];
    $findings = array_values(array_filter($result['fallbacks'] ?? [], static fn (array $row): bool => 'html_product_grid_fallback' === ($row['diagnostic_code'] ?? null)));
    $assert(1 === count($findings), $label . ': exactly one product-grid finding.');
    $assert(4 === ($findings[0]['product_count'] ?? null), $label . ': all four cards are recognized.');
    $assert(!in_array('runtime_product_grid_binding_declined', array_column($result['diagnostics'] ?? [], 'code'), true), $label . ': no declined grid binding.');
    $assert('failed' !== ($result['status'] ?? 'failed'), $label . ': compilation succeeds.');
    $canonical = $plan['pages'][0]['canonical_block_markup'] ?? '';
    $products = [];
    $grids = [];
    foreach ($plan['runtime_declarations'] ?? [] as $declaration) {
        if ('products' === ($declaration['type'] ?? null)) $products = $declaration['payload']['entities'] ?? [];
        if ('product_grids' === ($declaration['type'] ?? null)) $grids = $declaration['payload']['entities'] ?? [];
    }
    $assert(4 === count($products), $label . ': four products are declared.');
    $assert(1 === count($grids), $label . ': the grid is declared.');
    $gridBinding = $grids[0]['bindings'][0] ?? [];
    $gridSearch = $gridBinding['search_block_markup'] ?? '';
    $assert('commerce_product_grid' === ($gridBinding['role'] ?? null), $label . ': declared grid has its own binding.');
    $assert('' !== $gridSearch && 1 === substr_count($canonical, $gridSearch), $label . ': declared grid anchor resolves uniquely.');
    foreach ($products as $product) {
        $binding = $product['bindings'][0] ?? [];
        $search = $binding['search_block_markup'] ?? '';
        $assert('commerce_collection' === ($binding['role'] ?? null), $label . ': product has a collection binding.');
        $assert('' !== $search && 1 === substr_count($canonical, $search), $label . ': collection anchor resolves uniquely.');
        $position = $binding['position'] ?? [];
        $assert('' !== $search && $search === substr($canonical, (int) ($position['offset'] ?? 0), (int) ($position['length'] ?? 0)), $label . ': binding position resolves exactly.');
        $assert(str_starts_with($search, '<!-- wp:group '), $label . ': collection emits its own layout block.');
    }
}

$control = $compile('<catalog-panel>' . $header . str_replace('<span class="price">$10.00</span>', '', $grid) . '</catalog-panel>');
$assert(str_contains($control['serialized_blocks'] ?? '', '<!-- wp:custom/'), 'A safe custom-element host without a product grid still generates its component.');
$assert(!in_array('html_product_grid_fallback', array_column($control['fallbacks'] ?? [], 'diagnostic_code'), true), 'The non-commerce control has no product-grid finding.');

if ($failures) exit(1);
echo "product-grid-custom-element-host: ok\n";
