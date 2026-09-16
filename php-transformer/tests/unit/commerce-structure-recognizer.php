<?php
declare(strict_types=1);

/**
 * Unit coverage for generic commerce-structure recognition (#1759).
 *
 * Constructed with `new CommerceStructureRecognizer()` — no HtmlCompilation.
 * Detection is schema.org / currency / structural triad, never a shop-provider
 * name or fixture-specific class string.
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Diagnostics\CommerceFallbackReporter;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlCompilation;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Patterns\CommerceStructureRecognizer;

$failures = 0;
$passes   = 0;

$assert = static function (bool $condition, string $message) use (&$failures, &$passes): void {
    if ( $condition ) {
        ++$passes;
        return;
    }

    ++$failures;
    fwrite(STDERR, 'FAIL: ' . $message . PHP_EOL);
};

$element = static function (string $html): DOMElement {
    $doc = new DOMDocument();
    libxml_use_internal_errors(true);
    $doc->loadHTML(
        '<!DOCTYPE html><html><body>' . $html . '</body></html>',
        LIBXML_HTML_NODEFDTD
    );
    libxml_clear_errors();
    $body = $doc->getElementsByTagName('body')->item(0);
    if ( ! $body instanceof DOMElement || ! $body->firstElementChild instanceof DOMElement ) {
        throw new RuntimeException('Unable to parse fixture element.');
    }

    return $body->firstElementChild;
};

$recognizer = new CommerceStructureRecognizer();

$assert($recognizer->looksLikePriceText('$24'), 'currency-symbol price text');
$assert($recognizer->looksLikePriceText('19.99 USD'), 'iso-code price text');
$assert(! $recognizer->looksLikePriceText('Desk Lamp'), 'plain product name is not a price');
$assert(! $recognizer->looksLikePriceText('24 hours'), 'bare number without currency is not a price');

$assert($recognizer->isProductGridContainer($element('<ul class="items"></ul>')), 'unordered list is a product-grid container');
$assert($recognizer->isProductGridContainer($element('<ol></ol>')), 'ordered list is a product-grid container');
$assert($recognizer->isProductGridContainer($element('<div class="product-grid"></div>')), 'grid token on a class is a product-grid container');
$assert($recognizer->isProductGridContainer($element('<section style="display:grid"></section>')), 'display:grid style is a product-grid container');
$assert(! $recognizer->isProductGridContainer($element('<div class="hero"></div>')), 'a non-grid non-list container is not a product grid');

$schemaCard = $element(
    '<li itemscope itemtype="https://schema.org/Product"><h3 itemprop="name">Desk Lamp</h3><span itemprop="price">$48</span></li>'
);
$schemaProduct = $recognizer->productCardData($schemaCard);
$assert(is_array($schemaProduct) && 'Desk Lamp' === ($schemaProduct['name'] ?? null), 'schema.org Product microdata qualifies a card');
$assert('$48' === ($schemaProduct['price'] ?? null), 'schema.org price is extracted');

$triadCard = $element(
    '<article><h3>Tour Tee</h3><div class="price">$30</div><button class="add-to-cart">Add to cart</button></article>'
);
$triadProduct = $recognizer->productCardData($triadCard);
$assert(is_array($triadProduct) && 'Tour Tee' === ($triadProduct['name'] ?? null), 'name + price + cart control qualifies a card');
$assert(true === ($triadProduct['has_cart_control'] ?? null), 'cart control is recorded on the triad card');

$servicesCard = $element(
    '<div class="service card"><h3>Consulting</h3><p>We help you grow.</p><a href="/contact">Learn more</a></div>'
);
$assert(null === $recognizer->productCardData($servicesCard), 'a services card without price or cart control is not a product');

$priceOnly = $element(
    '<div class="tier card"><h3>Basic</h3><span class="price">$9/mo</span><a href="/signup">Get started</a></div>'
);
$assert(null === $recognizer->productCardData($priceOnly), 'a price-only plan card without cart control is not a product');

$wooNamed = $element(
    '<div class="woocommerce-product"><h3>Woo Tee</h3><span>$12</span></div>'
);
$assert(null === $recognizer->productCardData($wooNamed), 'a shop-provider class name without schema.org or triad is not a product');

$grid = $element(
    '<ul class="product-grid">'
    . '<li itemscope itemtype="https://schema.org/Product"><h3 itemprop="name">Desk Lamp</h3><span itemprop="price">$48</span><a class="add-to-cart" href="/cart">Add to cart</a></li>'
    . '<li itemscope itemtype="https://schema.org/Product"><h3 itemprop="name">Wall Clock</h3><span itemprop="price">$29</span><button class="buy-now">Buy now</button></li>'
    . '</ul>'
);
$cards = $recognizer->productCardsForContainer($grid);
$assert(2 === count($cards), 'two schema.org product children are extracted');
$assert('Desk Lamp' === ($cards[0]['name'] ?? null) && 'Wall Clock' === ($cards[1]['name'] ?? null), 'product names stay generic schema.org evidence');
$controls = $recognizer->commerceControlGroupsForContainer($grid);
$assert(2 === count($controls), 'cart controls are grouped per qualifying card');
$assert('commerce_cart_runtime' === ($controls[0]['runtime_requirement'] ?? null), 'control groups require a generic cart runtime');

$compilation = new ReflectionClass(HtmlCompilation::class);
$assert(! $compilation->hasMethod('looksLikePriceText'), 'looksLikePriceText is not a method on HtmlCompilation');
$assert(! $compilation->hasMethod('isProductGridContainer'), 'isProductGridContainer is not a method on HtmlCompilation');
$assert(! $compilation->hasMethod('appendProductGridFallbacks'), 'appendProductGridFallbacks is not a method on HtmlCompilation');
$assert(! $compilation->hasMethod('appendCommerceControlsFallbacks'), 'appendCommerceControlsFallbacks is not a method on HtmlCompilation');

$recognizerSource = (string) file_get_contents((new ReflectionClass(CommerceStructureRecognizer::class))->getFileName());
$assert(! str_contains($recognizerSource, 'HtmlCompilation'), 'CommerceStructureRecognizer has no HtmlCompilation reference');
$reporterSource = (string) file_get_contents((new ReflectionClass(CommerceFallbackReporter::class))->getFileName());
$assert(! str_contains($reporterSource, 'HtmlCompilation'), 'CommerceFallbackReporter has no HtmlCompilation reference');

if ( $failures ) {
    fwrite(STDERR, $failures . " commerce structure recognizer test(s) failed\n");
    exit(1);
}

echo 'Commerce structure recognizer tests: ' . $passes . " passed\n";
