<?php
declare(strict_types=1);

/**
 * Unit tests for the shared runtime selector vocabulary (#242).
 *
 * Plain-PHP test script in the style of tests/unit/source-dom.php — no PHPUnit.
 *
 * This vocabulary existed as three independent copies of the same 13-token
 * list across two namespaces, which is how the copies were free to disagree.
 * The cases below pin the contract those copies were meant to share, with
 * particular attention to the selector shapes on which they had already
 * diverged.
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\Support\RuntimeSelectorVocabulary;

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

// --- presentational selectors: the whole-selector rule ----------------------

$assert(RuntimeSelectorVocabulary::isPresentationalAnimation('.fade-in'), 'a class naming an effect is presentational');
$assert(RuntimeSelectorVocabulary::isPresentationalAnimation('#reveal-hero'), 'an id naming an effect is presentational');
$assert(RuntimeSelectorVocabulary::isPresentationalAnimation('[data-scroll]'), 'a data attribute naming an effect is presentational');
$assert(RuntimeSelectorVocabulary::isPresentationalAnimation('div.parallax'), 'a tag-qualified class is presentational');
$assert(RuntimeSelectorVocabulary::isPresentationalAnimation('.STAGGER'), 'token matching is case-insensitive');

$assert(! RuntimeSelectorVocabulary::isPresentationalAnimation('.cart-drawer'), 'a behavioral class is not presentational');
$assert(! RuntimeSelectorVocabulary::isPresentationalAnimation('.hero-banner'), 'an unrelated class is not presentational');
$assert(! RuntimeSelectorVocabulary::isPresentationalAnimation(''), 'an empty selector is not presentational');
$assert(! RuntimeSelectorVocabulary::isPresentationalAnimation('.fadein'), 'an unsplit token does not match the vocabulary');

// The selector must name an effect as a whole. A compound or descendant
// selector names a position in a document, so it is not presentational on the
// strength of one of its parts. Each of these returned true in at least one of
// the copies this vocabulary replaces.
$assert(! RuntimeSelectorVocabulary::isPresentationalAnimation('.wrap .fade-in'), 'a descendant selector is not presentational');
$assert(! RuntimeSelectorVocabulary::isPresentationalAnimation('div.fade-in > span'), 'a child-combinator selector is not presentational');
$assert(! RuntimeSelectorVocabulary::isPresentationalAnimation('.fade-in.extra'), 'a multi-class selector is not presentational');
$assert(! RuntimeSelectorVocabulary::isPresentationalAnimation('section#scroll-top'), 'a tag-qualified id is not presentational');

// --- data-attribute selectors ----------------------------------------------

$assert(
    RuntimeSelectorVocabulary::dataAttributeSelectorsFromCssSelector('[data-modal]') === array('[data-modal]'),
    'a bare data-attribute selector is reported'
);
$assert(
    RuntimeSelectorVocabulary::dataAttributeSelectorsFromCssSelector('button[data-cart]') === array('button[data-cart]', '[data-cart]'),
    'a tag-qualified data-attribute selector reports both the qualified and bare forms'
);

// The two scans below pin behavior that is preserved, not endorsed. The second
// scan runs against the reassigned $selector rather than the original argument,
// so a selector carrying more than one data attribute reports only the first
// and never sees the rest. Pinned here so that correcting it is a deliberate,
// visible change rather than a silent one.
$assert(
    RuntimeSelectorVocabulary::dataAttributeSelectorsFromCssSelector('form[data-checkout] .row[data-total]')
        === array('form[data-checkout]', '[data-checkout]'),
    'a second data attribute in the same selector is currently not reported'
);
$assert(
    RuntimeSelectorVocabulary::dataAttributeSelectorsFromCssSelector('[data-MODAL]') === array('[data-modal]'),
    'attribute names are lowercased'
);
$assert(
    RuntimeSelectorVocabulary::dataAttributeSelectorsFromCssSelector('[data-modal="open"]') === array('[data-modal]'),
    'an attribute value is not part of the reported selector'
);
$assert(
    RuntimeSelectorVocabulary::dataAttributeSelectorsFromCssSelector('[data-reveal]') === array(),
    'a presentational data attribute is excluded'
);
$assert(
    RuntimeSelectorVocabulary::dataAttributeSelectorsFromCssSelector('.no-attributes-here') === array(),
    'a selector without data attributes reports nothing'
);

// --- script selector pattern ------------------------------------------------

$pattern = RuntimeSelectorVocabulary::scriptSelectorPattern();
$matches = static fn (string $candidate): bool => 1 === preg_match('/^' . $pattern . '$/', $candidate);

$assert($matches('.promo'), 'a class selector is a script selector shape');
$assert($matches('#promo'), 'an id selector is a script selector shape');
$assert($matches('[data-modal]'), 'a data-attribute selector is a script selector shape');
$assert($matches('button'), 'a runtime tag is a script selector shape');
$assert($matches('canvas'), 'canvas is a script selector shape');
$assert(! $matches('div'), 'a non-runtime tag is not a script selector shape');

$assert(
    in_array('button', RuntimeSelectorVocabulary::RUNTIME_TAG_SELECTORS, true)
        && ! in_array('div', RuntimeSelectorVocabulary::RUNTIME_TAG_SELECTORS, true),
    'the runtime tag list names interactive elements only'
);

// --- statelessness ----------------------------------------------------------

$first = RuntimeSelectorVocabulary::isPresentationalAnimation('.fade-in');
RuntimeSelectorVocabulary::dataAttributeSelectorsFromCssSelector('[data-cart]');
$assert(
    $first === RuntimeSelectorVocabulary::isPresentationalAnimation('.fade-in'),
    'answers do not drift across calls'
);

echo 'Runtime selector vocabulary tests: ' . $passes . ' passed' . PHP_EOL;

if ( $failures > 0 ) {
    fwrite(STDERR, 'Runtime selector vocabulary tests: ' . $failures . ' FAILED' . PHP_EOL);
    exit(1);
}
