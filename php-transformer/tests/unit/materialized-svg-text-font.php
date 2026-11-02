<?php
declare(strict_types=1);

/**
 * An inline SVG whose `<text>` is styled by the page stylesheet is
 * materialized as a standalone image that cannot see that stylesheet. The
 * cascaded text presentation (family, weight, size) has to be baked onto each
 * `<text>`. A family that only exists through an author `@font-face` cannot be
 * loaded by an image document at all, so that SVG stays inline instead.
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

$transform = static function (string $css): array {
    $out = ( new HtmlTransformer() )->transform(
        '<style>' . $css . '</style>'
        . '<main><h1>Heading</h1><div class="band">'
        . '<svg viewBox="0 0 600 100" width="600" height="100"><text class="label" x="0" y="60">MAKE IT MAKE SENSE</text></svg>'
        . '</div></main>'
    )->toArray();
    $svg = '';
    foreach ( $out['assets'] ?? array() as $asset ) {
        if ( is_array($asset) && 'inline-svg' === ( $asset['source'] ?? '' ) ) {
            $svg .= (string) ( $asset['content'] ?? '' );
        }
    }

    return array( 'blocks' => (string) ( $out['serialized_blocks'] ?? '' ), 'svg' => $svg );
};

// 1. System font: the materialized asset's <text> carries the author font.
$system = $transform('.label { font-family: monospace; font-weight: 700; fill: #ff5400; font-size: 40px }');
$text = preg_match('/<text\b[^>]*>/i', $system['svg'], $match) ? $match[0] : '';
$assert(str_contains($system['blocks'], 'assets/materialized-svg/inline-svg-'), '1: the system-font SVG materializes as an image', $system['blocks']);
$assert(str_contains($text, 'font-family:monospace'), '2: the asset text carries the author font-family', $text);
$assert(str_contains($text, 'font-weight:700'), '3: the asset text carries the author font-weight', $text);
$assert(str_contains($text, 'font-size:40px'), '4: the asset text carries the author font-size', $text);
$assert(str_contains($text, 'fill:#ff5400'), '5: the asset text keeps its baked fill', $text);

// 2. Author web font: an image cannot load it, so the SVG stays inline.
$webFont = $transform(
    '@font-face { font-family: "Marquee Sans"; src: url(marquee.woff2) format("woff2") }'
    . '.label { font-family: "Marquee Sans", monospace; font-weight: 700; fill: #ff5400; font-size: 40px }'
);
$assert(! str_contains($webFont['blocks'], 'materialized-svg'), '6: the web-font SVG is not externalized as an image', $webFont['blocks']);
$assert(
    1 === preg_match('/<svg\b[^>]*viewBox="0 0 600 100"[^>]*>\s*<text\b[^>]*class="label"[^>]*>MAKE IT MAKE SENSE<\/text>\s*<\/svg>/', $webFont['blocks']),
    '7: the web-font SVG stays inline with its text intact',
    $webFont['blocks']
);

if ( $failures > 0 ) {
    fwrite(STDERR, PHP_EOL . "materialized svg text font: {$passes} passed, {$failures} FAILED" . PHP_EOL);
    exit(1);
}
fwrite(STDOUT, "materialized svg text font: {$passes} passed" . PHP_EOL);
