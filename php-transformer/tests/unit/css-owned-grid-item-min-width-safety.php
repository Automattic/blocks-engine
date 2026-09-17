<?php
declare(strict_types=1);

/**
 * Regression coverage for a real imported-page bug measured against a live
 * import (a two-column "work sample" card grid, source
 * `https://dwvirtualassistant.lovable.app/`): six cards rendered 516px wide
 * inside a 327px container at a 375px viewport, producing horizontal page
 * scroll. The source never scrolls sideways at the same viewport.
 *
 * The initial diagnosis assumed a carried, unconditional absolute-length
 * `grid-template-columns` declaration. Direct inspection of a real import —
 * both the live DOM (`getComputedStyle`) and every generated stylesheet
 * asset — disproved that: no rule anywhere sets `grid-template-columns` on
 * the offending `.blocks-engine-css-owned-grid` container. The computed
 * `515.562px` the browser reports is the *default* CSS Grid auto-track
 * sizing outcome, not a carried value.
 *
 * The real mechanism: the source's grid track collapses to a single,
 * unauthored implicit column below its own `sm:`/`md:`-style breakpoint
 * (ordinary Tailwind `grid` + a responsive `grid-cols-N` variant — already
 * fully author-owned and already correct). An implicit CSS Grid track's
 * default sizing function is `minmax(auto, auto)`; the *minimum* resolves to
 * the item's min-content size unless something gives the item an
 * "automatic minimum size" of zero (CSS Sizing ยง4.1 groundwork: `overflow`
 * other than `visible` is one such trigger). The source gets that for free
 * because the card's oversized, aspect-ratio'd image sits inside a wrapper
 * `<div class="overflow-hidden ...">`. Converting the page to blocks
 * coalesces that single-purpose wrapper into its parent (an already-accepted,
 * already-diagnosed topology change — see WrapperCoalescer), carrying the
 * wrapper's *visual* declarations but not this load-bearing sizing side
 * effect. With nothing left to zero the item's automatic minimum, the grid
 * item's min-content size (driven by the image's own aspect-ratio and
 * intrinsic dimensions, independent of its `width:100%` CSS) forces the
 * implicit track — and so the item itself — wider than the container.
 *
 * The fix restores the same safe default the source had via a
 * zero-specificity `min-width:0` on every direct child of a
 * `blocks-engine-css-owned-grid` container (EngineSupportCss::beforeAuthorCss()).
 * This is content- and viewport-independent: it does not special-case
 * mobile or hardcode a breakpoint, and an author rule that deliberately
 * wants a non-zero min-width on a specific item still wins (`:where()` is
 * zero specificity).
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

$failures = 0;
$passes = 0;

$assert = static function (bool $condition, string $message, string $detail = '') use (&$failures, &$passes): void {
    if ( $condition ) {
        ++$passes;
        return;
    }

    ++$failures;
    fwrite(STDERR, 'FAIL: ' . $message . ('' !== $detail ? ' - ' . $detail : '') . PHP_EOL);
};

$cssFor = static function (array $result, string $source): string {
    return implode("\n", array_map(
        static fn (array $asset): string => (string) ($asset['content'] ?? ''),
        array_values(array_filter(
            is_array($result['assets'] ?? null) ? $result['assets'] : array(),
            static fn (array $asset): bool => $source === ($asset['source'] ?? '')
        ))
    ));
};

$transform = static fn (string $html): array => ( new HtmlTransformer() )->transform($html, array())->toArray();

// The shape captured from the real import: a base `grid` with a
// min-width-gated two-column variant (the source's own, already-correct
// responsive collapse), whose cards hold an aspect-ratio'd image inside an
// `overflow-hidden` wrapper.
$css = '.work-samples{display:grid;gap:2rem}'
    . '@media(min-width:640px){.work-samples{grid-template-columns:repeat(2,minmax(0,1fr))}}'
    . '.frame{overflow:hidden;border:1px solid #ccc}'
    . '.shot{aspect-ratio:8/5;width:100%;object-fit:cover}';
$html = '<section class="work-samples">'
    . '<figure><div class="frame"><img src="one.png" width="1600" height="1000" class="shot"></div><figcaption>One</figcaption></figure>'
    . '<figure><div class="frame"><img src="two.png" width="1600" height="1000" class="shot"></div><figcaption>Two</figcaption></figure>'
    . '</section>';

$result = $transform('<style>' . $css . '</style>' . $html);
$markup = (string) ($result['serialized_blocks'] ?? '');
$beforeAuthorCss = implode("\n", array_map(
    static fn (array $asset): string => (string) ($asset['content'] ?? ''),
    array_values(array_filter(
        is_array($result['assets'] ?? null) ? $result['assets'] : array(),
        static fn (array $asset): bool => 'engine-support' === ($asset['source'] ?? '')
            && 'before-author' === ($asset['stylesheet_placement'] ?? '')
    ))
));
$authorCss = $cssFor($result, 'author-css');

$assert(
    str_contains($markup, 'blocks-engine-css-owned-grid'),
    'the base grid (no minimumColumnWidth-eligible track list) stays on the css-owned-grid carrier path',
    $markup
);
$assert(
    str_contains($beforeAuthorCss, ':root :where(.blocks-engine-css-owned-grid)>*{min-width:0}'),
    'a zero-specificity min-width:0 safety net is emitted for every css-owned-grid direct child',
    $beforeAuthorCss
);
$assert(
    ! str_contains($authorCss, ':root :where(.blocks-engine-css-owned-grid)>*{min-width:0}'),
    'the safety net lives in engine-support, not the author stylesheet',
    $authorCss
);
$assert(
    str_contains($authorCss, '.work-samples{display:grid;gap:2rem}')
        && str_contains($authorCss, '@media(min-width:640px){.work-samples{grid-template-columns:repeat(2,minmax(0,1fr))}}'),
    'the source grid and its own responsive collapse ride to the author stylesheet completely untouched',
    $authorCss
);

// Control: a container with NO css-owned-grid marker (an ordinary flow
// group) must not receive the grid-scoped safety net at all.
$flowOnly = $transform('<style>.stack{display:block}</style><div class="stack"><p>One</p><p>Two</p></div>');
$flowBeforeCss = implode("\n", array_map(
    static fn (array $asset): string => (string) ($asset['content'] ?? ''),
    array_values(array_filter(
        is_array($flowOnly['assets'] ?? null) ? $flowOnly['assets'] : array(),
        static fn (array $asset): bool => 'engine-support' === ($asset['source'] ?? '')
            && 'before-author' === ($asset['stylesheet_placement'] ?? '')
    ))
));
$assert(
    ! str_contains($flowBeforeCss, 'blocks-engine-css-owned-grid'),
    'a document with no css-owned grid never emits the grid item safety net',
    $flowBeforeCss
);

if ( $failures > 0 ) {
    fwrite(STDERR, "css-owned-grid item min-width safety: {$failures} failed, {$passes} passed\n");
    exit(1);
}

fwrite(STDOUT, "css-owned-grid item min-width safety passed: {$passes} assertions\n");
