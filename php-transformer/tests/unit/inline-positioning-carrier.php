<?php
declare(strict_types=1);

/**
 * Contract for inline layout positioning on the generated geometry carrier.
 *
 * The carrier preserved box sizing (`width`, `height`, margin) but dropped the
 * declarations that place a box: `float`, `position`, the insets and `overflow`.
 * That split positioned constructs in half. A percentage-padding aspect-ratio
 * box kept its spacer and lost the absolutely positioned child meant to fill it,
 * so the source painted an empty gap and stacked the child below it; a float row
 * kept each cell's percentage width and lost `float`, so the cells stacked into
 * a single column.
 *
 * Positioning is preserved, never synthesized: only an element whose own inline
 * style declares it is carried, and `absolute` additionally requires a
 * containing block that is itself carried inline.
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

$transform = static fn (string $html): array => ( new HtmlTransformer() )->transform($html, array())->toArray();

$cssOf = static function (array $result): string {
    return implode("\n", array_map(
        static fn (array $asset): string => (string) ($asset['content'] ?? ''),
        array_values(is_array($result['assets'] ?? null) ? $result['assets'] : array())
    ));
};

/** Every generated carrier rule body in the result, joined. */
$carrierRules = static function (array $result) use ($cssOf): string {
    $css = $cssOf($result);
    if ( ! preg_match_all('/(?<![\w-])\.(be-inline-geometry-[a-f0-9-]+)\{([^}]*)\}/', $css, $matches, PREG_SET_ORDER) ) {
        return '';
    }

    return implode("\n", array_map(static fn (array $match): string => $match[2], $matches));
};

// -- A float row keeps its cells beside one another.
$floatRow = $transform(
    '<div class="row">'
    . '<div style="float:left;width:33.28%;margin:0"><p>One</p></div>'
    . '<div style="float:left;width:33.28%;margin:0"><p>Two</p></div>'
    . '<div style="float:left;width:33.28%;margin:0"><p>Three</p></div>'
    . '</div>'
);
$floatRules = $carrierRules($floatRow);

$sizedFloatRules = array_values(array_filter(
    explode("\n", $floatRules),
    static fn (string $rule): bool => str_contains($rule, 'width:33.28%')
));
$assert(
    3 === count($sizedFloatRules),
    'each floated cell still carries the width the carrier already supported',
    $floatRules
);
$assert(
    $sizedFloatRules === array_values(array_filter($sizedFloatRules, static fn (string $rule): bool => str_contains($rule, 'float:left'))),
    'every rule that sizes a floated cell also carries the float that keeps it in the row',
    $floatRules
);

// -- A percentage-padding aspect box keeps its absolutely positioned child.
$aspectBox = $transform(
    '<div style="position:relative;width:100%;padding:0 0 75%;overflow:hidden">'
    . '<div><a href="/full.jpg"><img src="/thumb.jpg" class="tile" _width="800" _height="600"'
    . ' style="position:absolute;border:0;width:100%;top:0;left:0">'
    . '<div class="caption"><div class="caption-inner"><div class="caption-text">Overlay</div></div></div>'
    . '</a></div>'
    . '</div>'
);
$aspectRules = $carrierRules($aspectBox);

$assert(
    str_contains($aspectRules, 'position:relative'),
    'the aspect-ratio box keeps the positioning that makes it a containing block',
    $aspectRules
);
$assert(
    str_contains($aspectRules, 'overflow:hidden'),
    'the aspect-ratio box keeps the clipping that bounds its positioned child',
    $aspectRules
);
$assert(
    str_contains($aspectRules, 'position:absolute'),
    'the child fills the reserved box instead of returning to flow beneath it',
    $aspectRules
);
$assert(
    str_contains($aspectRules, 'top:0') && str_contains($aspectRules, 'left:0'),
    'the positioned child keeps the insets that place it inside the reserved box',
    $aspectRules
);
$assert(
    str_contains(json_encode($aspectBox['blocks'] ?? array()), '75%'),
    'the reserving half of the construct is still preserved',
    $aspectRules
);

// -- Positioning is preserved, not synthesized.
$staticFlow = $transform('<div class="plain" style="width:50%;margin:0"><p>Copy</p></div>');
$staticRules = $carrierRules($staticFlow);
$assert(
    '' !== $staticRules && ! str_contains($staticRules, 'position:') && ! str_contains($staticRules, 'float:'),
    'an element the source left in flow gains no positioning',
    $staticRules
);

// -- `absolute` without a provable containing block stays in flow.
$unanchored = $transform(
    '<section class="hero"><h1>Wave hero</h1>'
    . '<div class="layer" style="position:absolute;left:0;right:0;bottom:0;opacity:.7"></div>'
    . '<p>Copy</p></section>'
);
$assert(
    ! str_contains($carrierRules($unanchored), 'position:absolute'),
    'an absolutely positioned decorative layer with no carried containing block is not handed to the document',
    $carrierRules($unanchored)
);

// -- `fixed` keeps its existing handling and never reaches the carrier.
$fixedOverlay = $transform(
    '<section class="hero"><h1>Atmospheric hero</h1>'
    . '<div class="grain" style="position:fixed;inset:0;pointer-events:none;opacity:.14"></div>'
    . '</section>'
);
$assert(
    ! str_contains($carrierRules($fixedOverlay), 'position:fixed'),
    'viewport-fixed layers are not pinned to the editor canvas through the carrier',
    $carrierRules($fixedOverlay)
);

// -- `position:relative` needs no containing block of its own.
$relativeOnly = $transform('<div class="shift" style="position:relative;margin:5px"><p>Copy</p></div>');
$assert(
    str_contains($carrierRules($relativeOnly), 'position:relative'),
    'relative positioning stays in flow and is carried without an ancestor requirement',
    $carrierRules($relativeOnly)
);

if ( 0 < $failures ) {
    fwrite(STDERR, sprintf('inline positioning carrier contract FAILED: %d passed, %d failed%s', $passes, $failures, PHP_EOL));
    exit(1);
}

echo sprintf('Inline positioning carrier contract passed: %d assertions%s', $passes, PHP_EOL);
