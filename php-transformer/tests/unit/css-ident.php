<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\Css\CssIdent;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Support\SourceDom;

$failures = 0;
$passes = 0;
$assert = static function (bool $condition, string $message) use (&$failures, &$passes): void {
    if ( $condition ) {
        ++$passes;
        return;
    }
    ++$failures;
    fwrite(STDERR, "FAIL: {$message}\n");
};

$assert('mt-1\\.5' === CssIdent::escape('mt-1.5'), 'a decimal in an ident is escaped');
$assert('sm\\:grid-cols-2' === CssIdent::escape('sm:grid-cols-2'), 'a colon in an ident is escaped');
$assert('gap-5' === CssIdent::escape('gap-5'), 'an ident character is left intact');
$assert('\\32 xl\\:flex' === CssIdent::escape('2xl:flex'), 'a leading digit is hex-escaped');
$assert('.mt-1\\.5.sm\\:grid-cols-2' === CssIdent::compoundClassSelector(array( 'mt-1.5', 'sm:grid-cols-2' )), 'compound class selectors escape each token');

$assert(SourceDom::isBoundedClassToken('mt-1.5'), 'a decimal class token is retained');
$assert(SourceDom::isBoundedClassToken('sm:grid-cols-2'), 'a colon class token is retained');
$assert(SourceDom::isBoundedClassToken('2xl:flex'), 'a digit-started class token is retained');
$assert(! SourceDom::isBoundedClassToken('bad/token'), 'a slash token stays outside the receipt bound');
$assert(! SourceDom::isBoundedClassToken(str_repeat('x', 81)), 'an overlong token is dropped');
$assert(
    array( 'mt-1.5', 'w-full', 'sm:grid-cols-2' ) === SourceDom::boundedClassTokens('mt-1.5 w-full sm:grid-cols-2 bad/token'),
    'bounded tokens keep escaped-character classes and drop slash tokens'
);

if ( 0 < $failures ) {
    fwrite(STDERR, "css ident: {$failures} failed, {$passes} passed\n");
    exit(1);
}
fwrite(STDOUT, "css ident: {$passes} passed\n");
