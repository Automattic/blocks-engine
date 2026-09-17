<?php
declare(strict_types=1);

/**
 * Unit tests for the shared CSS byte scanner.
 *
 * Plain-PHP test script — no PHPUnit. Block structure is the thing every CSS
 * primitive in the engine needs and the thing each one used to re-derive: the
 * author layer reader, the wp-compat rule splitter and the static parity
 * resolver all counted braces themselves, and each copy missed a different
 * lexical context. matchingBrace() is that shared reading, so the cases below
 * are the ones the local copies got wrong.
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\Css\CssSyntaxScanner;

$failures = 0;
$passes   = 0;

$assert = static function (bool $condition, string $message, string $detail = '') use (&$failures, &$passes): void {
    if ( $condition ) { ++$passes; return; }
    ++$failures;
    fwrite(STDERR, 'FAIL: ' . $message . ( '' !== $detail ? ' - ' . $detail : '' ) . PHP_EOL);
};

/** Body of the block opened by the first top-level `{`, or null. */
$body = static function (string $css): ?string {
    $state = CssSyntaxScanner::state();
    $cursor = 0;
    $length = strlen($css);
    while ( $cursor < $length ) {
        if ( CssSyntaxScanner::isTopLevel($state) && '{' === $css[ $cursor ] ) {
            $close = CssSyntaxScanner::matchingBrace($css, $cursor);
            return null === $close ? null : substr($css, $cursor + 1, $close - $cursor - 1);
        }
        $cursor = CssSyntaxScanner::consume($css, $cursor, $state) ?? ( $cursor + 1 );
    }
    return null;
};

$assert('color:red' === $body('a{color:red}'), 'a plain block returns its own body');
$assert('a{b:c}' === $body('@media x{a{b:c}}'), 'a nested block is consumed whole');

// A quoted value may contain a brace, and so may a comment.
$assert('content:"}"' === $body('a{content:"}"}'), 'a brace inside a quoted value does not close the block');
$assert("content:'}'" === $body("a{content:'}'}"), 'single quotes count too');
$assert('/* } */color:red' === $body('a{/* } */color:red}'), 'a brace inside a comment does not close the block');

// The case every local copy missed. A selector may escape the characters that
// delimit a block, and Tailwind arbitrary-value utilities do it routinely, so
// counting raw braces desynchronises from the stylesheet.
$assert('color:red' === $body('.a\\{b{color:red}'), 'an escaped brace in a selector is not block structure');
$assert('width:1px' === $body('.w-\\[calc\\(100\\%\\)\\]{width:1px}'), 'an escaped arbitrary-value utility is not block structure');
$assert(
    'background:url(x\\{.png);color:red' === $body('a{background:url(x\\{.png);color:red}'),
    'an escaped brace inside url() does not close the block',
    var_export($body('a{background:url(x\\{.png);color:red}'), true)
);

// Structure is only read where the caller points at a brace, and an unclosed
// block reports itself rather than guessing an end.
$assert(null === CssSyntaxScanner::matchingBrace('a{b}', 0), 'matchingBrace requires an opening brace');
$assert(3 === CssSyntaxScanner::matchingBrace('a{b}', 1), 'matchingBrace returns the closing offset');
$assert(null === CssSyntaxScanner::matchingBrace('a{b', 1), 'an unclosed block returns null');
$assert(null === CssSyntaxScanner::matchingBrace('', 0), 'an empty stylesheet has no block');

if ( $failures > 0 ) {
    fwrite(STDERR, "CssSyntaxScanner unit tests: {$failures} failed, {$passes} passed\n");
    exit(1);
}
fwrite(STDOUT, "CssSyntaxScanner unit tests: {$passes} passed\n");
