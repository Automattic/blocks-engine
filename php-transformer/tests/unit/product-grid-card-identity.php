<?php
declare(strict_types=1);

/** Regression for product-card identity and facet-drawer rejection (#3185). */
require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Patterns\CommerceStructureRecognizer;

$failures = 0;
$assert = static function (bool $ok, string $message) use (&$failures): void {
    if ($ok) return;
    ++$failures;
    fwrite(STDERR, 'FAIL: ' . $message . PHP_EOL);
};
$element = static function (string $html): DOMElement {
    $doc = new DOMDocument();
    $previous = libxml_use_internal_errors(true);
    $doc->loadHTML('<!doctype html><html><head><meta charset="UTF-8"></head><body>' . $html . '</body></html>');
    libxml_clear_errors();
    libxml_use_internal_errors($previous);
    return $doc->getElementsByTagName('body')->item(0)->firstElementChild;
};

$card = <<<'HTML'
<ul class="grid">
  <li class="columns"><product-card class="product-card">
    <figure class="product-card--featured-image">
      <span class="product-card--badges"><span class="badge">Sale</span></span>
      <a href="/products/pantalon-fausti-marron" title="Pantalón Fausti Marrón"><img src="a.jpg" alt="Pantalón Fausti Marrón"></a>
      <quick-view class="product-card-quickview" data-product-handle="pantalon-fausti-marron"><span>Vista rápida</span></quick-view>
    </figure>
    <div class="product-card-info">
      <a href="/products/pantalon-fausti-marron" title="Pantalón Fausti Marrón" class="product-card-title">Pantalón Fausti Marrón</a>
      <span class="price"><del><span class="amount"><span class="money">$120,900</span></span></del>
        <ins><span class="amount"><span class="money">$48,360</span></span></ins></span>
      <span class="cuotas"> · 6 cuotas sin interés de <span class="money">$8,060</span></span>
    </div>
  </product-card></li>
</ul>
HTML;
$drawer = <<<'HTML'
<div class="side-panel facet-drawer" id="Facet-Drawer" inert="">
  <div class="side-panel-header"><div>
    <h4>FILTRAR <span class="filter-count"><span class="facets__label">0 productos</span></span></h4>
  </div></div>
  <div class="side-panel-content"><form><select name="sort_by">
    <option value="price-ascending">Precio, menor a mayor</option>
  </select></form></div>
  <div class="side-panel-footer"><a href="/collections/x">Borrar</a><button class="button"><span>Aplicar</span></button></div>
</div>
HTML;

$recognizer = new CommerceStructureRecognizer();
$products = $recognizer->productCardsForContainer($element($card));
$assert(1 === count($products), 'Reduced card yields exactly one product.');
$assert('Pantalón Fausti Marrón' === ($products[0]['name'] ?? null), 'Name is the title link, not the image or card wrapper.');
$assert(!$recognizer->looksLikePriceText('0 productos'), 'A product count is not a price.');
$assert(null === $recognizer->productCardData($element($drawer)), 'Facet drawer yields no product.');
foreach ($element($drawer)->getElementsByTagName('*') as $candidate) {
    $assert(null === $recognizer->productCardData($candidate), 'No facet-drawer descendant is a product.');
}

$assert('pantalon-fausti-marron' === ($products[0]['slug'] ?? null), 'The title link supplies the product URL slug.');
$namedCard = static fn (string $title): DOMElement => $element('<article>' . $title . '<span class="price">$10</span><button>Add to cart</button></article>');
$assert('Schema title' === ($recognizer->productCardData($namedCard('<h3>Heading</h3><span itemprop="name">Schema title</span>'))['name'] ?? null), 'Schema name precedes a heading.');
$assert('Heading' === ($recognizer->productCardData($namedCard('<div class="title">Link title</div><h3>Heading</h3>'))['name'] ?? null), 'Heading precedes title tokens.');
$assert('Specific title' === ($recognizer->productCardData($namedCard('<div class="title">Extra <a class="name">Specific title</a></div>'))['name'] ?? null), 'The most specific name/title carrier wins.');
$assert('Safe title' === ($recognizer->productCardData($namedCard('<h3>Bad <span class="badge">Sale</span></h3><div class="title">Safe title</div>'))['name'] ?? null), 'A heading containing a badge is not the title.');
$assert(null === $recognizer->productCardData($namedCard('<div class="title">Bad <button>Quick view</button></div>')), 'A title wrapper containing controls is rejected.');
$assert(null === $recognizer->productCardData($element('<article itemscope itemtype="https://schema.org/Product"><h3>Filter</h3><span class="price">0 productos</span></article>')), 'Even a declared price must not turn a count into a price.');

// Exercise name-derived slugs too, rather than only the URL's ASCII spelling.
unset($products[0]['slug']);
// Exercise the owning fallback-to-runtime normalization, not a copied closure.
$runtime = new ReflectionMethod(ArtifactCompiler::class, 'runtimeDeclarationsFromFallbacks');
$declarations = $runtime->invoke(new ArtifactCompiler(), array(), array(array(
    'diagnostic_code' => 'html_product_grid_fallback',
    'products' => $products,
)), 'index.html', array());
$entities = array();
foreach ($declarations as $declaration) {
    if ('products' === ($declaration['type'] ?? null)) $entities = $declaration['payload']['entities'] ?? array();
}
$assert(1 === count($entities), 'Exactly one product is declared.');
$assert('Pantalón Fausti Marrón' === ($entities[0]['name'] ?? null), 'Runtime name is exact.');
$assert('pantalon-fausti-marron' === ($entities[0]['slug'] ?? null), 'Runtime slug transliterates accented Latin letters.');
$assert('120900' === ($entities[0]['regular_price'] ?? null), 'Regular price normalizes thousands separators.');
$assert('48360' === ($entities[0]['sale_price'] ?? null), 'Sale price is the inserted amount, not the installment.');

if ($failures) exit(1);
echo "product-grid-card-identity: ok\n";
