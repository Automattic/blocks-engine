<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

/**
 * A real, captured Wix hero-slideshow excerpt (rmamusiclessons.com,
 * `#comp-motpujy96`) that the synthetic fixture in
 * `stretch-derived-image-geometry.php` did not reproduce, because that
 * fixture only exercised {@see AuthorStyleRuleProjector::receivesDefiniteBlockSize}'s
 * percentage- and stretch-chain routes with a literal pixel height at the
 * very top of the chain. This document exercises two routes that fixture
 * never touched, both present on the same real elements:
 *
 *  - `#comp-motpujyc3` and `[id^="comp-motpujyg1__"]` pair `height:auto`
 *    with a literal `min-height:0px` (a common page-builder idiom that
 *    overrides the browser's default `min-height:auto` on a flex/grid item
 *    without constraining anything). The heuristic used to treat that
 *    literal zero as a genuine, non-rescuable value -- indistinguishable
 *    from `min-height:min-content` -- and disqualified the whole chain from
 *    stretch-derived sizing.
 *  - Higher up, `#comp-motpujy96` itself opts out of stretch
 *    (`align-self:center`), so no ancestor ever hands it a definite size
 *    top-down. Its child `.comp-motpujy96-container` is sized instead from
 *    the bottom up, by its own `grid-template-rows:minmax(<definite>,
 *    auto)` -- ordinary CSS Grid track sizing, not stretch at all -- which
 *    the heuristic never modeled.
 *
 * Both gaps combined to make `isIntrinsicallySizedGridContainer()` treat
 * `#comp-motpujyc3`, `#comp-motpujyc3 .comp-motpujyc3-container`, and
 * `[id^="comp-motpujyg1__"]` as unsized and collapse their `1fr` row track
 * to `min-content`, even though the real chain resolves to a definite
 * ~402px in a browser. It must now recognize both routes, and must not
 * carry the collapse when no ancestor or self-track is genuinely resolvable.
 */

$assertions = 0;
$assert = static function (bool $condition, string $message, string $detail = '') use (&$assertions): void {
    ++$assertions;
    if ( ! $condition ) {
        fwrite(STDERR, 'FAIL: ' . $message . ( '' !== $detail ? "\n" . $detail : '' ) . "\n");
        exit(1);
    }
};

$css = static function (array $result): string {
    $out = '';
    foreach ( $result['assets'] ?? array() as $asset ) {
        if ( 'css' === ($asset['kind'] ?? null) ) {
            $out .= (string) ($asset['content'] ?? '');
        }
    }
    return $out;
};

// Rule bodies below are copied verbatim (property order and all) from the
// real captured source's inline <style>; only unrelated declarations
// (paint, borders, custom-property theme tokens, ...) are trimmed.
$slideshowMarkup = static function (): string {
    return '<div id="comp-motpujy96" data-testid="slideshow" class="comp-motpujy96 _OfOwM" role="group">'
        . '<div class="comp-motpujy96-overflow-wrapper gDZ5xr" data-testid="responsive-container-overflow">'
        . '<div data-testid="responsive-container-content" tabindex="-1" role="group" class="comp-motpujy96-container">'
        . '<div id="comp-motpujyc3" class="JkoXHO comp-motpujyc3 wixui-repeater" tabindex="-1" role="group">'
        . '<div data-testid="responsive-container-content" role="list" aria-live="off" class="comp-motpujyc3-container">'
        . '<div class="p9hNc1 xjQkF3 fABPvj">'
        . '<div id="comp-motpujyg1__item1" role="listitem" class="HFEOE3 NaeT1r comp-motpujyg1-container comp-motpujyg1 comp-motpujyg1__item1 wixui-repeater__item" dir="ltr">'
        . '<div id="comp-motpujyi4__item1" data-testid="imageX" class="i4P7Vt comp-motpujyi4 comp-motpujyi4__item1 ZYZJBv wixui-image">'
        . '<picture><img loading="lazy" src="/media/photo.avif" alt="Photo" style="object-fit: cover; object-position: 51% 42%; width: 100%;" fetchpriority="high"></picture>'
        . '</div></div></div>'
        . '</div></div></div></div></div></div>';
};

$slideshowCss = '#comp-motpujy96 .comp-motpujy96-overflow-wrapper{position:relative;display:grid;grid-template-rows:1fr;grid-template-columns:minmax(0, 1fr);overflow-x:hidden;scrollbar-width:none;overflow:-moz-scrollbars-none;-ms-overflow-style:none;}'
    . '#comp-motpujy96 .comp-motpujyc96-container-unused{display:none}'
    . '#comp-motpujy96 .comp-motpujy96-container{box-sizing:border-box;position:relative;pointer-events:none;row-gap:0px;column-gap:0px;display:var(--l_display,var(--container-display));grid-template-rows:minmax(max(0.5px, 0.2790514 * (var(--scaling-factor) - var(--scrollbar-width))),auto);grid-template-columns:minmax(0px,1fr);--container-layout-type:grid-container-layout;--container-display:grid;}'
    . '#comp-motpujy96{min-height:0px;--l_display:unset;height:auto;min-width:0px;width:max(0.5px, 0.373913 * (var(--scaling-factor) - var(--scrollbar-width)));max-width:99999px;max-height:99999px;--comp-display:unset;pointer-events:auto;margin-left:0px;margin-right:0px;margin-top:0px;margin-bottom:0px;align-self:center;order:2;position:relative;}'
    . '#comp-motpujyc3{min-height:0px;--l_display:unset;height:auto;min-width:0px;width:auto;max-width:99999px;max-height:99999px;--comp-display:unset;align-self:stretch;justify-self:stretch;pointer-events:auto;margin-left:0px;margin-right:0px;margin-top:0px;margin-bottom:0px;grid-area:1/1/2/2;position:relative;}'
    . '#comp-motpujyc3 .comp-motpujyc3-container{box-sizing:border-box;position:relative;pointer-events:none;display:var(--l_display,var(--container-display));grid-template-rows:1fr;grid-template-columns:1fr;--container-layout-type:grid-container-layout;--container-display:grid;}'
    . '#comp-motpujyc3:not(.comp-motpujyc3-container){display:var(--l_display,var(--container-display));grid-template-rows:1fr;grid-template-columns:minmax(0, 1fr);--container-display:grid;}'
    . '.p9hNc1{visibility:hidden;grid-area:1/1/2/2}'
    . '.p9hNc1.fABPvj,.p9hNc1.xjQkF3{visibility:visible}'
    . '[id^="comp-motpujyg1__"]{min-height:100%;--l_display:unset;height:auto;min-width:0px;width:100%;max-width:99999px;max-height:99999px;--comp-display:unset;box-sizing:border-box;display:var(--l_display,var(--container-display));grid-template-rows:1fr;grid-template-columns:1fr;--container-layout-type:grid-container-layout;--container-display:grid;pointer-events:auto;margin-left:0px;margin-right:0px;margin-top:0px;margin-bottom:0px;flex-basis:auto;flex-grow:0;flex-shrink:0;position:relative;}';

$resolved = ( new HtmlTransformer() )->transform(
    '<style>' . $slideshowCss . '</style><main>' . $slideshowMarkup() . '</main>'
)->toArray();
$resolvedCss = $css($resolved);
$resolvedBlocks = (string) ($resolved['serialized_blocks'] ?? '');

$assert(
    str_contains($resolvedCss, '#comp-motpujyc3 .comp-motpujyc3-container{box-sizing:border-box;position:relative;pointer-events:none;display:var(--l_display,var(--container-display));grid-template-rows:1fr;grid-template-columns:1fr;--container-layout-type:grid-container-layout;--container-display:grid;}'),
    'the repeater content container (own height:auto, min-height:0px -- not min-content) keeps its fractional row track',
    $resolvedCss
);
$assert(
    str_contains($resolvedCss, '#comp-motpujyc3:not(.comp-motpujyc3-container){display:var(--l_display,var(--container-display));grid-template-rows:1fr;grid-template-columns:minmax(0, 1fr);--container-display:grid;}'),
    'the repeater itself (own height:auto, min-height:0px, align-self:stretch) keeps its fractional row track',
    $resolvedCss
);
$assert(
    (bool) preg_match('/\[id\^="comp-motpujyg1__"\]\{[^}]*grid-template-rows:1fr/', $resolvedCss),
    'the repeater item (own height:auto, percentage min-height:100%) keeps its fractional row track',
    $resolvedCss
);
$assert(
    ! (bool) preg_match('/#comp-motpujyc3(?::not\(\.comp-motpujyc3-container\)| \.comp-motpujyc3-container)\{[^}]*grid-template-rows:min-content/', $resolvedCss)
        && ! (bool) preg_match('/\[id\^="comp-motpujyg1__"\]\{[^}]*grid-template-rows:min-content/', $resolvedCss),
    'none of the three reported-broken selectors have their row track collapsed',
    $resolvedCss
);
$assert(
    str_contains($resolvedBlocks, '<!-- wp:image') && 1 === substr_count($resolvedBlocks, '<!-- wp:image'),
    'the slide image still converts to a native core/image block'
);
$assert(
    ! str_contains($resolvedBlocks, '<!-- wp:html'),
    'the slide subtree produces zero core/html fallback blocks'
);
$assert(
    (bool) preg_match('/\.[a-z0-9-]+>img\{object-position:51% 42%\}/', $resolvedCss),
    'the authored per-slide object-position focal point is carried into the generated CSS unaffected by the height fix',
    $resolvedCss
);

// Same chain, but `#comp-motpujyc3`'s own `min-height` is a real, non-zero
// intrinsic-sizing keyword rather than a literal zero: it genuinely
// disqualifies the chain, and the collapse must still occur exactly as
// before. This proves the zero-min-height carve-out is scoped to a literal
// zero, not every non-definite `min-height`.
$genuinelyIntrinsic = ( new HtmlTransformer() )->transform(
    '<style>'
    . str_replace('#comp-motpujyc3{min-height:0px;', '#comp-motpujyc3{min-height:min-content;', $slideshowCss)
    . '</style><main>' . $slideshowMarkup() . '</main>'
)->toArray();
$assert(
    str_contains($css($genuinelyIntrinsic), '#comp-motpujyc3:not(.comp-motpujyc3-container){display:var(--l_display,var(--container-display));grid-template-columns:minmax(0, 1fr);--container-display:grid;grid-template-rows:min-content}'),
    'a genuinely intrinsic-sizing min-height (min-content) still disqualifies the chain and collapses the row track',
    $css($genuinelyIntrinsic)
);

// Same chain, but `.comp-motpujy96-container`'s own row track is dropped to
// a pure `1fr` (no definite minimum component): with `#comp-motpujy96`
// opted out of stretch (`align-self:center`) and no self-establishing
// track below it either, nothing in the chain is genuinely resolvable, and
// the collapse must still occur -- proving the fix recognizes the
// self-establishing-track route rather than simply disabling the collapse.
$noSelfEstablishingTrack = ( new HtmlTransformer() )->transform(
    '<style>'
    . str_replace(
        'grid-template-rows:minmax(max(0.5px, 0.2790514 * (var(--scaling-factor) - var(--scrollbar-width))),auto);grid-template-columns:minmax(0px,1fr);',
        'grid-template-rows:1fr;grid-template-columns:minmax(0px,1fr);',
        $slideshowCss
    )
    . '</style><main>' . $slideshowMarkup() . '</main>'
)->toArray();
$assert(
    str_contains($css($noSelfEstablishingTrack), '#comp-motpujyc3:not(.comp-motpujyc3-container){display:var(--l_display,var(--container-display));grid-template-columns:minmax(0, 1fr);--container-display:grid;grid-template-rows:min-content}'),
    'with no self-establishing track anywhere in the chain and the top opted out of stretch, the row track still collapses',
    $css($noSelfEstablishingTrack)
);

fwrite(STDOUT, 'Self-establishing grid track geometry unit tests: ' . $assertions . " passed\n");
