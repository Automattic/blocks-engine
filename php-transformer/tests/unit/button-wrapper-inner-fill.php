<?php
declare(strict_types=1);

/**
 * Definite source-owned core/buttons geometry must fill the native inner carriers.
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

$height = ( new HtmlTransformer() )->transform(
    '<style>#source-button{height:45.8594px;padding:12px 24px;font-size:16px;background:#173b64;color:#fff}</style>'
    . '<main><a id="source-button" href="/quote">GET A QUOTE</a></main>'
)->toArray();
$heightCss = '';
foreach ( $height['assets'] ?? array() as $asset ) {
    if ( is_array($asset) && 'css' === ( $asset['kind'] ?? '' ) ) {
        $heightCss .= (string) ( $asset['content'] ?? '' );
    }
}

$assert(
    (bool) preg_match('/wp-block-buttons[^}]*\{[^}]*height:45\.8594px/', $heightCss),
    '4: source-authored height stays on the outer core/buttons carrier',
    $heightCss
);
$assert(
    (bool) preg_match('/wp-block-buttons\)> :where\(\.wp-block-button\)\{height:100%!important\}[^\n]*wp-block-button__link\)\{height:100%!important\}/', $heightCss),
    '5: a definite outer height fills the nested core/button and link from the authored carrier rule',
    $heightCss
);

$autoHeight = ( new HtmlTransformer() )->transform(
    '<style>#auto-button{height:auto;padding:12px 24px;font-size:16px;background:#173b64;color:#fff}</style>'
    . '<main><a id="auto-button" href="/quote">GET A QUOTE</a></main>'
)->toArray();
$autoHeightCss = implode('', array_map(static fn (array $asset): string => 'css' === ( $asset['kind'] ?? '' ) ? (string) ( $asset['content'] ?? '' ) : '', $autoHeight['assets'] ?? array()));
$assert(
    ! str_contains($autoHeightCss, 'height:100%!important'),
    '6: auto-height does not opt into inner carrier fill',
    $autoHeightCss
);

$responsiveHeight = ( new HtmlTransformer() )->transform(
    '<style>#responsive-button{height:45.8594px;padding:12px 24px;background:#173b64;color:#fff}@media(max-width:600px){#responsive-button{height:auto}}</style>'
    . '<main><a id="responsive-button" href="/quote">GET A QUOTE</a></main>'
)->toArray();
$responsiveHeightCss = implode('', array_map(static fn (array $asset): string => 'css' === ( $asset['kind'] ?? '' ) ? (string) ( $asset['content'] ?? '' ) : '', $responsiveHeight['assets'] ?? array()));
$assert(
    (bool) preg_match('/@media\(max-width:600px\)\{[^}]*height:auto[^}]*\}[^@]*height:auto!important/', $responsiveHeightCss),
    '7: responsive auto-height explicitly clears the nested carrier fill in the same condition',
    $responsiveHeightCss
);

if ( $failures > 0 ) {
    fwrite(STDERR, PHP_EOL . "button wrapper inner fill tests: {$passes} passed, {$failures} FAILED" . PHP_EOL);
    exit(1);
}
fwrite(STDOUT, "button wrapper inner fill tests: {$passes} passed" . PHP_EOL);
