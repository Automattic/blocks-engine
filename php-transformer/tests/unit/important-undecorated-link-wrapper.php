<?php
declare(strict_types=1);

/**
 * A content-wrapping link pushed down into a heading rebuilds its anchor inside
 * the block. When the source wrapper is undecorated, the rebuilt anchor must
 * be too, whether the author wrote `text-decoration:none` or a utility class
 * such as Bootstrap's `.text-decoration-none{text-decoration:none!important}`.
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\SourceBlockAttributeProjector;

$failures = 0;
$passes = 0;
$assert = static function (bool $ok, string $message, string $detail = '') use (&$failures, &$passes): void {
    if ( $ok ) {
        ++$passes;
        return;
    }
    ++$failures;
    fwrite(STDERR, 'FAIL: ' . $message . ( '' !== $detail ? ' - ' . $detail : '' ) . PHP_EOL);
};

$undecorated = SourceBlockAttributeProjector::SYNTHETIC_ANCHOR_UNDECORATED_CLASS;
$heading = static function (string $css): string {
    $blocks = (string) (( new HtmlTransformer() )->transform(
        '<html><head><style>' . $css . '.brand h3{color:#fff}</style></head><body><header>'
        . '<a href="/" class="brand plain"><h3>Brand</h3></a></header></body></html>',
        array()
    )->toArray()['serialized_blocks'] ?? '');
    preg_match('/<h3 class="[^"]*"[^>]*>.*?<\/h3>/s', $blocks, $match);

    return $blocks;
};

foreach ( array(
    'plain'     => '.plain{text-decoration:none}',
    'important' => '.plain{text-decoration:none!important}',
    'spaced'    => '.plain{text-decoration:none !important}',
) as $kind => $css ) {
    $markup = $heading($css);
    $assert(str_contains($markup, '<a class="brand plain" href="/">'), "the {$kind} wrapper retains its author class and link", $markup);
    $assert(!str_contains($markup, $undecorated), "a {$kind} wrapper needs no propagated-decoration carrier", $markup);
}
$assert(1 !== preg_match('/\b' . preg_quote($undecorated, '/') . '\b/', $heading('.plain{text-decoration:underline!important}')), 'a decorated wrapper keeps the rebuilt anchor decorated');

if ( 0 < $failures ) {
    fwrite(STDERR, "important undecorated link wrapper FAILED: {$passes} passed, {$failures} failed\n");
    exit(1);
}
echo "important undecorated link wrapper passed: {$passes} assertions\n";
