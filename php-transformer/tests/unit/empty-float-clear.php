<?php
declare(strict_types=1);

/**
 * An empty element whose author stylesheet sets `clear` ends the float
 * context, so the floats' container keeps its height. It renders nothing
 * itself, yet dropping it collapses the container under the following
 * content (#4778).
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

$failures = 0;
$assert = static function (bool $condition, string $message) use (&$failures): void {
    if ( ! $condition ) {
        ++$failures;
        fwrite(STDERR, 'FAIL: ' . $message . PHP_EOL);
    }
};

$markup = static function (string $rule): string {
    $html = '<style>.wrap{width:600px}.left{float:left;width:150px;height:300px}.right{float:right;width:430px;height:600px}.clr{' . $rule . '}</style>'
        . '<main><div class="wrap"><div class="left"><p>Menu</p></div><div class="right"><h2>Article</h2><p>Body text.</p></div><div class="clr"></div></div>'
        . '<footer><p>Footer text</p></footer></main>';
    return (string) ( ( new HtmlTransformer() )->transform($html)->toArray()['serialized_blocks'] ?? '' );
};

foreach ( array( 'clear:both', 'clear:left', 'clear:right' ) as $rule ) {
    $assert(1 === preg_match('/<div[^>]*class="[^"]*\bclr\b/', $markup($rule)), "An empty element with stylesheet `{$rule}` survives as a block.");
}
$assert(1 !== preg_match('/<div[^>]*class="[^"]*\bclr\b/', $markup('clear:none')), 'An empty element with `clear:none` is still dropped.');

if ( 0 < $failures ) {
    exit(1);
}
fwrite(STDOUT, 'empty float clear tests: passed' . PHP_EOL);
