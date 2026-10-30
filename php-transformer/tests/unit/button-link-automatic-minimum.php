<?php
declare(strict_types=1);

/**
 * Converted button links keep the source anchor's automatic minimum size
 * (issue #3623).
 *
 * A row of `white-space:nowrap`, padded button links inside a grid item
 * sizes its tracks from the row's min-content width: each anchor's
 * `min-width:auto` resolves to its full nowrap label, and an `fr` track's
 * `auto` minimum adopts that contribution. The direct-flex bridge used to
 * pin `min-width:0` on the participating `.wp-block-buttons` wrapper, so
 * the wrapper — and with it the grid item's min-content contribution —
 * could collapse below the labels, and `fr` tracks resolved to a pure
 * ratio split that no source grid would produce (the hero image widened
 * and the heading re-wrapped).
 *
 * The neutral fixture below asserts the projected wrapper chain keeps the
 * source's automatic minimum (no `min-width:0` on the participating
 * wrapper) while the source grid geometry rides to the author stylesheet
 * untouched. When the source suppresses its own automatic minimum — an
 * explicit zero `min-width`, or an inline-axis `overflow` other than
 * `visible` — the emitted wrapper must restate `min-width:0`, because the
 * authored zero rides the projected link rule and the fresh wrapper box
 * would otherwise floor at its content.
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

$transform = static fn (string $html): array => ( new HtmlTransformer() )->transform($html)->toArray();
$cssOf = static function (array $out): string {
    $css = '';
    foreach ( $out['assets'] ?? array() as $asset ) {
        if ( is_array($asset) && 'css' === ( $asset['kind'] ?? '' ) ) {
            $css .= (string) ( $asset['content'] ?? '' );
        }
    }
    return $css;
};
/** The direct-flex bridge's participating wrapper rule. */
$wrapperRules = static function (string $css): array {
    return array_values(array_unique((array) preg_match_all(
        '/:where\(\.blocks-engine-control-[a-z0-9-]+\.wp-block-buttons\)\{display:block[^}]*\}/',
        $css,
        $matches
    ) ? $matches[0] : array()));
};

// The neutral reduction: nowrap padded button links in a flex row inside
// the text column of a `1.15fr .85fr` hero grid. The caption keeps the
// image column out of the media-text pattern so the source grid survives
// as a grid.
$style = '.g{display:grid;grid-template-columns:1.15fr .85fr;gap:48px;width:728px}'
    . '.row{display:flex;gap:12px}.b{display:inline-flex;white-space:nowrap;padding:0 28px;height:44px;align-items:center;background:#3a6e9e;color:#fff;font-size:15px}'
    . 'img{width:100%;display:block}';
$html = '<section class="g"><div><h1>Structural advice for your project</h1>'
    . '<div class="row"><a class="b" href="#c">Schedule a free consultation</a><a class="b" href="#s">View services</a></div></div>'
    . '<div><img src="hero.png" width="800" height="800" alt=""><p>Captured at the site.</p></div></section>';

$out = $transform('<style>' . $style . '</style>' . $html);
$markup = (string) ( $out['serialized_blocks'] ?? '' );
$css = $cssOf($out);

$assert(
    str_contains($markup, 'wp:buttons') && str_contains($markup, 'Schedule a free consultation') && str_contains($markup, 'View services'),
    '1: both nowrap hero buttons convert to core buttons',
    $markup
);
$assert(
    str_contains($css, '.g{display:grid;grid-template-columns:1.15fr .85fr;gap:48px;width:728px}')
        && str_contains($css, '.row{display:flex;gap:12px}'),
    '2: the source grid and flex geometry ride to the author stylesheet untouched',
    $css
);
$assert(
    array() !== $wrapperRules($css),
    '3: the direct-flex bridge projects a participating wrapper rule',
    $css
);
$assert(
    array() === array_filter($wrapperRules($css), static fn (string $rule): bool => str_contains($rule, 'min-width:0')),
    '4: the participating wrapper keeps the source automatic minimum instead of min-width:0',
    implode("\n", $wrapperRules($css))
);
$assert(
    (bool) preg_match('/\.wp-block-button\)\{display:block!important;margin:0!important;min-width:0\}/', $css),
    '5: the synthetic inner wrapper keeps its neutral margin reset',
    $css
);

// Grid-parent variant: the anchor is itself a grid item, so the wrapper
// stands in for it there too and must keep the same automatic minimum.
$gridItem = $transform(
    '<style>.cards{display:grid;grid-template-columns:auto auto;gap:24px}.b{display:block;white-space:nowrap;padding:0 28px;height:44px;background:#3a6e9e;color:#fff}</style>'
    . '<main><div class="cards"><a class="b" href="#c">Schedule a free consultation</a><a class="b" href="#s">View services</a></div></main>'
);
$gridItemCss = $cssOf($gridItem);
$assert(
    array() !== $wrapperRules($gridItemCss)
        && array() === array_filter($wrapperRules($gridItemCss), static fn (string $rule): bool => str_contains($rule, 'min-width:0')),
    '6: a direct grid-item anchor keeps the automatic minimum on its participating wrapper',
    $gridItemCss
);

// The zero stays when the source itself suppresses the automatic minimum:
// the authored floor rides the projected link, so an unzeroed fresh wrapper
// box would floor at its content and size larger than the source did.
$suppressed = array(
    'overflow:hidden' => '.b{display:inline-flex;white-space:nowrap;padding:0 28px;height:44px;background:#3a6e9e;color:#fff;overflow:hidden}',
    'overflow-x:hidden' => '.b{display:inline-flex;white-space:nowrap;padding:0 28px;height:44px;background:#3a6e9e;color:#fff;overflow-x:hidden}',
    'min-width:0' => '.b{display:inline-flex;white-space:nowrap;padding:0 28px;height:44px;background:#3a6e9e;color:#fff;min-width:0}',
    'shorthand overflow:hidden visible' => '.b{display:inline-flex;white-space:nowrap;padding:0 28px;height:44px;background:#3a6e9e;color:#fff;overflow:hidden visible}',
);
foreach ( $suppressed as $label => $buttonCss ) {
    $suppressionOut = $transform('<style>.row{display:flex;gap:12px}' . $buttonCss . '</style><main><div class="row"><a class="b" href="#c">Schedule a free consultation</a></div></main>');
    $suppressionCss = $cssOf($suppressionOut);
    $suppressionRules = $wrapperRules($suppressionCss);
    $assert(
        array() !== $suppressionRules
            && array() !== array_filter($suppressionRules, static fn (string $rule): bool => str_contains($rule, 'min-width:0')),
        "7: the wrapper restates min-width:0 when the source suppresses its own minimum ({$label})",
        $suppressionCss
    );
}

// A nonzero explicit min-width rides the projected link rule and raises the
// chain floor above min-content, so the wrapper needs no floor of its own.
$explicitFloor = $transform(
    '<style>.row{display:flex;gap:12px}.b{display:inline-flex;white-space:nowrap;padding:0 28px;height:44px;background:#3a6e9e;color:#fff;min-width:120px}</style>'
    . '<main><div class="row"><a class="b" href="#c">Schedule a free consultation</a></div></main>'
);
$explicitFloorCss = $cssOf($explicitFloor);
$explicitFloorRules = $wrapperRules($explicitFloorCss);
$assert(
    array() !== $explicitFloorRules
        && array() === array_filter($explicitFloorRules, static fn (string $rule): bool => str_contains($rule, 'min-width:0')),
    '8: an explicit nonzero source min-width needs no wrapper floor',
    $explicitFloorCss
);
$assert(
    str_contains($explicitFloorCss, 'min-width:120px'),
    '9: the explicit source min-width still projects onto the link',
    $explicitFloorCss
);

if ( $failures > 0 ) {
    fwrite(STDERR, PHP_EOL . "button link automatic minimum tests: {$passes} passed, {$failures} FAILED" . PHP_EOL);
    exit(1);
}
fwrite(STDOUT, "button link automatic minimum tests: {$passes} passed" . PHP_EOL);
