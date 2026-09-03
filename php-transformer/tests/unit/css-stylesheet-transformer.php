<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\Css\CssSelectorTokenizer;
use Automattic\BlocksEngine\PhpTransformer\Css\CssStylesheetTransformer;

$failures = 0;
$passes = 0;
$assert = static function (bool $condition, string $message) use (&$failures, &$passes): void {
    if ( $condition ) { ++$passes; return; }
    ++$failures;
    fwrite(STDERR, "FAIL: {$message}\n");
};

$transformer = new CssStylesheetTransformer();
$rename = static fn (string $prelude): string => str_replace('.before', '.after', $prelude);

// 1, 2, and 8: comments and declaration syntax remain source-identical.
$css = "/* leading */ .before, /* list */ [data-label=\"a,b\"] { content: '\\'{};'; --x: \";}\"; }";
$assert("/* leading */ .after, /* list */ [data-label=\"a,b\"] { content: '\\'{};'; --x: \";}\"; }" === $transformer->transform($css, $rename), 'comments, strings, escapes, and declarations are preserved');
$assert($css === $transformer->transform($css, static fn (string $prelude): string => $prelude), 'no-op callback is byte-identical');

// 3 and 4: only safe block at-rules recurse.
$css = '@media screen { @supports (display: grid) { .before { color:red } } } @font-face { font-family:"x"; src:url("x;{}.woff2"); } @keyframes spin { from { opacity:0 } }';
$expected = '@media screen { @supports (display: grid) { .after { color:red } } } @font-face { font-family:"x"; src:url("x;{}.woff2"); } @keyframes spin { from { opacity:0 } }';
$assert($expected === $transformer->transform($css, $rename), 'nested media/supports rules transform while font-face and keyframes remain opaque');
$conditions = array();
$transformer->transform($css, static function (string $prelude, string $body, array $ancestors) use (&$conditions): string {
    $conditions[trim($prelude)] = $ancestors;
    return $prelude;
});
$assert(array( '@media screen', '@supports (display: grid)' ) === ($conditions['.before'] ?? null), 'transform callbacks receive the enclosing at-rule stack');

$visitedStyles = array();
$visitedKeyframes = array();
$transformer->visitStyleAndKeyframeRules(
    '@media screen { .before { animation: fade 1s } @-webkit-keyframes fade { from { opacity:0 } } } .after { color:red }',
    static function (string $prelude, string $body, array $ancestors) use (&$visitedStyles): void {
        $visitedStyles[] = array(trim($prelude), trim($body), $ancestors);
    },
    static function (string $name, string $body) use (&$visitedKeyframes): void {
        $visitedKeyframes[] = array($name, trim($body));
    }
);
$assert(
    array(
        array('.before', 'animation: fade 1s', array('@media screen')),
        array('.after', 'color:red', array()),
    ) === $visitedStyles
    && array(array('fade', 'from { opacity:0 }')) === $visitedKeyframes,
    'combined visitor reports nested style rules and vendor-prefixed keyframes'
);

// 5: commas inside nested syntax are not selector-list separators.
$parts = CssStylesheetTransformer::splitSelectorList(':is(.a,.b), :not([data-x="a,b"]), [title="x,y"]');
$assert(array( ':is(.a,.b)', ' :not([data-x="a,b"])', ' [title="x,y"]' ) === $parts, 'selector lists split only on top-level commas');

// 6: tokenization identifies each combinator and the rightmost compound.
foreach ( array( '.a .b' => ' ', '.a>.b' => '>', '.a + .b' => '+', '.a~.b' => '~' ) as $selector => $combinator ) {
    $tokens = CssSelectorTokenizer::tokenize($selector);
    $assert($tokens['supported'] && array( '.a', '.b' ) === $tokens['compounds'] && array( $combinator ) === $tokens['combinators'] && '.b' === $tokens['rightmost_compound'], "tokenizes {$selector}");
}
$assert(! CssSelectorTokenizer::tokenize('.a, .b')['supported'], 'selector lists are surfaced as unsupported by the single-selector tokenizer');

// Escapes consume CSS hex digits and an optional CSS whitespace continuation.
foreach ( array( '.\\31 0', ".\\31\r\n0" ) as $selector ) {
    $tokens = CssSelectorTokenizer::tokenize($selector);
    $assert($tokens['supported'] && array( $selector ) === $tokens['compounds'], 'escaped identifier stays one compound');
}
$escapedCss = '.before\\{x\\;y\\,z { color:red }';
$assert('.after\\{x\\;y\\,z { color:red }' === $transformer->transform($escapedCss, $rename), 'escaped structural bytes do not split stylesheet rules');

// CSS whitespace is exactly space, tab, LF, CR, and FF; comments only separate
// descendants when surrounding whitespace supplies the combinator.
$tokens = CssSelectorTokenizer::tokenize(".a\f>\f.b");
$assert($tokens['supported'] && array( '.a', '.b' ) === $tokens['compounds'] && array( '>' ) === $tokens['combinators'], 'form-feed around child combinator has no phantom compound');
$tokens = CssSelectorTokenizer::tokenize('.a/* note */ .b');
$assert($tokens['supported'] && array( ' ' ) === $tokens['combinators'], 'comments with CSS whitespace separate descendants');
$tokens = CssSelectorTokenizer::tokenize('.a/* note */.b');
$assert($tokens['supported'] && array( '.a/* note */.b' ) === $tokens['compounds'], 'comments alone stay inside a compound');

// Selectors 4 column combinator and namespace separator remain distinct.
foreach ( array( '.a||.b', '.a || .b' ) as $selector ) {
    $tokens = CssSelectorTokenizer::tokenize($selector);
    $assert($tokens['supported'] && array( '||' ) === $tokens['combinators'], "tokenizes column combinator {$selector}");
}
$tokens = CssSelectorTokenizer::tokenize('svg|a > *|button');
$assert($tokens['supported'] && array( 'svg|a', '*|button' ) === $tokens['compounds'] && array( '>' ) === $tokens['combinators'], 'single namespace separator remains in compounds');
$assert(array( 'start' => 0, 'end' => 2 ) === CssSelectorTokenizer::tokenize('.a > .b')['compound_spans'][0], 'compound spans reference original selector bytes');
$assert(array( 'start' => 3, 'end' => 4 ) === CssSelectorTokenizer::tokenize('.a > .b')['combinator_spans'][0], 'combinator spans reference original selector bytes');

// 7: malformed/truncated constructs are retained instead of partially transformed.
$malformed = '.before { content: "unterminated';
$assert($malformed === $transformer->transform($malformed, $rename), 'truncated stylesheet is preserved exactly');
$malformedSelector = '.before:is(.x { color:red }';
$assert($malformedSelector === $transformer->transform($malformedSelector, $rename), 'unbalanced selector prelude is preserved exactly');
$unmatchedClose = '} .before { color:red }';
$assert($unmatchedClose === $transformer->transform($unmatchedClose, $rename), 'top-level unmatched close preserves the entire stylesheet');
$unmatchedCloseAfterRule = '.before { color:red } } .before { color:blue }';
$assert($unmatchedCloseAfterRule === $transformer->transform($unmatchedCloseAfterRule, $rename), 'later unmatched close prevents earlier callback transformations');

// Block at-rules recurse only when rules are safe to walk. Declaration at-rules
// and declaration bodies, including custom-property braces and URL data, stay raw.
$css = '@scope (.root) { .before { color:red } } @property --x { syntax:"<color>"; initial-value:red; } @font-face { src:url(data:font/woff2;base64,abc==); } .before { --json: { braces: ";}"; }; background:url("data:image/svg+xml,<svg>{}</svg>"); }';
$expected = '@scope (.root) { .after { color:red } } @property --x { syntax:"<color>"; initial-value:red; } @font-face { src:url(data:font/woff2;base64,abc==); } .after { --json: { braces: ";}"; }; background:url("data:image/svg+xml,<svg>{}</svg>"); }';
$assert($expected === $transformer->transform($css, $rename), 'scope recurses while property, custom properties, and data URLs remain byte-preserved');

$identityCases = array(
    "/* comment */ .a\r\n{ content:';}'; }",
    ".a\f>\f.b { background:url(data:image/svg+xml,<svg>{}</svg>); }",
    '@scope (.x) { .a/*x*/ .b { --x:{ y:z; }; } }',
    '@property --x { syntax:"*"; inherits:false; initial-value:0; }',
);
foreach ( $identityCases as $identityCase ) {
    $assert($identityCase === $transformer->transform($identityCase, static fn (string $prelude): string => $prelude), 'no-op is byte-identical across scanner edge cases');
}

if ( $failures > 0 ) {
    fwrite(STDERR, "CssStylesheetTransformer unit tests: {$failures} failed, {$passes} passed\n");
    exit(1);
}
fwrite(STDOUT, "CssStylesheetTransformer unit tests: {$passes} passed\n");
