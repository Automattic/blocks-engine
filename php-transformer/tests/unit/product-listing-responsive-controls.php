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
$assert(array('price:min', 'price:max', 'price:min', 'price:max') === array_column($recognized['controls'], 'key'), 'Both placements of each price bound survive recognition.');
$assert(array('mobile', 'mobile', 'desktop', 'desktop') === array_map(static fn (array $control): string => $control['element']->parentNode->getAttribute('id'), $recognized['controls']), 'The sidebar determines the region without discarding modal placements.');
$result = (new ArtifactCompiler())->compile(array('entrypoint' => 'catalog.html', 'files' => array('catalog.html' => $html)))->toArray();
$plan = $result['source_reports']['wordpress_site_plan'] ?? array();
$listings = array_values(array_filter($plan['runtime_declarations'] ?? array(), static fn (array $declaration): bool => 'product_listings' === ($declaration['type'] ?? null)));
$listing = $listings[0]['payload']['entities'][0] ?? array();
$binding = $listing['bindings'][0] ?? array();
$markup = $binding['search_block_markup'] ?? '';
$assert('commerce_listing' === ($binding['role'] ?? null) && '' !== $markup, 'Listing has a region binding.');
$assert(str_contains($markup, 'Alpha') && str_contains($markup, 'desktop') && ! str_contains($markup, 'mobile') && ! str_contains($markup, 'Site header') && ! str_contains($markup, 'Site footer'), 'commerce_listing binds the sidebar/grid region markup, not the page.');
$controls = $listing['controls'] ?? array();
$assert(array('range:price:min', 'range:price:max') === array_column($controls, 'id'), 'Each logical price bound is declared once.');
foreach ($controls as $control) {
    $bindings = array_values(array_filter($listing['bindings'] ?? array(), static fn (array $binding): bool => $control['id'] === ($binding['control'] ?? null)));
    $assert(2 === count($bindings), 'Every price bound has both responsive bindings.');
    $assert(array(1, 2) === array_column($bindings, 'occurrence'), 'Identical responsive markup retains distinct anchor occurrences.');
    $regionStart = $binding['position']['offset'];
    $regionEnd = $regionStart + $binding['position']['length'];
    $assert($bindings[0]['position']['offset'] < $regionStart, 'The modal placement is anchored outside the region.');
    $assert($bindings[1]['position']['offset'] >= $regionStart && $bindings[1]['position']['offset'] < $regionEnd, 'The sidebar placement is anchored inside the region.');
    foreach ($bindings as $placement) {
        $assert('commerce_listing_control' === $placement['role'], 'Every placement is a listing-control anchor.');
    }
}
$forms = array_filter($plan['runtime_declarations'] ?? array(), static fn (array $declaration): bool => 'forms' === ($declaration['type'] ?? null) && ! empty($declaration['payload']['entities']));
$assert(array() === $forms, 'Both listing-owned forms are claimed, including the modal outside the region.');
echo "product-listing-responsive-controls: ok\n";
