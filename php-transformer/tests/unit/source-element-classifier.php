<?php
declare(strict_types=1);

/**
 * Unit tests for the standalone structural source-element vocabulary (#242).
 *
 * Plain-PHP test script in the style of tests/unit/subtree-classifier.php — no
 * PHPUnit.
 *
 * The point of these tests is the first line of the body: the classifier is
 * constructed with `new SourceElementClassifier()` and nothing else. Before the
 * extraction these predicates were private methods on an 11k-line transformer
 * and could only be exercised by transforming a whole document, so a wrong
 * answer surfaced as a wrong block somewhere downstream. Each case below now
 * pins one predicate to one fragment.
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Classification\SourceElementClassifier;

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

/** Build the first element child of an HTML fragment. */
$element = static function (string $html): DOMElement {
    $doc = new DOMDocument();
    libxml_use_internal_errors(true);
    $doc->loadHTML(
        '<!DOCTYPE html><html><body>' . $html . '</body></html>',
        LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD
    );
    libxml_clear_errors();
    $body = $doc->getElementsByTagName('body')->item(0);
    foreach ( $body->childNodes as $child ) {
        if ( $child instanceof DOMElement ) {
            return $child;
        }
    }

    throw new RuntimeException('fragment has no element child');
};

// No context, no collaborators, no transformer. That is the contract.
$classifier = new SourceElementClassifier();

// --- structure: repeated direct children -----------------------------------

$assert(
    $classifier->hasRepeatedDirectChildTags($element('<ul><li>a</li><li>b</li></ul>')),
    'repeated <li> children are recognized as repetition'
);
$assert(
    ! $classifier->hasRepeatedDirectChildTags($element('<div><h2>t</h2><p>b</p></div>')),
    'distinct child tags are not repetition'
);
$assert(
    ! $classifier->hasRepeatedDirectChildTags($element('<div><p>only one</p></div>')),
    'a single child is not repetition'
);
$assert(
    $classifier->hasRepeatedDirectChildTags($element('<div><span>a</span>text<span>b</span></div>')),
    'interleaved text nodes do not hide repeated element children'
);

// --- structure: direct vs descendant ---------------------------------------

$assert(
    $classifier->hasDirectChildElement($element('<figure><img src="a.png"></figure>'), 'img'),
    'a direct <img> child is found'
);
$assert(
    ! $classifier->hasDirectChildElement($element('<figure><div><img src="a.png"></div></figure>'), 'img'),
    'a nested <img> is not a direct child'
);

// --- media: responsive image sources ---------------------------------------

$assert(
    $classifier->hasResponsiveImageSources($element('<img src="a.png" srcset="a-2x.png 2x">')),
    'srcset on the element itself counts'
);
$assert(
    $classifier->hasResponsiveImageSources($element('<img src="a.png" sizes="100vw">')),
    'sizes alone counts as a responsive source'
);
$assert(
    $classifier->hasResponsiveImageSources($element('<div><img src="a.png" srcset="a-2x.png 2x"></div>')),
    'a descendant image carries responsive sources up'
);
$assert(
    ! $classifier->hasResponsiveImageSources($element('<img src="a.png">')),
    'a plain src is not a responsive source'
);
$assert(
    ! $classifier->hasResponsiveImageSources($element('<div><p>no media here</p></div>')),
    'a subtree without images has no responsive sources'
);

// --- media: image-carrier buttons -------------------------------------------
//
// core/button keeps its label as inline RichText, so an image or a block-level
// descendant cannot survive that lowering. These pin which buttons must route
// to a preserving container instead.

$assert(
    $classifier->isImageCarrierButton($element('<button><img src="i.png" alt="Search"></button>')),
    'an image button with no visible text is a carrier'
);
$assert(
    $classifier->isImageCarrierButton($element(
        '<button><div><img src="p.png" alt="Fjord Coffee"></div>'
        . '<div><h3>Fjord Coffee</h3><span>Logo</span></div>'
        . '<p>Brand identity</p></button>'
    )),
    'a media card button is a carrier even though it also has visible text'
);
$assert(
    ! $classifier->isImageCarrierButton($element('<button><img src="i.png" alt=""> Download</button>')),
    'an icon beside a phrasing label stays on the native control path'
);
$assert(
    ! $classifier->isImageCarrierButton($element('<button>All 06</button>')),
    'a text-only button is not a carrier'
);
$assert(
    ! $classifier->isImageCarrierButton($element(
        '<button type="submit"><div><img src="p.png" alt="Go"></div><p>Send</p></button>'
    )),
    'a submit control is never re-homed into a container'
);
$assert(
    ! $classifier->isImageCarrierButton($element('<button><div><span>Stacked</span></div></button>')),
    'block-level structure alone, with no image, is not an image carrier'
);

// --- identity: RichText-legal button labels ---------------------------------
//
// core/button can hold a phrasing label, including one flow wrapper around that
// label (a heading used for CTA type). Branching flow is a card, not a button.

$assert(
    $classifier->isRichTextButtonLabel($element('<button>Go</button>')),
    'a phrasing-only button is a RichText label'
);
$assert(
    $classifier->isRichTextButtonLabel($element('<button><div><span>Stacked</span></div></button>')),
    'a single layout wrapper around phrasing is still a RichText label'
);
$assert(
    $classifier->isRichTextButtonLabel($element('<a class="primary-button" href="/book"><h3>Reserve now</h3><span aria-hidden="true"></span></a>')),
    'a heading used as the sole visible CTA label is still a RichText label'
);
$assert(
    $classifier->isRichTextButtonLabel($element(
        '<button class="nested-control"><span>WhatsApp</span>'
        . '<div class="nested-icon"><div class="nested-icon-source">'
        . '<svg width="100%" height="100%" viewBox="0 0 77 77" aria-hidden="true"><path d="M0 0h77v77H0z"/></svg>'
        . '</div></div></button>'
    )),
    'phrasing text plus a nested icon wrapper is still a RichText label'
);
$assert(
    ! $classifier->isRichTextButtonLabel($element('<button><h3>Orangutan TT\'s</h3><p>70% Indica / 30% Sativa</p></button>')),
    'a heading plus a paragraph is not a RichText label'
);
$assert(
    ! $classifier->isRichTextButtonLabel($element(
        '<button><div><h3>Orangutan TT\'s</h3><p>70% Indica / 30% Sativa</p></div>'
        . '<div><p>Offers significant analgesic effects.</p></div></button>'
    )),
    'nested layout containers each holding their own content are not a RichText label'
);
$assert(
    ! $classifier->isRichTextButtonLabel($element('<a style="padding:10px;background:#fff" href="/x"><h3>Title</h3><p>Body copy</p></a>')),
    'a clickable card anchor wrapping a heading plus a paragraph is not a RichText label'
);

// --- identity: card-like ----------------------------------------------------

$assert(
    $classifier->isCardLikeElement($element('<article>post</article>')),
    '<article> is card-like by tag'
);
$assert(
    $classifier->isCardLikeElement($element('<div class="service-card">x</div>')),
    'a hyphen-delimited card token is card-like'
);
$assert(
    $classifier->isCardLikeElement($element('<div class="Feature Box">x</div>')),
    'card token matching is case-insensitive'
);
$assert(
    ! $classifier->isCardLikeElement($element('<div class="cardinal">x</div>')),
    'a token merely containing "card" is not card-like'
);
$assert(
    ! $classifier->isCardLikeElement($element('<div class="wrapper">x</div>')),
    'an unrelated class is not card-like'
);

// --- identity: visual layers ------------------------------------------------

$assert(
    $classifier->isVisualLayerElement($element('<svg class="decorative-wave-layer"></svg>')),
    'a semantic visual-layer class is recognized'
);
$assert(
    ! $classifier->isVisualLayerElement($element('<svg id="Layer_1"></svg>')),
    'a generic authoring-tool layer identifier is not visual-layer evidence by itself'
);
$assert(
    $classifier->isVisualLayerElement($element('<svg id="hero-layer"></svg>')),
    'a descriptive visual-layer identifier is recognized'
);

// --- values: positive CSS length -------------------------------------------

$assert($classifier->isPositiveCssLength('12px'), '12px is a positive length');
$assert($classifier->isPositiveCssLength('1.5rem'), 'fractional rem is a positive length');
$assert($classifier->isPositiveCssLength('  8vh  '), 'surrounding whitespace is tolerated');
$assert(! $classifier->isPositiveCssLength('0px'), 'zero is not a positive length');
$assert(! $classifier->isPositiveCssLength('-4px'), 'a negative length is not positive');
$assert(! $classifier->isPositiveCssLength('auto'), 'a keyword is not a length');
$assert(! $classifier->isPositiveCssLength('50%'), 'a percentage is not an absolute length');
$assert(! $classifier->isPositiveCssLength(''), 'an empty value is not a length');

// --- tokens: commerce vocabulary -------------------------------------------

$assert(
    $classifier->hasCommerceToken($element('<div class="product-price">9</div>'), array('price')),
    'a commerce token is found in a hyphenated class'
);
$assert(
    $classifier->hasCommerceToken($element('<div itemprop="price">9</div>'), array('price')),
    'a commerce token is found in itemprop'
);
$assert(
    ! $classifier->hasCommerceToken($element('<div class="pricing-table">9</div>'), array('price')),
    'a longer word containing the token does not match'
);

// --- image-only slideshow collections --------------------------------------

$slideshowHost = $element(
    '<div><div style="height:20px;overflow:hidden"></div><div id="photo-slideshow" class="photo-slideshow">'
    . '<div class="slides">'
    . '<div class="slide"><img src="one.jpg"></div>'
    . '<div class="slide" style="display:none"><img src="two.jpg"></div>'
    . '</div>'
    . '<div class="picker"><a><img src="one-t.jpg"></a><a><img src="two-t.jpg"></a></div>'
    . '</div></div>'
);
$stageImages = $classifier->imageSlideshowStageImages($slideshowHost);
$assert(
    2 === count($stageImages)
        && 'one.jpg' === $stageImages[0]->getAttribute('src')
        && 'two.jpg' === $stageImages[1]->getAttribute('src'),
    'a thin host around a stage and thumbnail pager yields the stage images'
);
$assert(
    $classifier->isImageOnlySlideshowCollection($slideshowHost),
    'the same host is an image-only slideshow collection'
);

$mixedSlide = $element(
    '<div class="photo-slideshow"><div class="slides">'
    . '<div class="slide"><h2>Quote</h2><p>Copy.</p><img src="one.jpg"></div>'
    . '<div class="slide" style="display:none"><h2>Two</h2><p>More.</p><img src="two.jpg"></div>'
    . '</div></div>'
);
$assert(
    array() === $classifier->imageSlideshowStageImages($mixedSlide),
    'slides that carry headings are not an image-only collection'
);

$videoSlide = $element(
    '<div class="photo-slideshow"><div class="slides">'
    . '<div class="slide"><video src="clip.mp4"></video></div>'
    . '<div class="slide" style="display:none"><img src="two.jpg"></div>'
    . '</div><div class="picker"><a><img src="one-t.jpg"></a><a><img src="two-t.jpg"></a></div></div>'
);
$assert(
    array() === $classifier->imageSlideshowStageImages($videoSlide),
    'a slideshow that mixes video is not an image-only collection'
);

$article = $element(
    '<article><p>Intro</p><div id="photo-slideshow" class="photo-slideshow"><div class="slides">'
    . '<div class="slide"><img src="one.jpg"></div>'
    . '<div class="slide" style="display:none"><img src="two.jpg"></div>'
    . '</div></div><p>Outro</p></article>'
);
$assert(
    array() === $classifier->imageSlideshowStageImages($article),
    'mixed siblings around a slideshow are not themselves the collection'
);

// --- phrasing content -------------------------------------------------------

$assert(
    $classifier->hasOnlyPhrasingChildren($element('<p>text <em>emphasis</em> more</p>')),
    'inline children are phrasing content'
);
$assert(
    ! $classifier->hasOnlyPhrasingChildren($element('<div><p>a block</p></div>')),
    'a block child is not phrasing content'
);

// --- ancestry ---------------------------------------------------------------

$outer = $element('<section><div><span id="leaf">x</span></div></section>');
$leaf  = $outer->getElementsByTagName('span')->item(0);
$assert($classifier->isDescendantOf($leaf, $outer), 'a nested element is a descendant');
$assert($classifier->isDescendantOf($outer, $outer), 'an element is its own ancestor for this predicate');

$sibling = $element('<aside>other</aside>');
$assert(! $classifier->isDescendantOf($leaf, $sibling), 'an unrelated element is not an ancestor');

// --- statelessness ----------------------------------------------------------

$card = $element('<article>post</article>');
$first = $classifier->isCardLikeElement($card);
$classifier->isPositiveCssLength('10px');
$classifier->hasRepeatedDirectChildTags($element('<ul><li>a</li><li>b</li></ul>'));
$assert(
    $first === $classifier->isCardLikeElement($card),
    'answers do not drift as the classifier is reused across elements'
);

$shared = new SourceElementClassifier();
$assert(
    $shared->isCardLikeElement($card) === $classifier->isCardLikeElement($card),
    'two independently constructed classifiers agree'
);

echo 'Source element classifier tests: ' . $passes . ' passed' . PHP_EOL;

if ( $failures > 0 ) {
    fwrite(STDERR, 'Source element classifier tests: ' . $failures . ' FAILED' . PHP_EOL);
    exit(1);
}
