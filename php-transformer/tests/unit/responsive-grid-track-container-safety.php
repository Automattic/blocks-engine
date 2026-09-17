<?php
declare(strict_types=1);

/**
 * Regression coverage for a real imported-page bug: an inline
 * `display:grid` carrier whose `grid-template-columns` is a single desktop
 * measurement (a resolved pixel width, not authored responsive CSS) rode to
 * the generated stylesheet as an unconditional absolute length. Inside a
 * container narrower than that measurement — the ordinary case at a phone
 * viewport — the track could not shrink and the grid child overflowed its
 * own container, producing horizontal page scroll.
 *
 * Captured from a real import of a two-column "work sample" grid: the
 * source collapsed to one column under its own media query at 375px
 * (`grid-template-columns: 327px`, matching its 327px container exactly),
 * but WordPress carried the desktop-resolved `515.562px` unconditionally
 * into a 327px container, producing a 516px-wide card and a 540px document.
 *
 * Automattic/blocks-engine#1895 ("Give carried presentation a
 * condition-aware representation"), #1893 ("Run parity fixtures at more
 * than one viewport"), and #1898 ("Carry author intent without flattening
 * it") describe this class of bug. This fixture is the concrete instance:
 * a carried track must remain intrinsically container-safe, not merely
 * correct at the viewport it happened to be measured at.
 *
 * The fix wraps each bare absolute-length track (`px`, `rem`, …) in
 * `min(<track>, 100%)` — the same idiom WordPress core already uses to
 * keep a fixed `minimumColumnWidth` container-safe
 * (`repeat(auto-fill, minmax(min(<width>, 100%), 1fr))`). A grid child can
 * therefore never exceed its own container's width, regardless of which
 * viewport the carried measurement came from.
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

$cards = '<div><p>One</p></div><div><p>Two</p></div>';

// -- The real bug: a single desktop-measured pixel track, carried inline,
// must become container-safe rather than an unconditional absolute length.
$measured = $transform(
    '<section class="work-samples" style="display:grid;grid-template-columns:515.562px;gap:24px">' . $cards . '</section>'
);
$measuredMarkup = (string) ($measured['serialized_blocks'] ?? '');
$measuredCss = $cssFor($measured, 'engine-support');

$assert(
    str_contains($measuredMarkup, 'blocks-engine-css-owned-grid'),
    'a single-track measured grid stays on the css-owned-grid carrier path',
    $measuredMarkup
);
$assert(
    str_contains($measuredCss, 'grid-template-columns:min(515.562px, 100%)'),
    'the carried desktop measurement is wrapped in min(<track>, 100%) so it cannot exceed its container',
    $measuredCss
);
$assert(
    ! (bool) preg_match('/grid-template-columns:515\.562px(?!\s*,)/', $measuredCss),
    'no bare, unconditional 515.562px track reaches the generated stylesheet',
    $measuredCss
);

// -- Two asymmetric fixed tracks (the desktop "456px 456px" shape from the
// captured import): every bare-length track is wrapped independently.
$twoTrack = $transform(
    '<section class="work-samples" style="display:grid;grid-template-columns:456px 456px;gap:24px">' . $cards . '</section>'
);
$twoTrackCss = $cssFor($twoTrack, 'engine-support');
$assert(
    str_contains($twoTrackCss, 'grid-template-columns:min(456px, 100%) min(456px, 100%)'),
    'each fixed-length track in a multi-column list is independently container-clamped',
    $twoTrackCss
);

// -- Font-relative lengths (rem) are just as container-agnostic as px and
// must be clamped the same way; an already-fractional companion track
// (1fr) is content to fill remaining space and stays untouched.
$remTrack = $transform(
    '<section class="rem-grid" style="display:grid;grid-template-columns:8rem 1fr;gap:16px">' . $cards . '</section>'
);
$remTrackCss = $cssFor($remTrack, 'engine-support');
$assert(
    str_contains($remTrackCss, 'grid-template-columns:min(8rem, 100%) 1fr'),
    'a bare rem track is clamped exactly like a bare px track, and the fr track beside it is left alone',
    $remTrackCss
);

// -- Already-adaptive tracks are not touched: wrapping a percentage,
// fraction, or function-based track in min(x, 100%) would be a no-op at
// best and would corrupt named line groups / repeat() syntax at worst.
$adaptive = $transform(
    '<section class="adaptive-grid" style="display:grid;grid-template-columns:30% minmax(0,1fr) repeat(auto-fit, minmax(120px, 1fr)) auto min-content;gap:8px">'
    . $cards . '<div><p>Three</p></div><div><p>Four</p></div><div><p>Five</p></div>'
    . '</section>'
);
$adaptiveCss = $cssFor($adaptive, 'engine-support');
$assert(
    str_contains($adaptiveCss, 'grid-template-columns:30% minmax(0,1fr) repeat(auto-fit, minmax(120px, 1fr)) auto min-content'),
    'percentage, minmax(), repeat(), auto, and min-content tracks are carried verbatim',
    $adaptiveCss
);

// -- Keyword-only values pass through untouched.
$masonry = $transform(
    '<section class="masonry-grid" style="display:grid;grid-template-columns:masonry;gap:8px">' . $cards . '</section>'
);
$masonryCss = $cssFor($masonry, 'engine-support');
$assert(
    str_contains($masonryCss, 'grid-template-columns:masonry'),
    'the masonry keyword is not mistaken for a length and passes through unchanged',
    $masonryCss
);

// -- Blast-radius control: a class-owned (author stylesheet) grid keeps its
// own fixed tracks byte-for-byte, including under its own media query. The
// fix only touches inline-carried measurements, never the source's own
// authored, already-responsive CSS (#1898: do not flatten author intent).
$classOwned = $transform(
    '<style>.work-samples{display:grid;grid-template-columns:456px 456px;gap:24px}'
    . '@media(max-width:600px){.work-samples{grid-template-columns:1fr}}</style>'
    . '<section class="work-samples">' . $cards . '</section>'
);
$classOwnedCss = implode("\n", array_map(
    static fn (array $asset): string => (string) ($asset['content'] ?? ''),
    is_array($classOwned['assets'] ?? null) ? $classOwned['assets'] : array()
));
$assert(
    str_contains($classOwnedCss, '.work-samples{display:grid;grid-template-columns:456px 456px;gap:24px}'),
    'a class-owned author rule keeps its own fixed tracks unmodified',
    $classOwnedCss
);
$assert(
    str_contains($classOwnedCss, '@media(max-width:600px){.work-samples{grid-template-columns:1fr}}'),
    'the source stylesheet keeps its own collapse media query untouched — it never needed this fix',
    $classOwnedCss
);

if ( $failures > 0 ) {
    fwrite(STDERR, "Responsive grid track container safety: {$failures} failed, {$passes} passed\n");
    exit(1);
}

fwrite(STDOUT, "Responsive grid track container safety passed: {$passes} assertions\n");
