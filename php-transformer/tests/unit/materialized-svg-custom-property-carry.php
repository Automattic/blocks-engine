<?php
declare(strict_types=1);

/**
 * A materialized SVG keeps the author declarations that matched it. The custom
 * properties those declarations read were declared on the wrappers the
 * materialization collapses, so they have to be re-rooted on the image or the
 * carried `var()` is invalid at computed-value time and the artwork falls back
 * to its intrinsic viewBox geometry.
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

$transform = static function (string $html): array {
    $out = ( new HtmlTransformer() )->transform($html)->toArray();
    $css = '';
    $svg = '';
    foreach ( $out['assets'] ?? array() as $asset ) {
        if ( ! is_array($asset) ) {
            continue;
        }
        if ( 'css' === ( $asset['kind'] ?? '' ) ) {
            $css .= (string) ( $asset['content'] ?? '' );
        }
        if ( 'inline-svg' === ( $asset['source'] ?? '' ) ) {
            $svg .= (string) ( $asset['content'] ?? '' );
        }
    }

    return array( 'blocks' => (string) ( $out['serialized_blocks'] ?? '' ), 'css' => $css, 'svg' => $svg );
};

// An icon inside a converted button: the wrapper that declares --size is gone.
$button = $transform(
    '<style>.icon{--size:24px}.icon svg{width:var(--size);height:var(--size)}</style>'
    . '<div><a href="tel:123" class="cta">Call us'
    . '<span class="icon"><svg viewBox="0 0 200 200" width="200" height="200"><path d="M10 10H90V90H10z"></path></svg></span>'
    . '</a></div>'
);
$image = preg_match('/<img[^>]*>/', $button['blocks'], $match) ? $match[0] : '';

$assert('' !== $image, '1: the button icon materializes as an image', $button['blocks']);
$assert(
    str_contains($image, 'width:var(--size)') && str_contains($image, 'height:var(--size)'),
    '2: the author declaration is still carried onto the image',
    $image
);
$assert(
    str_contains($image, '--size:24px'),
    '3: the custom property the carried declaration reads is re-rooted on the image',
    $image
);

// The same leak through the generated geometry carrier rule.
$standalone = $transform(
    '<style>.wrap{--size:32px}.wrap svg{display:inline-block;width:var(--size);height:var(--size)}</style>'
    . '<div class="wrap"><svg viewBox="0 0 200 200" width="200" height="200"><path d="M10 10H90V90H10z"></path></svg></div>'
);
$carrier = '';
if ( preg_match('/[^{}]*\{[^{}]*width:var\(--size\)[^{}]*\}/', $standalone['css'], $match) ) {
    $carrier = $match[0];
}

$assert('' !== $carrier, '4: the standalone SVG carries its author media box into a generated rule', $standalone['css']);
$assert(
    str_contains($carrier, '--size:32px'),
    '5: the generated rule re-roots the custom property it reads',
    $carrier
);

// A quoted font stack resolved from a root-style custom property must remain a
// well-formed HTML attribute. Unescaped `"` would terminate `style="` early.
$quotedFont = $transform(
    '<style>:root{--font-mono:"JetBrains Mono", ui-monospace, monospace}.plan{width:100%;height:auto;display:block}</style>'
    . '<main><svg class="plan" viewBox="0 0 100 84" style="font-family: var(--font-mono);">'
    . '<rect width="100" height="84" fill="#E2E8F0"></rect>'
    . '<text x="50" y="42" text-anchor="middle">FR1</text>'
    . '</svg></main>'
);
$quotedFontImage = preg_match('/<img[^>]*>/', $quotedFont['blocks'], $quotedFontMatch) ? $quotedFontMatch[0] : '';
$assert(
    str_contains($quotedFont['blocks'], '<!-- wp:image') && ! str_contains($quotedFont['blocks'], '<!-- wp:html') && '' !== $quotedFontImage,
    '6: a resolved quoted font-family custom property stays on the native image path',
    $quotedFont['blocks']
);
$assert(
    str_contains($quotedFont['svg'], 'font-family:&quot;JetBrains Mono&quot;, ui-monospace, monospace')
        && preg_match('/<svg\b[^>]*\sstyle\s*=\s*"([^"]*)"/i', $quotedFont['svg'], $quotedFontStyle) === 1
        && str_contains($quotedFontStyle[1], 'JetBrains Mono')
        && ! str_contains($quotedFontStyle[1], '"')
        && ! str_contains($quotedFont['svg'], 'style="font-family:"'),
    '7: the materialized SVG style attribute remains well-formed around a quoted font family',
    $quotedFont['svg']
);

if ( $failures > 0 ) {
    fwrite(STDERR, PHP_EOL . "materialized svg custom property carry: {$passes} passed, {$failures} FAILED" . PHP_EOL);
    exit(1);
}
fwrite(STDOUT, "materialized svg custom property carry: {$passes} passed" . PHP_EOL);
