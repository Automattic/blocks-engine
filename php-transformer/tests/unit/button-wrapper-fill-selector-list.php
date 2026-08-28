<?php
declare(strict_types=1);

/**
 * Inner-fill combinators must attach to every selector in a comma list (issue follow-up to #1303).
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

$failures = 0;
$passes   = 0;
$assert = static function (bool $condition, string $message, string $detail = '') use (&$failures, &$passes): void {
    if ( $condition ) {
        ++$passes;
        return;
    }
    ++$failures;
    fwrite(STDERR, 'FAIL: ' . $message . ( '' !== $detail ? ' - ' . $detail : '' ) . PHP_EOL);
};

$out = ( new HtmlTransformer() )->transform(
    '<style>.cta{width:7.82%;margin-left:89%}</style>'
    . '<main><button class="cta" type="button">One</button>'
    . '<div><button class="cta" type="button">Two</button></div></main>'
)->toArray();
$css = '';
foreach ( $out['assets'] ?? array() as $asset ) {
    if ( is_array($asset) && 'css' === ( $asset['kind'] ?? '' ) ) {
        $css .= (string) ( $asset['content'] ?? '' );
    }
}

$assert(
    ! preg_match('/:where\(\.wp-block-buttons\),:where\([^)]+\):where\(\.wp-block-buttons\)>\s*:where\(\.wp-block-button\)/', $css),
    '1: child combinator is not applied only to the last selector in the list',
    $css
);
$assert(
    str_contains($css, 'wp-block-button__link){width:100%!important'),
    '2: inner link still fills the wrapper',
    $css
);

if ( $failures > 0 ) {
    fwrite(STDERR, PHP_EOL . "button fill selector-list tests: {$passes} passed, {$failures} FAILED" . PHP_EOL);
    exit(1);
}
fwrite(STDOUT, "button fill selector-list tests: {$passes} passed" . PHP_EOL);
