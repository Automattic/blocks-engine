<?php
declare(strict_types=1);

/**
 * A viewBox-only SVG that fills a wrapper pinned on all four sides by
 * `position:absolute; inset:0` (the Wix vector-image logo) has no intrinsic
 * width. Once it is materialized into a linked core/image, the shrink-to-fit
 * anchor collapses it to 0x0 unless the figure, link and img carry the source
 * parent fill.
 *
 * @see https://github.com/Automattic/blocks-engine/issues/2490
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

$transform = static function (string $css, string $body): array {
    $out = ( new HtmlTransformer() )->transform('<style>' . $css . '</style>' . $body)->toArray();
    $generated = '';
    foreach ( $out['assets'] ?? array() as $asset ) {
        if ( is_array($asset) && 'css' === ( $asset['kind'] ?? '' ) ) {
            $generated .= (string) ( $asset['content'] ?? '' );
        }
    }
    $blocks = (string) ( $out['serialized_blocks'] ?? '' );
    $figureClass = preg_match('/<figure class="([^"]*)"/', $blocks, $match) ? $match[1] : '';
    $fillClass = preg_match('/\bbe-inline-geometry-[a-f0-9-]+/', $figureClass, $match) ? $match[0] : '';
    $fillRule = '';
    if ( '' !== $fillClass ) {
        $quoted = preg_quote($fillClass, '/');
        $fillRule = preg_match_all('/[^{}]*' . $quoted . '[^{}]*\{[^{}]*\}/', $generated, $matches) ? implode('', $matches[0]) : '';
    }

    return array( 'blocks' => $blocks, 'css' => $generated, 'fill' => $fillRule );
};

$svg = '<svg preserveAspectRatio="xMidYMid meet" viewBox="0 0 67.38 34.36" role="img" aria-label="Homepage">'
    . '<path d="M20.42 0v12.64H19V0c-1.44 1.53-4.64 5.41-4.64 10 0 5.9 5.34 10.68 5.34 10.68z" fill="#231f20"/></svg>';
$wixCss = '.iL7Pq5 svg{width:var(--svg-calculated-width,100%);height:var(--svg-calculated-height,100%);margin:auto;position:absolute;inset:0}'
    . '.iL7Pq5{position:absolute;inset:0}.IT88M3{position:absolute;inset:0}'
    . '#comp-logo{width:70px;height:36px;position:relative}';
$logo = static fn (string $svgMarkup): string => '<header><div id="comp-logo"><a href="https://example.com/" class="IT88M3"><div class="iL7Pq5">'
    . $svgMarkup . '</div></a></div></header>';

// 1. The Wix logo: var() fallback of 100% inside an inset-pinned wrapper.
$linked = $transform($wixCss, $logo($svg));
$assert(str_contains($linked['blocks'], '<a href="https://example.com/"><img'), '1a: the logo keeps its link', $linked['blocks']);
$assert(
    str_contains($linked['fill'], '>a>img{width:100%;height:100%;-o-object-fit:contain;object-fit:contain}'),
    '1b: the linked img fills the figure without stretching',
    $linked['fill']
);
$assert(str_contains($linked['fill'], '>a{display:block;width:100%;height:100%}'), '1c: the link fills the figure', $linked['fill']);
$assert(str_contains($linked['fill'], '{margin:0;width:100%;height:100%;line-height:0}'), '1d: the figure fills the pinned wrapper', $linked['fill']);
$assert(! str_contains($linked['fill'], 'vertical-align:baseline'), '1e: no shrink-to-fit inline geometry', $linked['fill']);

// 2. Insets given as longhands are the same pinned box.
$longhand = $transform(str_replace('.iL7Pq5{position:absolute;inset:0}', '.iL7Pq5{position:absolute;top:0;right:0;bottom:0;left:0}', $wixCss), $logo($svg));
$assert(str_contains($longhand['fill'], '>a>img{width:100%;height:100%;'), '2: top/right/bottom/left pinning fills too', $longhand['fill']);

// 3. A custom property that resolves to a fixed size is not a parent fill.
$fixed = $transform($wixCss . '.iL7Pq5{--svg-calculated-width:40px;--svg-calculated-height:20px}', $logo($svg));
$assert(! str_contains($fixed['css'], 'object-fit:contain'), '3: a resolved fixed size keeps its own geometry', $fixed['fill']);

// 4. A wrapper pinned on only two sides has no definite size to fill.
$corner = $transform(str_replace('.iL7Pq5{position:absolute;inset:0}', '.iL7Pq5{position:absolute;top:0;left:0}', $wixCss), $logo($svg));
$assert(! str_contains($corner['css'], 'object-fit:contain'), '4: a corner-anchored wrapper is not a parent fill', $corner['fill']);

// 5. A statically positioned wrapper is unchanged.
$static = $transform(str_replace('.iL7Pq5{position:absolute;inset:0}', '.iL7Pq5{display:block}', $wixCss), $logo($svg));
$assert(! str_contains($static['css'], 'object-fit:contain'), '5: a static wrapper is not a parent fill', $static['fill']);

// 6. An inset wrapper with an auto-resolving var() is unchanged.
$autoSize = $transform(str_replace('100%)', 'auto)', $wixCss), $logo($svg));
$assert(! str_contains($autoSize['css'], 'object-fit:contain'), '6: an auto-sized SVG is not a parent fill', $autoSize['fill']);

if ( $failures > 0 ) {
    fwrite(STDERR, sprintf('%d failure(s), %d pass(es)' . PHP_EOL, $failures, $passes));
    exit(1);
}

echo sprintf('Inset-positioned parent-fill SVG tests passed (%d assertions).' . PHP_EOL, $passes);
