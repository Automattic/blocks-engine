<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Patterns\ListingControlsRecognizer;

$assert = static function (bool $condition, string $message): void {
    if (! $condition) throw new RuntimeException($message);
};
$cards = '';
foreach (array('Alpha', 'Beta', 'Gamma') as $name) {
    $cards .= '<article><a href="/products/' . $name . '"><img src="' . $name . '.jpg" alt="' . $name . '"></a><h2><a href="/products/' . $name . '">' . $name . '</a></h2><p>$30.00</p><form method="post" action="/cart/add"><input type="hidden" name="id" value="' . $name . '"><button>Add to cart</button></form></article>';
}
$form = static fn (string $id): string => '<form id="' . $id . '"><input type="number" name="min_price" aria-label="Minimum price"><input type="number" name="max_price" aria-label="Maximum price"><button>Apply</button></form>';
$html = '<!doctype html><html><head><title>Catalog</title></head><body><div class="document"><header>Site header</header><section class="modal">' . $form('mobile') . '</section><section class="category-body"><div class="row" style="display:flex"><div class="sidebar">' . $form('desktop') . '</div><div id="grid" style="display:grid;gap:20px">' . $cards . '</div></div></section><footer>Site footer</footer></div></body></html>';
$document = new DOMDocument();
$document->loadHTML($html, LIBXML_NOERROR | LIBXML_NOWARNING);
$grid = $document->getElementById('grid');
$cardElements = iterator_to_array($grid->getElementsByTagName('article'), false);
$recognized = (new ListingControlsRecognizer())->recognize($grid, $cardElements, array(30.0, 30.0, 30.0));
$assert('row' === $recognized['region']->getAttribute('class'), 'Region is the row beside the sidebar, not the document wrapper.');
$assert(0 === $recognized['region']->getElementsByTagName('header')->length && 0 === $recognized['region']->getElementsByTagName('footer')->length, 'Region excludes header and footer.');
$assert(array('price:min', 'price:max') === array_column($recognized['controls'], 'key'), 'Responsive duplicates are dropped.');
foreach ($recognized['controls'] as $control) {
    $assert('desktop' === $control['element']->parentNode->getAttribute('id'), 'Claimed bounds belong to the sidebar.');
}
$result = (new ArtifactCompiler())->compile(array('entrypoint' => 'catalog.html', 'files' => array('catalog.html' => $html)))->toArray();
$plan = $result['source_reports']['wordpress_site_plan'] ?? array();
$listings = array_values(array_filter($plan['runtime_declarations'] ?? array(), static fn (array $declaration): bool => 'product_listings' === ($declaration['type'] ?? null)));
$listing = $listings[0]['payload']['entities'][0] ?? array();
$binding = $listing['bindings'][0] ?? array();
$markup = $binding['search_block_markup'] ?? '';
$assert('commerce_listing' === ($binding['role'] ?? null) && '' !== $markup, 'Listing has a region binding.');
$assert(str_contains($markup, 'Alpha') && str_contains($markup, 'desktop') && ! str_contains($markup, 'mobile') && ! str_contains($markup, 'Site header') && ! str_contains($markup, 'Site footer'), 'commerce_listing binds the sidebar/grid region markup, not the page.');
foreach ($listing['controls'] ?? array() as $control) {
    $assert('range' === $control['kind'] && str_contains($control['selector'], 'section:nth-of-type(2)'), 'Published range selectors point to the sidebar.');
}
echo "product-listing-responsive-controls: ok\n";
