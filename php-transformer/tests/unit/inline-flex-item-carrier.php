<?php
declare(strict_types=1);

/**
 * Contract for inline flex-ITEM sizing on children of an authored flex row.
 *
 * `inlineGeometryProperties()` allow-listed `flex-basis` but not the `flex`
 * shorthand, and `cssDeclarations()` performs no shorthand expansion, so an
 * authored `flex:1 1 20rem` produced a `flex` key no property loop ever read.
 * The column kept its `min-width` and lost its basis, fell back to
 * `flex:0 1 auto`, and under `flex-wrap:wrap` took a flex line alone.
 *
 * `flex` resets `flex-basis`, so a document authoring both must keep source
 * order in the emitted rule or the later declaration silently loses.
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

/** Every generated geometry carrier rule body, in emission order. */
$carrierRules = static function (string $css): array {
    if ( ! preg_match_all('/(?<![\w-])\.(be-inline-geometry-[a-f0-9-]+)\{([^}]*)\}/', $css, $matches, PREG_SET_ORDER) ) {
        return array();
    }

    return array_map(static fn (array $match): string => (string) $match[2], $matches);
};

/** The first carrier rule body carrying a declaration, '' when none does. */
$ruleWith = static function (array $rules, string $needle): string {
    foreach ( $rules as $rule ) {
        if ( str_contains($rule, $needle) ) {
            return $rule;
        }
    }

    return '';
};

$textColumn = '<blockquote><p>The kiln remembers every firing.</p></blockquote>'
    . '<p>Amber glaze pulls copper into the surface across a long reduction cycle.</p>'
    . '<p>The second firing settles the crackle into a stable, repeatable pattern.</p>';

// -- Defect: both columns of an authored flex row lose `flex:1 1 20rem`.
$row = $transform(
    '<section><div style="display:flex;flex-wrap:wrap;gap:2rem;align-items:center">'
    . '<div style="flex:1 1 20rem;min-width:min(100%,18rem)">' . $textColumn . '</div>'
    . '<div style="flex:1 1 20rem;min-width:min(100%,18rem)"><p>Studio notes from the second firing.</p></div>'
    . '</div></section>'
);
$rowCss = $cssFor($row, 'engine-support');
$rowRules = $carrierRules($rowCss);
$basisRule = $ruleWith($rowRules, 'flex:1 1 20rem');

$assert(
    '' !== $basisRule,
    'flex shorthand: an authored flex:1 1 20rem reaches the generated geometry rule',
    $rowCss
);
$assert(
    str_contains($basisRule, 'flex:1 1 20rem !important'),
    'flex shorthand: the carried shorthand rides the !important geometry tier',
    '' !== $basisRule ? $basisRule : $rowCss
);
$assert(
    str_contains($basisRule, 'min-width:min(100%,18rem) !important'),
    'flex shorthand: the shorthand joins the min-width already carried on the same column',
    '' !== $basisRule ? $basisRule : $rowCss
);
$assert(
    2 === count(array_filter($rowRules, static fn (string $rule): bool => str_contains($rule, 'flex:1 1 20rem !important'))),
    'flex shorthand: both authored columns carry the shorthand, not just the first',
    $rowCss
);

// -- Hazard 1, order A: the shorthand resets the longhand, so a later
// flex-basis must still win. Source order decides last-write-wins here.
$shorthandFirst = $transform(
    '<section><div style="display:flex;flex-wrap:wrap;gap:1rem">'
    . '<div style="flex:1 1 auto;flex-basis:20rem"><p>Order A column copy.</p></div>'
    . '<div><p>Sibling copy.</p></div>'
    . '</div></section>'
);
$shorthandFirstCss = $cssFor($shorthandFirst, 'engine-support');
$shorthandFirstRule = $ruleWith($carrierRules($shorthandFirstCss), 'flex:1 1 auto');

$assert(
    '' !== $shorthandFirstRule && str_contains($shorthandFirstRule, 'flex-basis:20rem'),
    'order A: both the shorthand and the longhand reach the rule',
    '' !== $shorthandFirstRule ? $shorthandFirstRule : $shorthandFirstCss
);
$assert(
    '' !== $shorthandFirstRule
        && strpos($shorthandFirstRule, 'flex:1 1 auto') < strpos($shorthandFirstRule, 'flex-basis:20rem'),
    'order A: flex authored first is emitted first, so the later flex-basis still wins',
    '' !== $shorthandFirstRule ? $shorthandFirstRule : $shorthandFirstCss
);

// -- Hazard 1, order B: the reverse document order must emit in reverse too,
// or the shorthand loses the reset the author asked for.
$longhandFirst = $transform(
    '<section><div style="display:flex;flex-wrap:wrap;gap:1rem">'
    . '<div style="flex-basis:20rem;flex:1 1 auto"><p>Order B column copy.</p></div>'
    . '<div><p>Sibling copy.</p></div>'
    . '</div></section>'
);
$longhandFirstCss = $cssFor($longhandFirst, 'engine-support');
$longhandFirstRule = $ruleWith($carrierRules($longhandFirstCss), 'flex:1 1 auto');

$assert(
    '' !== $longhandFirstRule && str_contains($longhandFirstRule, 'flex-basis:20rem'),
    'order B: both the longhand and the shorthand reach the rule',
    '' !== $longhandFirstRule ? $longhandFirstRule : $longhandFirstCss
);
$assert(
    '' !== $longhandFirstRule
        && strpos($longhandFirstRule, 'flex-basis:20rem') < strpos($longhandFirstRule, 'flex:1 1 auto'),
    'order B: flex-basis authored first is emitted first, so the later shorthand still wins',
    '' !== $longhandFirstRule ? $longhandFirstRule : $longhandFirstCss
);

// -- The other two item longhands are carried on the same tier.
$growShrink = $transform(
    '<section><div style="display:flex;flex-wrap:wrap;gap:1rem">'
    . '<div style="flex-grow:2;flex-shrink:0"><p>Grow and shrink column copy.</p></div>'
    . '<div><p>Sibling copy.</p></div>'
    . '</div></section>'
);
$growShrinkCss = $cssFor($growShrink, 'engine-support');
$growShrinkRule = $ruleWith($carrierRules($growShrinkCss), 'flex-grow:2');

$assert(
    str_contains($growShrinkRule, 'flex-grow:2 !important'),
    'longhands: an authored flex-grow is carried on the !important geometry tier',
    '' !== $growShrinkRule ? $growShrinkRule : $growShrinkCss
);
$assert(
    str_contains($growShrinkRule, 'flex-shrink:0 !important'),
    'longhands: an authored flex-shrink is carried on the !important geometry tier',
    '' !== $growShrinkRule ? $growShrinkRule : $growShrinkCss
);

// -- core/media-text already consumes the media child's sizing to derive the
// split ratio. The media child is absorbed into the block attributes and is
// never serialized, so it must not also receive a carrier: that would apply
// sizing twice, once as an attribute and once as !important CSS.
$mediaText = $transform(
    '<section style="display:flex;gap:2rem;align-items:center">'
    . '<div style="flex:0 0 40%;flex-basis:40%"><img src="hero.jpg" alt="Hero" width="800" height="600"></div>'
    . '<div><h2>Studio</h2><p>Body copy long enough to bear text in the media-text pattern.</p></div>'
    . '</section>'
);
$mediaTextMarkup = (string) ($mediaText['serialized_blocks'] ?? '');
$mediaTextRules = $carrierRules($cssFor($mediaText, 'engine-support'));

$assert(
    str_contains($mediaTextMarkup, 'wp:media-text'),
    'media-text: the fixture still reaches the core/media-text pattern',
    substr($mediaTextMarkup, 0, 200)
);
$assert(
    str_contains($mediaTextMarkup, '"mediaWidth":40'),
    'media-text: the split ratio is still derived from the media child',
    substr($mediaTextMarkup, 0, 200)
);
$assert(
    '' === $ruleWith($mediaTextRules, 'flex:0 0 40%'),
    'media-text: the consumed media child gets no carrier, so sizing is never applied twice',
    implode(' | ', $mediaTextRules)
);

// -- A bare <img> serializes inside a generated <figure>, and that figure is
// the element occupying the flex-child slot. The carrier has to land on the
// wrapper that is actually the flex item, not on the source node.
$injectedFigure = $transform(
    '<div style="display:flex;gap:1rem">'
    . '<img style="flex:0 0 200px" src="a.jpg" alt="A" width="200" height="200">'
    . '<p>Sibling copy that keeps the row from collapsing.</p>'
    . '<p>Third sibling so the media-text pattern does not claim the row.</p>'
    . '</div>'
);
$injectedFigureMarkup = (string) ($injectedFigure['serialized_blocks'] ?? '');
$injectedFigureCss = $cssFor($injectedFigure, 'engine-support');

$assert(
    str_contains($ruleWith($carrierRules($injectedFigureCss), 'flex:0 0 200px'), 'flex:0 0 200px !important'),
    'injected figure: the image sizing survives into a carrier rule',
    $injectedFigureCss
);
$assert(
    1 === preg_match('/<figure class="[^"]*wp-block-image[^"]*be-inline-geometry-[a-f0-9]+[^"]*"/', $injectedFigureMarkup),
    'injected figure: the carrier lands on the generated figure, which is the flex child',
    $injectedFigureMarkup
);

// -- Control: a class-owned shorthand has nothing inline to carry; the author
// stylesheet already retains the declaration and no carrier is invented.
$classOwned = $transform(
    '<style>.col{flex:1 1 20rem}</style>'
    . '<section><div style="display:flex;flex-wrap:wrap;gap:1rem">'
    . '<div class="col"><p>Class-owned column copy.</p></div>'
    . '<div class="col"><p>Second class-owned column copy.</p></div>'
    . '</div></section>'
);

$assert(
    '' === $ruleWith($carrierRules($cssFor($classOwned, 'engine-support')), 'flex:1 1 20rem'),
    'class-owned control: no geometry carrier is invented for a class-owned shorthand',
    $cssFor($classOwned, 'engine-support')
);
$assert(
    str_contains($cssFor($classOwned, 'author-css'), '.col{flex:1 1 20rem}'),
    'class-owned control: the author rule stays the owner of the shorthand',
    $cssFor($classOwned, 'author-css')
);

if ( $failures > 0 ) {
    fwrite(STDERR, "Inline flex-item carrier contract: {$failures} failed, {$passes} passed\n");
    exit(1);
}

fwrite(STDOUT, "Inline flex-item carrier contract passed: {$passes} assertions\n");
