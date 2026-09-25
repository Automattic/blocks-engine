<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\CssValueInspector;

$failures = 0;
$passes   = 0;
$assert   = static function (bool $condition, string $message) use (&$failures, &$passes): void {
    if ( $condition ) {
        ++$passes;
        return;
    }
    ++$failures;
    fwrite(STDERR, "FAIL: {$message}\n");
};

$assert(
    array( '1px', '1px', '1px', '1px' ) === CssValueInspector::expandBoxShorthand('1px'),
    'one value applies to all sides'
);
$assert(
    array( '1px', '4px', '1px', '4px' ) === CssValueInspector::expandBoxShorthand('1px 4px'),
    'two values are vertical then horizontal'
);
$assert(
    array( '1px', '4px', '2px', '4px' ) === CssValueInspector::expandBoxShorthand('1px 4px 2px'),
    'three values are top, horizontal, bottom'
);
$assert(
    array( '1px', '2px', '3px', '4px' ) === CssValueInspector::expandBoxShorthand('1px 2px 3px 4px'),
    'four values are top, right, bottom, left'
);
$assert(
    array( 'clamp(1px, 2vw, 3px)', '0', 'clamp(1px, 2vw, 3px)', '0' ) === CssValueInspector::expandBoxShorthand('clamp(1px, 2vw, 3px) 0'),
    'functional notation stays whole across a two-value shorthand'
);
$assert(
    array( '', '', '', '' ) === CssValueInspector::expandBoxShorthand(''),
    'an empty value expands to empty sides'
);
$assert(CssValueInspector::isBorderWidthToken('2px'), 'a length is a border-width token');
$assert(CssValueInspector::isBorderWidthToken('medium'), 'a keyword is a border-width token');
$assert(! CssValueInspector::isBorderWidthToken('solid'), 'a style keyword is not a border-width token');

if ( 0 < $failures ) {
    fwrite(STDERR, "css value inspector: {$failures} failed, {$passes} passed\n");
    exit(1);
}
fwrite(STDOUT, "css value inspector: {$passes} passed\n");
