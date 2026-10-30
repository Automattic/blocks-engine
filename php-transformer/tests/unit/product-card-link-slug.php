<?php
declare(strict_types=1);

/** Regression for product-card slugs derived from directory-index and page-file links (#3316). */
require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

$assert = static function (bool $condition, string $message): void {
    if (! $condition) throw new RuntimeException($message);
};
$card = static fn (string $name, string $href): string => '<article><a href="' . $href . '"><img src="' . strtolower($name) . '.jpg" alt="' . $name . '"></a><h2><a href="' . $href . '">' . $name . '</a></h2><p>$20.00</p></article>';
$controls = '<form method="get"><label for="sort">Sort by</label><select id="sort" name="sort_by"><option value="featured">Featured</option><option value="price-ascending">Price: low to high</option></select></form>';
$links = array('Alpha' => 'products/alpha/index.html', 'Beta' => 'products/beta.html', 'Gamma' => 'products/gamma/');
$cards = '';
foreach ($links as $name => $href) {
    $cards .= $card($name, $href);
}
$html = '<!doctype html><html><head><title>Catalog</title></head><body><main><h1>Catalog</h1><section>' . $controls . '<div class="grid" style="display:grid">' . $cards . '</div></section></main></body></html>';
$result = (new ArtifactCompiler())->compile(array('entrypoint' => 'catalog.html', 'files' => array('catalog.html' => $html)))->toArray();
$assert('failed' !== $result['status'] && !isset($result['source_reports']['wordpress_site_plan_diagnostics']), 'Catalog composes cleanly: ' . json_encode($result['source_reports']['wordpress_site_plan_diagnostics'] ?? $result['status']));
$entities = array();
foreach ($result['source_reports']['wordpress_site_plan']['runtime_declarations'] ?? array() as $declaration) {
    if ('products' === ($declaration['type'] ?? null)) {
        $entities = array_merge($entities, $declaration['payload']['entities'] ?? array());
    }
}
$assert(3 === count($entities), 'Three product cards declare three products: ' . json_encode(array_column($entities, 'slug')));
$byName = array_column($entities, null, 'name');
foreach ($links as $name => $href) {
    $slug = $byName[$name]['slug'] ?? null;
    $assert(strtolower($name) === $slug, 'Card link ' . $href . ' names the product "' . strtolower($name) . '", got ' . var_export($slug, true) . '.');
}
$assert(array('alpha', 'beta', 'gamma') === array_column($entities, 'slug'), 'Product slugs are exactly alpha, beta and gamma: ' . json_encode(array_column($entities, 'slug')));
echo "product-card-link-slug: ok\n";
