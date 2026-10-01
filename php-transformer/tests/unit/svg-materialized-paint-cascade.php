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

// SVG presentation attributes are copied into the standalone image payload
// directly. Resolve their variables at the SVG's own ancestor scope rather
// than from the lossy document-wide custom-property collection.
$scopedAttributePaint = '<style>
#orange-one{--orange-icon:#e1402a}
#orange-two{--orange-icon:#f58220}
</style>
<main>
<div id="orange-one"><svg viewBox="0 0 10 10" width="10" height="10"><path fill="var(--orange-icon)" d="M0 0h10v10H0z"></path></svg></div>
<div id="orange-two"><svg viewBox="0 0 10 10" width="10" height="10"><path fill="var(--orange-icon)" d="M0 0h10v10H0z"></path></svg></div>
</main>';
$scopedAttributeResult = (new HtmlTransformer())->transform($scopedAttributePaint)->toArray();
$scopedAttributeAssets = $inlineSvgAssets($scopedAttributeResult);
$scopedAttributeContents = array_map(static fn (array $asset): string => (string) ($asset['content'] ?? ''), $scopedAttributeAssets);
$assert(2 === count($scopedAttributeAssets), 'Scoped SVG presentation attributes produce distinct native image assets for distinct ancestor values.');
$assert((bool) array_filter($scopedAttributeContents, static fn (string $content): bool => str_contains($content, 'fill="#e1402a"') && ! str_contains($content, 'var(')), 'The first scoped SVG presentation attribute is baked into its asset.');
$assert((bool) array_filter($scopedAttributeContents, static fn (string $content): bool => str_contains($content, 'fill="#f58220"') && ! str_contains($content, 'var(')), 'The second scoped SVG presentation attribute is baked into its asset.');
$assert(2 === substr_count((string) ($scopedAttributeResult['serialized_blocks'] ?? ''), '<!-- wp:image '), 'Scoped SVG presentation attributes retain the editor-native core/image contract.');
$assert(! str_contains((string) ($scopedAttributeResult['serialized_blocks'] ?? ''), '<!-- wp:html'), 'Scoped SVG presentation attributes do not regress to an HTML fallback.');

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

$quotedFontFamily = (new HtmlTransformer())->transform(
    '<style>:root{--font-mono:"JetBrains Mono", ui-monospace, monospace}.plan{width:100%;height:auto;display:block}</style>'
    . '<main><svg class="plan" viewBox="0 0 100 84" style="font-family: var(--font-mono);">'
    . '<rect width="100" height="84" fill="#E2E8F0"></rect>'
    . '<text x="50" y="42" text-anchor="middle">FR1</text>'
    . '</svg></main>'
)->toArray();
$quotedFontAssets = $inlineSvgAssets($quotedFontFamily);
$quotedFontContent = (string) ($quotedFontAssets[0]['content'] ?? '');
$assert(1 === count($quotedFontAssets) && 'core/image' === ($quotedFontFamily['blocks'][0]['blockName'] ?? null), 'A resolved root-style font-family custom property remains an editor-native materialized image.');
$assert(
    str_contains($quotedFontContent, 'font-family:&quot;JetBrains Mono&quot;, ui-monospace, monospace')
        && preg_match('/<svg\b[^>]*\sstyle\s*=\s*"([^"]*)"/i', $quotedFontContent, $quotedFontStyle) === 1
        && str_contains($quotedFontStyle[1], 'JetBrains Mono')
        && ! str_contains($quotedFontStyle[1], '"'),
    'The resolved quoted font stack is HTML-escaped inside the standalone SVG style attribute.'
);
$assert(
    str_contains($quotedFontStyle[1] ?? '', 'display:block') && str_contains($quotedFontStyle[1] ?? '', 'height:auto') && str_contains($quotedFontStyle[1] ?? '', 'width:100%'),
    'Box geometry composed onto the root SVG shares the escaped style attribute with the resolved font stack.'
);
$assert(! str_contains($quotedFontContent, 'var(--font-mono)'), 'The standalone SVG asset does not retain the unresolved font-family custom property.');

// Standard SVG color and inert icon-exporter metadata do not imply behavior.
// Their paint must survive the native-image boundary rather than being removed.
$attributeColor = (new HtmlTransformer())->transform('<svg color="#c95a12" fill="currentColor" viewBox="0 0 10 10"><path d="M0 0h10v10H0z"></path></svg>')->toArray();
$attributeColorAssets = $inlineSvgAssets($attributeColor);
$assert(1 === count($attributeColorAssets), 'An SVG color presentation attribute does not disqualify passive artwork.');
$attributeColorContent = (string) ($attributeColorAssets[0]['content'] ?? '');
$assert(str_contains($attributeColorContent, 'fill="#c95a12"') && ! str_contains($attributeColorContent, 'currentColor'), 'The SVG color attribute supplies currentColor in the standalone asset.');

$groupWeight = (new HtmlTransformer())->transform('<svg color="#123456" viewBox="0 0 10 10"><g weight="light"><path fill="currentColor" d="M0 0h10v10H0z"></path></g></svg>')->toArray();
$assert(1 === count($inlineSvgAssets($groupWeight)) && 'core/image' === ($groupWeight['blocks'][0]['blockName'] ?? null), 'Inert group weight metadata remains native SVG artwork.');
$assert(str_contains((string) ($inlineSvgAssets($groupWeight)[0]['content'] ?? ''), 'fill="#123456"'), 'Exporter metadata admission preserves icon paint.');

$descendantColor = (new HtmlTransformer())->transform('<svg color="#123456" viewBox="0 0 10 10"><g color="#fedcba" weight="light"><path fill="currentColor" d="M0 0h10v10H0z"></path></g></svg>')->toArray();
$assert(str_contains((string) ($inlineSvgAssets($descendantColor)[0]['content'] ?? ''), 'fill="#fedcba"'), 'Descendant artwork resolves currentColor at its own group scope rather than the SVG root.');
$descendantVariable = (new HtmlTransformer())->transform('<svg style="--icon-color:#123456" viewBox="0 0 10 10"><g style="--icon-color:#fedcba" weight="light"><path fill="var(--icon-color,rgb(0,0,0))" d="M0 0h10v10H0z"></path></g></svg>')->toArray();
$assert(str_contains((string) ($inlineSvgAssets($descendantVariable)[0]['content'] ?? ''), 'fill="#fedcba"'), 'Descendant presentation variables use their own inherited custom-property scope.');

$cascadeColor = (new HtmlTransformer())->transform('<style>.icon{color:#abcdef}</style><svg class="icon" color="#123456" fill="currentColor" viewBox="0 0 10 10"><path d="M0 0h10v10H0z"></path></svg>')->toArray();
$assert(str_contains((string) ($inlineSvgAssets($cascadeColor)[0]['content'] ?? ''), 'fill="#abcdef"'), 'Matched CSS color overrides the SVG presentation attribute.');

$ancestorColor = (new HtmlTransformer())->transform('<svg color="#654321" viewBox="0 0 10 10"><g><svg fill="currentColor" viewBox="0 0 10 10"><path d="M0 0h10v10H0z"></path></svg></g></svg>')->toArray();
$assert(str_contains((string) ($inlineSvgAssets($ancestorColor)[0]['content'] ?? ''), '#654321'), 'Ancestor SVG presentation color remains available during artwork materialization.');

$functionFallback = (new HtmlTransformer())->transform('<svg color="var(--icon-color, rgb(255, 255, 255))" style="fill:var(--icon-color, rgb(255, 255, 255))" viewBox="0 0 10 10"><g weight="regular"><path d="M0 0h10v10H0z"></path></g></svg>')->toArray();
$functionFallbackContent = (string) ($inlineSvgAssets($functionFallback)[0]['content'] ?? '');
$assert(1 === count($inlineSvgAssets($functionFallback)), 'Function-valued color fallbacks materialize through the existing SVG image path.');
$assert(str_contains($functionFallbackContent, 'rgb(255, 255, 255)') && ! str_contains($functionFallbackContent, 'var('), 'Function-valued paint fallbacks are baked without unresolved variables.');

$definedOuter = (new HtmlTransformer())->transform('<style>:root{--icon-color:#13579b}</style><svg color="var(--icon-color,var(--missing,rgb(0,0,0)))" fill="currentColor" viewBox="0 0 10 10"><path d="M0 0h10v10H0z"></path></svg>')->toArray();
$assert(str_contains((string) ($inlineSvgAssets($definedOuter)[0]['content'] ?? ''), 'fill="#13579b"'), 'A defined paint variable wins without evaluating its nested fallback.');

$unresolvedPaint = (new HtmlTransformer())->transform('<svg color="var(--missing)" fill="currentColor" viewBox="0 0 10 10"><path d="M0 0h10v10H0z"></path></svg>')->toArray();
$assert(array() === $inlineSvgAssets($unresolvedPaint), 'Unresolved color remains inline rather than being baked as a guessed image color.');
$unknownAttribute = (new HtmlTransformer())->transform('<svg custom-behavior="live" viewBox="0 0 10 10"><path d="M0 0h10v10H0z"></path></svg>')->toArray();
$assert(array() === $inlineSvgAssets($unknownAttribute), 'Unknown behavior-bearing attributes retain the existing inline floor.');
$unsafeWeight = (new HtmlTransformer())->transform('<svg viewBox="0 0 10 10"><g weight="javascript:alert(1)"><path d="M0 0h10v10H0z"></path></g></svg>')->toArray();
$assert(array() === $inlineSvgAssets($unsafeWeight), 'Metadata admission does not bypass unsafe-value checks.');
$animatedPaint = (new HtmlTransformer())->transform('<style>.icon path{animation:pulse 1s infinite}@keyframes pulse{to{opacity:0}}</style><svg class="icon" color="#123456" viewBox="0 0 10 10"><path d="M0 0h10v10H0z"></path></svg>')->toArray();
$assert(array() === $inlineSvgAssets($animatedPaint), 'Page-CSS animated descendants still require inline document context.');

$cssFilledIcon = (new HtmlTransformer())->transform('<style>.icon-box{width:30px;height:30px;position:relative}</style><a href="/details"><div><h2>Details</h2><div class="icon-box"><div style="display:contents"><svg color="#123456" viewBox="0 0 10 10" style="width:100%;height:100%;display:inline-block"><g weight="light"><path d="M0 0h10v10H0z"></path></g></svg></div></div></div></a>')->toArray();
$cssFilledMarkup = (string) ($cssFilledIcon['serialized_blocks'] ?? '');
$cssFilledStyles = implode('', array_map(static fn (array $asset): string => 'css' === ($asset['kind'] ?? '') ? (string) ($asset['content'] ?? '') : '', $cssFilledIcon['assets'] ?? array()));
$assert(str_contains($cssFilledMarkup, '<!-- wp:image') && str_contains($cssFilledMarkup, '<a href="/details"><img'), 'A linked CSS-filled icon uses the valid core/image link shape.');
$assert(str_contains($cssFilledStyles, '>a>img{width:100%;height:100%') && str_contains($cssFilledStyles, '>a{display:block;width:100%;height:100%}'), 'Both native link and image carriers retain the percentage media box across a transparent source wrapper.');

$richTextIcon = (new HtmlTransformer())->transform('<style>.icon-box{width:20px;height:20px}</style><a href="/details">Details<span class="icon-box"><span style="display:contents"><svg color="#123456" viewBox="0 0 10 10" style="width:100%;height:100%;display:inline-block"><g weight="light"><path d="M0 0h10v10H0z"></path></g></svg></span></span></a>')->toArray();
$richTextMarkup = (string) ($richTextIcon['serialized_blocks'] ?? '');
$assert(preg_match('/<img[^>]*style="[^"]*width:20px;[^"]*height:20px/', $richTextMarkup) === 1, 'Flattened RichText artwork resolves CSS-authored percentage axes against its original icon box.');

fwrite(STDOUT, 'SVG materialized paint cascade tests: ' . $assertions . " passed\n");
