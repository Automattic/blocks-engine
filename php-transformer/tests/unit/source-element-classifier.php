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
