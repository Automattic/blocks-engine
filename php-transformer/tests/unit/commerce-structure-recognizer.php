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

// A browse/marketplace catalog card: no schema.org markup and no add-to-cart
// control (the purchase action lives on a detail page), but a name, a
// one-time price, and a per-card photo. This is the pattern real captured
// marketplace grids use (see blocks-engine issue tracking browse-capture
// evidence): the structural quad qualifies it without a cart control.
$catalogImageCard = $element(
    '<div class="card"><img src="/media/ring.jpg" alt="Solaris Ring"><h3>Solaris Ring</h3><span class="price">$64.99</span></div>'
);
$catalogImageProduct = $recognizer->productCardData($catalogImageCard);
$assert(is_array($catalogImageProduct) && 'Solaris Ring' === ($catalogImageProduct['name'] ?? null), 'name + one-time price + per-card image qualifies a product without a cart control');
$assert('$64.99' === ($catalogImageProduct['price'] ?? null), 'catalog-image card price is extracted');
$assert(true !== ($catalogImageProduct['has_cart_control'] ?? null), 'catalog-image card records no cart control');
$assert('/media/ring.jpg' === ($catalogImageProduct['image']['src'] ?? null), 'catalog-image card retains its image');

// A recurring/subscription price disqualifies the image-corroborated path
// even when a photo is present: a plan card that happens to carry a photo is
// still a plan, not a one-time-purchase item.
$recurringImageCard = $element(
    '<div class="tier card"><img src="/img/pro-plan.jpg" alt="Pro plan"><h3>Pro</h3><span class="price">$29/mo</span><a href="/signup">Get started</a></div>'
);
$assert(null === $recognizer->productCardData($recurringImageCard), 'a recurring-price plan card is not a product even with a photo');

$recurringImageCardVariant = $element(
    '<div class="tier card"><img src="/img/pro-plan.jpg" alt="Pro plan"><h3>Pro</h3><span class="price">$29</span><span class="billing">Billed monthly</span><a href="/signup">Get started</a></div>'
);
$assert(null === $recognizer->productCardData($recurringImageCardVariant), 'a "billed monthly" plan card is not a product even with a photo');

// A blog post card (photo + heading + excerpt, no price at all) is never a
// product regardless of the image-corroborated path, since a price is
// required before any qualification path is considered.
$blogPostCard = $element(
    '<article class="post-card"><img src="/img/post.jpg" alt="Post thumbnail"><h3>How We Shipped It</h3><p>A behind-the-scenes look at the release.</p></article>'
);
$assert(null === $recognizer->productCardData($blogPostCard), 'a blog post card without a price is not a product');

// A feature card uses an inline icon, not a photographic <img>, so it never
// qualifies through the image-corroborated path even if it somehow carried
// price-like text.
$featureCard = $element(
    '<div class="feature card"><svg viewBox="0 0 10 10"><circle cx="5" cy="5" r="4"></circle></svg><h3>Fast</h3><p>Blazing speed, $0 setup.</p></div>'
);
$assert(null === $recognizer->productCardData($featureCard), 'a feature card with an inline icon (no <img>) is not a product');

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

// Storefront structure: an unstyled carousel track of product cards is a grid;
// the same cards in a search dialog or menu drawer are transient interface; and
// a section that wraps a product list is not itself one product.
$card = static fn (string $name, string $price): string => '<x-slide><a href="/p"><img src="/m.png" alt="' . $name . '"></a><h3>' . $name . '</h3><span>' . $price . '</span></x-slide>';
$track = '<x-track>' . $card('Mug', '$12.00') . $card('Cup', '$9.00') . $card('Bowl', '$15.00') . '</x-track>';
$assert($recognizer->isProductGridContainer($element($track)), 'an unstyled track repeating one item is a grid container');
$assert(3 === count($recognizer->productCardsForContainer($element($track))), 'each repeated item is a product card');
$dialog = $element('<dialog open><div class="results">' . $track . '</div></dialog>');
$assert(! $recognizer->isProductGridContainer($dialog->getElementsByTagName('x-track')->item(0)), 'a product list inside a dialog is transient interface');
$drawer = $element('<details><summary>Menu</summary><ul><li>' . $card('Mug', '$12.00') . '</li><li>' . $card('Cup', '$9.00') . '</li></ul></details>');
$assert(! $recognizer->isProductGridContainer($drawer->getElementsByTagName('ul')->item(0)), 'a product list inside a disclosure drawer is transient interface');
$region = $element('<div><h2>Featured products</h2>' . $track . '</div>');
$assert(null === $recognizer->productCardData($region), 'a section wrapping a product list is not itself a product');

if ( $failures ) {
    fwrite(STDERR, $failures . " commerce structure recognizer test(s) failed\n");
    exit(1);
}

echo 'Commerce structure recognizer tests: ' . $passes . " passed\n";
