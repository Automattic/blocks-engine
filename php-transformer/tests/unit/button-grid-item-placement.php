<?php
declare(strict_types=1);

/**
 * The buttons wrapper is the source control's box in the parent layout, so
 * grid placement and self-alignment belong on it, not the inner control.
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
    '<style>#wrap{display:grid}:is(#main :where(.cta),[id^="cta__"]){grid-area:1/1/2/2;align-self:start;justify-self:start;position:relative;width:7.82%;margin:0 0 2.5rem 1.5rem}</style>'
    . '<main id="main"><div id="wrap"><a id="cta" class="cta" href="/contacts" role="button">Contacts</a></div></main>'
)->toArray();
$css = '';
foreach ( $out['assets'] ?? array() as $asset ) {
    if ( is_array($asset) && 'css' === ( $asset['kind'] ?? '' ) ) {
        $css .= (string) ( $asset['content'] ?? '' );
    }
}

$assert(
    (bool) preg_match('/wp-block-buttons[^}]*\{[^}]*grid-area/', $css),
    '1: grid placement lands on the buttons wrapper',
    $css
);
$assert(
    ! preg_match('/wp-block-button__link\)?\{[^}]*grid-area/', $css),
    '2: grid placement does not land on the inner control',
    $css
);
$assert(
    (bool) preg_match('/wp-block-buttons[^}]*\{[^}]*align-self/', $css)
        && (bool) preg_match('/wp-block-buttons[^}]*\{[^}]*justify-self/', $css),
    '3: self-alignment travels with the placement',
    $css
);
$assert(
    (bool) preg_match('/wp-block-buttons[^}]*\{[^}]*margin:(?:0 0 2\.5rem 1\.5rem|[^}]*margin-bottom:2\.5rem[^}]*margin-left:1\.5rem)/', $css),
    '4: authored grid-item margins land on the buttons wrapper',
    $css
);
$assert(
    ! preg_match('/wp-block-button(?:__link)?[^}]*\{[^}]*margin-bottom:2\.5rem/', $css),
    '5: authored grid-item margins do not land on synthetic inner controls',
    $css
);

if ( $failures > 0 ) {
    fwrite(STDERR, PHP_EOL . "button grid-item placement tests: {$passes} passed, {$failures} FAILED" . PHP_EOL);
    exit(1);
}
fwrite(STDOUT, "button grid-item placement tests: {$passes} passed" . PHP_EOL);
