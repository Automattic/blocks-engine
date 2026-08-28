<?php
declare(strict_types=1);

/**
 * A definite width on `.wp-block-buttons` must fill the inner link (issue #1303).
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
    '<style>.cta{width:7.82%;margin-left:89%}.cta{width:min-content}</style>'
    . '<main><button class="cta" type="button">Contacts</button></main>'
)->toArray();
$css = '';
foreach ( $out['assets'] ?? array() as $asset ) {
    if ( is_array($asset) && 'css' === ( $asset['kind'] ?? '' ) ) {
        $css .= (string) ( $asset['content'] ?? '' );
    }
}

$assert(
    (bool) preg_match('/wp-block-buttons[^{]*\{[^}]*7\.82%/', $css),
    '1: wrapper keeps the definite source width',
    $css
);
$assert(
    str_contains($css, 'wp-block-button__link){width:100%!important'),
    '2: inner link fills the sized wrapper',
    $css
);
$assert(
    ! preg_match('/wp-block-buttons[^{]*\{[^}]*min-content/', $css),
    '3: min-content still stays off the wrapper',
    $css
);

if ( $failures > 0 ) {
    fwrite(STDERR, PHP_EOL . "button wrapper inner fill tests: {$passes} passed, {$failures} FAILED" . PHP_EOL);
    exit(1);
}
fwrite(STDOUT, "button wrapper inner fill tests: {$passes} passed" . PHP_EOL);
