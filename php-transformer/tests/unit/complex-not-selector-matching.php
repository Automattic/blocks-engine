<?php
declare(strict_types=1);

/**
 * Complex :not() arguments must be evaluated as full selectors, not compounds.
 *
 * Selectors Level 4 allows complex selectors — arguments carrying descendant,
 * child, or sibling combinators — inside `:not()`. Evaluating such an argument
 * as one compound silently drops its combinators, so a source exclusion such
 * as `.sqs-block:not(.fluid-engine .sqs-block)` was read as
 * `.sqs-block:not(.fluid-engine.sqs-block)`. An element inside a
 * `.fluid-engine` ancestor then wrongly matched the rule and the engine
 * inlined its padding into block style attributes, shrinking every image box
 * by the padded amount; the browser excludes the same element and computes
 * zero padding.
 *
 * The matcher's complex-selector machinery (matchesAt with combinators) is
 * used to evaluate the argument against the source DOM instead. Arguments the
 * matcher cannot parse — dynamic-state suffixes, selector lists it does not
 * model — keep the whole selector unsupported so the declaration stays
 * source-owned in the author stylesheet.
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\Css\CssSelectorMatcher;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

$failures = 0;
$passes   = 0;
$assert = static function (bool $condition, string $message, string $detail = '') use (&$failures, &$passes): void {
    if ( $condition ) {
        ++$passes;
        return;
    }
    ++$failures;
    fwrite(STDERR, 'FAIL: ' . $message . ('' !== $detail ? ' - ' . $detail : '') . PHP_EOL);
};

$dom = new DOMDocument();
$dom->loadHTML(
    '<html><body>'
    . '<div class="fluid-engine fe-block"><div class="sqs-block image-block" id="inside">A</div></div>'
    . '<div class="sqs-block image-block" id="outside">B</div>'
    . '</body></html>'
);
$xpath = new DOMXPath($dom);
$inside = $xpath->query('//*[@id="inside"]')[0];
$outside = $xpath->query('//*[@id="outside"]')[0];

$parsed = CssSelectorMatcher::parse('.sqs-block:not(.fluid-engine .sqs-block)');
$assert(
    true === ( $parsed['supported'] ?? false ),
    'a complex :not() argument stays supported for evaluation',
    json_encode($parsed)
);
$assert(
    false === CssSelectorMatcher::matches($inside, $parsed)['matches'],
    'an element inside the negated ancestor chain is excluded'
);
$assert(
    true === CssSelectorMatcher::matches($outside, $parsed)['matches'],
    'an element outside the negated ancestor chain still matches'
);
$assert(
    30 === CssSelectorMatcher::specificity($parsed),
    ':not() contributes the specificity of its argument (10 + 20)',
    (string) CssSelectorMatcher::specificity($parsed)
);

$child = CssSelectorMatcher::parse('.x:not(.outer > .x)');
$assert(
    true === ( $child['supported'] ?? false ),
    'a child-combinator :not() argument stays supported'
);

$stateSuffix = CssSelectorMatcher::parse('.a:not(.b:hover)');
$assert(
    false === ( $stateSuffix['supported'] ?? false ),
    'a dynamic-state :not() argument keeps the selector unsupported'
);

$attrParen = CssSelectorMatcher::parse('.a:not([title="x)"])');
$assert(
    true === ( $attrParen['supported'] ?? false ),
    'a parenthesis inside an attribute value does not truncate the :not() argument'
);

$transform = static fn (string $html): array => ( new HtmlTransformer() )->transform($html)->toArray();

$inline = static function (array $result): int {
    return substr_count((string) ( $result['serialized_blocks'] ?? '' ), 'padding-top:17px');
};

$sourceOwned = static function (array $result, string $needle): bool {
    foreach ( ( $result['assets'] ?? array() ) as $asset ) {
        if ( 'css' === ( $asset['kind'] ?? '' ) && str_contains((string) ( $asset['content'] ?? '' ), $needle) ) {
            return true;
        }
    }
    return false;
};

$shapedDocument = static function (bool $insideEngine): string {
    $wrapper = '<div class="sqs-block image-block"><div class="sqs-block-content"><img src="/media/a.webp" alt="A"></div></div>';
    return '<style>.sqs-block:not(.fluid-engine .sqs-block){padding-top:17px;padding-bottom:17px}</style>'
        . '<main><div class="fluid-engine"><div class="fe-block">' . $wrapper . '</div></div></main>'
        . ( $insideEngine ? '' : '' )
        . '<main><div class="fluid-engine"><div class="fe-block"><div class="sqs-block image-block"><div class="sqs-block-content"><img src="/media/b.webp" alt="B"></div></div></div></div></main>';
};

$shaped = $transform(
    '<style>.sqs-block:not(.fluid-engine .sqs-block){padding-top:17px;padding-bottom:17px}</style>'
    . '<main><div class="fluid-engine"><div class="fe-block"><div class="sqs-block image-block"><div class="sqs-block-content"><img src="/media/a.webp" alt="A"></div></div></div></div></main>'
);
$assert(
    0 === $inline($shaped),
    'an image wrapper inside the excluded chain gets no inline padding',
    (string) ( $shaped['serialized_blocks'] ?? '' )
);
$assert(
    $sourceOwned($shaped, '.sqs-block:not(.fluid-engine .sqs-block){padding-top:17px'),
    'the excluded declaration stays source-owned in the author stylesheet'
);

$taskCase = $transform(
    '<style>.x:not(.outer .x){padding:17px}</style>'
    . '<main><div class="outer"><div class="x"><div class="content"><p>In</p></div></div></div>'
    . '<div class="x"><div class="content"><p>Out</p></div></div></main>'
);
$assert(
    ! str_contains((string) ( $taskCase['serialized_blocks'] ?? '' ), 'style="padding'),
    'no block inside .outer carries a resolved padding style attribute'
);
$assert(
    $sourceOwned($taskCase, '.x:not(.outer .x){padding:17px}'),
    'the sibling outside .outer keeps the rule available, source-owned in the author stylesheet'
);

if ( $failures > 0 ) {
    fwrite(STDERR, "Complex :not() selector matching: {$failures} failed, {$passes} passed\n");
    exit(1);
}
fwrite(STDOUT, "Complex :not() selector matching passed: {$passes} assertions\n");
