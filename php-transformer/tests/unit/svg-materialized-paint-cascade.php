<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

/**
 * A materialized SVG becomes a standalone `<img src="...svg">` document.
 * CSS `fill`/`color` from the host page cannot cross that boundary, so any
 * SVG whose paint comes only from CSS (rather than an explicit attribute)
 * must have its resolved cascade value baked into the asset itself.
 */

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    ++$assertions;
    if ( ! $condition ) {
        fwrite(STDERR, 'FAIL: ' . $message . PHP_EOL);
        exit(1);
    }
};

/** @return list<array<string, mixed>> */
$inlineSvgAssets = static fn (array $result): array => array_values(array_filter(
    $result['assets'] ?? array(),
    static fn (array $asset): bool => 'inline-svg' === ($asset['source'] ?? null)
));

// A generic decorative-shape shape: fill is declared on an intermediate class
// carrier via a `var()` fallback chain, whose custom property is scoped to an
// id ancestor rather than :root -- the pattern behind real page builder output
// (a component-scoped design-token custom property feeding a shared utility
// class), never appearing as text anywhere inside the <svg> itself.
$scopedIndirection = '<style>
#shape-one{--fill:#e1402a}
.paint-carrier{fill:var(--corvid-fill-color,var(--fill))}
</style>
<main><div id="shape-one"><div class="paint-carrier"><svg viewBox="0 0 10 10" width="100" height="100"><path d="M0 0h10v10H0z"></path></svg></div></div></main>';
$scopedResult = (new HtmlTransformer())->transform($scopedIndirection)->toArray();
$scopedAssets = $inlineSvgAssets($scopedResult);
$assert(1 === count($scopedAssets), 'A single decorative shape materializes one SVG asset.');
$assert(str_contains((string) ($scopedAssets[0]['content'] ?? ''), 'fill:#e1402a'), 'The resolved ancestor-cascade fill (through a scoped custom-property fallback chain) is baked into the standalone SVG asset.');
$assert(str_contains((string) ($scopedResult['serialized_blocks'] ?? ''), 'src="' . $scopedAssets[0]['path']), 'The rendered image block references the asset carrying the baked paint.');

// Two shapes resolving to different colors through the same class-owned CSS
// rule must not collide onto the same materialized asset: the resolved paint
// participates in the asset's content identity, not just its structure.
$distinctPaint = '<style>
#shape-a{--fill:#e1402a}
#shape-b{--fill:#2a6fe1}
.paint-carrier{fill:var(--fill)}
</style>
<main>
<div id="shape-a"><div class="paint-carrier"><svg viewBox="0 0 10 10" width="100" height="100"><path d="M0 0h10v10H0z"></path></svg></div></div>
<div id="shape-b"><div class="paint-carrier"><svg viewBox="0 0 10 10" width="100" height="100"><path d="M0 0h10v10H0z"></path></svg></div></div>
</main>';
$distinctResult = (new HtmlTransformer())->transform($distinctPaint)->toArray();
$distinctAssets = $inlineSvgAssets($distinctResult);
$assert(2 === count($distinctAssets), 'Two shapes with different resolved fill materialize two distinct assets rather than colliding.');
$distinctContents = array_map(static fn (array $asset): string => (string) ($asset['content'] ?? ''), $distinctAssets);
$assert((bool) array_filter($distinctContents, static fn (string $content): bool => str_contains($content, 'fill:#e1402a')), 'The first shape bakes its own resolved fill.');
$assert((bool) array_filter($distinctContents, static fn (string $content): bool => str_contains($content, 'fill:#2a6fe1')), 'The second shape bakes its own, different, resolved fill.');

// Two shapes that resolve to the SAME cascaded color still dedupe onto one
// asset -- paint baking must not break existing content-addressed sharing.
$samePaint = '<style>
#shape-c{--fill:#e1402a}
#shape-d{--fill:#e1402a}
.paint-carrier{fill:var(--fill)}
</style>
<main>
<div id="shape-c"><div class="paint-carrier"><svg viewBox="0 0 10 10" width="100" height="100"><path d="M0 0h10v10H0z"></path></svg></div></div>
<div id="shape-d"><div class="paint-carrier"><svg viewBox="0 0 10 10" width="100" height="100"><path d="M0 0h10v10H0z"></path></svg></div></div>
</main>';
$sameResult = (new HtmlTransformer())->transform($samePaint)->toArray();
$assert(1 === count($inlineSvgAssets($sameResult)), 'Two shapes resolving to the same cascaded fill still share one materialized asset.');

// An SVG that already carries its own explicit paint attribute must not be
// overridden by an unrelated ancestor's inherited CSS fill.
$explicitWins = '<style>
#shape-e{--fill:#e1402a}
.paint-carrier{fill:var(--fill)}
</style>
<main><div id="shape-e"><div class="paint-carrier"><svg viewBox="0 0 10 10" width="100" height="100" fill="#00b140"><path d="M0 0h10v10H0z"></path></svg></div></div></main>';
$explicitResult = (new HtmlTransformer())->transform($explicitWins)->toArray();
$explicitAssets = $inlineSvgAssets($explicitResult);
$assert(1 === count($explicitAssets), 'The explicitly painted shape still materializes one asset.');
$assert(str_contains((string) ($explicitAssets[0]['content'] ?? ''), 'fill="#00b140"') && ! str_contains((string) ($explicitAssets[0]['content'] ?? ''), '#e1402a'), 'An SVG-authored explicit fill attribute wins over an inherited ancestor cascade fill, matching browser cascade order.');

// currentColor must resolve against the effective inherited `color`, even
// when that color is declared on an ancestor that classification treats as a
// low-value styling boundary (a bare div with a hashed/utility class name).
$currentColor = '<style>.text-carrier{color:#123456}</style>
<main><div class="text-carrier"><svg viewBox="0 0 10 10" width="20" height="20" fill="currentColor"><path d="M0 0h10v10H0z"></path></svg></div></main>';
$currentColorResult = (new HtmlTransformer())->transform($currentColor)->toArray();
$currentColorAssets = $inlineSvgAssets($currentColorResult);
$assert(1 === count($currentColorAssets), 'A currentColor shape materializes one asset.');
$assert(str_contains((string) ($currentColorAssets[0]['content'] ?? ''), '#123456') && ! str_contains((string) ($currentColorAssets[0]['content'] ?? ''), 'currentColor'), 'currentColor is resolved against the ancestor-declared color and baked in, rather than left to fail closed in the isolated asset document.');

$textBaseline = '<style>.text-mask text{dominant-baseline:text-before-edge}</style>
<main><svg class="text-mask" viewBox="0 0 165 140"><defs><clipPath id="label"><text x="0" y="0em">Let’s</text><text x="0" y="1em">talk</text></clipPath></defs><g><text x="0" y="0em">Let’s</text><text x="0" y="1em">talk</text></g></svg></main>';
$textBaselineResult = (new HtmlTransformer())->transform($textBaseline)->toArray();
$textBaselineAssets = $inlineSvgAssets($textBaselineResult);
$textBaselineContent = (string) ($textBaselineAssets[0]['content'] ?? '');
$assert(1 === count($textBaselineAssets) && 'core/image' === ($textBaselineResult['blocks'][0]['blockName'] ?? null), 'A stylesheet-positioned text SVG remains an editor-native materialized image.');
$assert(4 === substr_count($textBaselineContent, 'dominant-baseline:text-before-edge'), 'Resolved text baseline layout is baked into every matching node in the standalone SVG asset.');

$inheritedBaseline = (new HtmlTransformer())->transform('<style>.text-mask{dominant-baseline:hanging}</style><svg class="text-mask" viewBox="0 0 20 20"><text y="0">Label</text></svg>')->toArray();
$inheritedBaselineContent = (string) ($inlineSvgAssets($inheritedBaseline)[0]['content'] ?? '');
$assert(str_contains($inheritedBaselineContent, '<text y="0" style="dominant-baseline:hanging">'), 'An inherited baseline declaration is carried from the source SVG root to its standalone text node.');

$specificBaseline = (new HtmlTransformer())->transform('<style>#mask text{dominant-baseline:alphabetic}.late text{dominant-baseline:hanging}</style><svg id="mask" class="late" viewBox="0 0 20 20"><text y="0">Label</text></svg>')->toArray();
$specificBaselineContent = (string) ($inlineSvgAssets($specificBaseline)[0]['content'] ?? '');
$assert(str_contains($specificBaselineContent, 'dominant-baseline:alphabetic') && ! str_contains($specificBaselineContent, 'dominant-baseline:hanging'), 'The strongest matching baseline selector wins regardless of source order.');

$importantBaseline = (new HtmlTransformer())->transform('<style>.late text{dominant-baseline:hanging!important}#mask text{dominant-baseline:alphabetic}</style><svg id="mask" class="late" viewBox="0 0 20 20"><text y="0">Label</text></svg>')->toArray();
$importantBaselineContent = (string) ($inlineSvgAssets($importantBaseline)[0]['content'] ?? '');
$assert(str_contains($importantBaselineContent, 'dominant-baseline:hanging') && ! str_contains($importantBaselineContent, 'dominant-baseline:alphabetic'), 'An important baseline declaration wins before selector specificity.');

$explicitBaseline = (new HtmlTransformer())->transform('<style>.mask{dominant-baseline:hanging}</style><svg class="mask" viewBox="0 0 20 20"><text y="0" dominant-baseline="alphabetic">Label</text></svg>')->toArray();
$explicitBaselineContent = (string) ($inlineSvgAssets($explicitBaseline)[0]['content'] ?? '');
$assert(str_contains($explicitBaselineContent, 'dominant-baseline="alphabetic"') && ! str_contains($explicitBaselineContent, 'dominant-baseline:hanging'), 'A descendant presentation attribute wins over an inherited baseline declaration.');

$variableBaseline = (new HtmlTransformer())->transform('<style>#mask{--baseline:alphabetic}.late{--baseline:hanging}.late text{dominant-baseline:var(--baseline)}</style><svg id="mask" class="late" viewBox="0 0 20 20"><text y="0">Label</text></svg>')->toArray();
$variableBaselineContent = (string) ($inlineSvgAssets($variableBaseline)[0]['content'] ?? '');
$assert(! str_contains($variableBaselineContent, 'dominant-baseline:'), 'An unresolved custom-property cascade is not baked as an incorrect baseline winner.');

$blockedInheritance = (new HtmlTransformer())->transform('<style>.mask{dominant-baseline:hanging}</style><svg class="mask" viewBox="0 0 20 20"><text y="0" style="dominant-baseline:var(--baseline)">Label</text></svg>')->toArray();
$blockedInheritanceContent = (string) ($inlineSvgAssets($blockedInheritance)[0]['content'] ?? '');
$assert(! str_contains($blockedInheritanceContent, 'dominant-baseline:hanging'), 'An unresolved descendant declaration blocks an ancestor baseline from being baked over it.');

$pageInheritedBaseline = (new HtmlTransformer())->transform('<style>main{dominant-baseline:hanging}</style><main><svg viewBox="0 0 20 20"><text y="0">Label</text></svg></main>')->toArray();
$pageInheritedBaselineContent = (string) ($inlineSvgAssets($pageInheritedBaseline)[0]['content'] ?? '');
$assert(str_contains($pageInheritedBaselineContent, 'dominant-baseline:hanging'), 'Baseline inheritance from outside the SVG is baked into the standalone text node.');

fwrite(STDOUT, 'SVG materialized paint cascade tests: ' . $assertions . " passed\n");
