<?php
declare(strict_types=1);

/**
 * An inline-block anchor centered only through an inherited ancestor
 * `text-align` becomes a core/buttons flex wrapper whose default main-axis
 * alignment is left. The inherited alignment must be restated as
 * layout.justifyContent or the source's centering is lost. The restated
 * justification only has room to act when the wrapper fills its parent, so
 * the generated wrapper rule must not shrink-wrap a centered wrapper.
 *
 * Regression for Automattic/blocks-engine#2150: the wrapper consulted only the
 * element and its immediate parent, so an anchor nested more than one level
 * under a `text-align:center` container compiled left-aligned. The follow-up
 * render check found the container still shrink-wrapping to its button's own
 * width, which left the button at its parent's left edge in WordPress.
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

$transform = static fn (string $html): array =>
    ( new HtmlTransformer() )->transform($html)->toArray();

$buttonsBlockFor = static function (string $markup, string $label): string {
    $start = strpos($markup, '<!-- wp:buttons');
    while ( false !== $start ) {
        $end = strpos($markup, '<!-- /wp:buttons -->', $start);
        $chunk = false === $end ? '' : substr($markup, $start, $end - $start);
        if ( str_contains($chunk, $label) ) {
            return $chunk;
        }
        $start = strpos($markup, '<!-- wp:buttons', $start + 1);
    }

    return '';
};

$cssFor = static function (array $out): string {
    $css = '';
    foreach ( $out['assets'] ?? array() as $asset ) {
        if ( is_array($asset) && 'css' === ( $asset['kind'] ?? '' ) ) {
            $css .= (string) ( $asset['content'] ?? '' );
        }
    }
    return $css;
};

$controlMarkerOf = static function (string $buttonsBlock): string {
    return 1 === preg_match('/blocks-engine-control-[a-z0-9-]+/', $buttonsBlock, $m) ? $m[0] : '';
};

$style = '<style>'
    . '.text-center{text-align:center}'
    . '.text-right{text-align:right}'
    . '.text-left{text-align:left}'
    . '.cta{display:inline-block;background:#111;color:#fff;padding:14px 40px;border-radius:8px}'
    . '</style>';

$controlMarkup = '<div class="mt-8"><a class="cta" href="https://example.com/">Subscribe</a></div>';

// The issue shape: the anchor's own parent carries no text-align; the centering
// comes from the grandparent and inherits down to the inline control.
$centeredOut = $transform(
    $style
    . '<div class="text-center"><h2>Stay in the Loop</h2>'
    . $controlMarkup
    . '</div>'
);
$centered = (string) ( $centeredOut['serialized_blocks'] ?? '' );
$centeredButtons = $buttonsBlockFor($centered, 'Subscribe');

$assert(
    '' !== $centeredButtons && str_contains($centeredButtons, '<!-- wp:buttons'),
    'the inherited-center anchor is recognized as a core/buttons block',
    $centered
);
$assert(
    1 === preg_match('/"layout":\{"type":"flex","justifyContent":"center"\}/', $centeredButtons),
    'an anchor centered through a grandparent text-align:center justifies its core/buttons wrapper center',
    $centeredButtons
);

// The restated justification has no room to act on a shrink-wrapped wrapper:
// the generated wrapper rule must fill the parent instead, the way the source
// block the control sat in did, while the button itself still hugs its label.
$centeredMarker = $controlMarkerOf($centeredButtons);
$centeredCss = $cssFor($centeredOut);
$assert(
    '' !== $centeredMarker,
    'the centered control carries a generated control marker',
    $centeredButtons
);
$assert(
    1 === preg_match('/' . preg_quote($centeredMarker, '/') . '\.' . preg_quote($centeredMarker, '/') . '\.wp-block-buttons\{width:100%;max-width:100%\}/', $centeredCss),
    'a centered inline control fills its parent buttons wrapper instead of shrink-wrapping it',
    $centeredCss
);
$assert(
    ! preg_match('/' . preg_quote($centeredMarker, '/') . '\.' . preg_quote($centeredMarker, '/') . '\.wp-block-buttons\{width:max-content/', $centeredCss),
    'the centered buttons wrapper is not pinned to max-content',
    $centeredCss
);
$assert(
    1 === preg_match('/' . preg_quote($centeredMarker, '/') . '\.' . preg_quote($centeredMarker, '/') . '\.wp-block-button\{width:max-content;max-width:100%\}/', $centeredCss),
    'the button itself still shrink-wraps its label inside the filled wrapper',
    $centeredCss
);

$rightAlignedOut = $transform(
    $style
    . '<div class="text-right"><div class="wrap">'
    . $controlMarkup
    . '</div></div>'
);
$rightAligned = (string) ( $rightAlignedOut['serialized_blocks'] ?? '' );
$assert(
    1 === preg_match('/"layout":\{"type":"flex","justifyContent":"right"\}/', $buttonsBlockFor($rightAligned, 'Subscribe')),
    'right inheritance maps to justifyContent right the same way',
    $rightAligned
);

// The nearest explicit declaration wins, matching CSS inheritance: a closer
// left declaration on the intermediate wrapper overrides the centered ancestor.
$nearestWinsOut = $transform(
    $style
    . '<div class="text-center"><div class="text-left">'
    . $controlMarkup
    . '</div></div>'
);
$nearestWins = (string) ( $nearestWinsOut['serialized_blocks'] ?? '' );
$assert(
    ! str_contains($buttonsBlockFor($nearestWins, 'Subscribe'), '"justifyContent":"center"'),
    'a nearer text-align declaration wins over the more distant ancestor',
    $nearestWins
);

// Without any inherited alignment the wrapper keeps the default left geometry.
$leftDefaultOut = $transform(
    $style
    . '<div><div class="mt-8">'
    . $controlMarkup
    . '</div></div>'
);
$leftDefault = (string) ( $leftDefaultOut['serialized_blocks'] ?? '' );
$leftDefaultButtons = $buttonsBlockFor($leftDefault, 'Subscribe');
$assert(
    '' !== $leftDefaultButtons
    && ! str_contains($leftDefaultButtons, '"justifyContent"'),
    'a button without inherited centering keeps the default left-aligned wrapper',
    $leftDefault
);
// A non-centered inline control keeps its intrinsic shrink-to-fit geometry:
// nothing about the centered case may widen unrelated wrappers.
$leftDefaultMarker = $controlMarkerOf($leftDefaultButtons);
$leftDefaultCss = $cssFor($leftDefaultOut);
$assert(
    '' !== $leftDefaultMarker
    && 1 === preg_match('/' . preg_quote($leftDefaultMarker, '/') . '\.' . preg_quote($leftDefaultMarker, '/') . '\.wp-block-buttons\{width:max-content;max-width:100%\}/', $leftDefaultCss),
    'a non-centered inline control keeps its intrinsic shrink-to-fit wrapper',
    $leftDefaultCss
);

if ( $failures ) {
    fwrite(STDERR, $failures . " inherited text-align button justification test(s) failed\n");
    exit(1);
}

echo 'Inherited text-align button justification tests: ' . $passes . " passed\n";
