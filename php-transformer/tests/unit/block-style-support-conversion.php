<?php
declare(strict_types=1);

/**
 * Regression coverage for inline CSS that must become Gutenberg block supports
 * instead of unsupported raw block style strings.
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;
use Automattic\BlocksEngine\PhpTransformer\VisualParity\StaticStyleParityRunner;
use Automattic\BlocksEngine\PhpTransformer\VisualParity\StaticStyleParityComparator;
use Automattic\BlocksEngine\PhpTransformer\VisualParity\StaticStyleParityProbe;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\GeometryCarrierClassAllocator;

$failures = 0;
$passes   = 0;

$assert = static function (bool $condition, string $message, string $detail = '') use (&$failures, &$passes): void {
    if ( $condition ) {
        ++$passes;
        return;
    }

    ++$failures;
    fwrite(STDERR, 'FAIL: ' . $message . ('' !== $detail ? ' - ' . $detail : '') . PHP_EOL);
};

$html = '<nav class="site-nav" style="display:flex;align-items:center;justify-content:space-between;gap:24px;background:var(--wp--preset--color--base);color:var(--wp--preset--color--contrast);padding:16px 24px;box-shadow:0 12px 30px rgba(0,0,0,.12)">'
    . '<a href="/">Home</a><a href="/menu">Menu</a>'
    . '</nav>';

$result = ( new HtmlTransformer() )->transform($html, array())->toArray();
$blocks = $result['blocks'] ?? array();
$nav = $blocks[0] ?? array();

$assert('core/navigation' === ($nav['blockName'] ?? ''), '1: nav-like wrapper becomes core/navigation', (string) ($nav['blockName'] ?? '(none)'));
$attrs = is_array($nav['attrs'] ?? null) ? $nav['attrs'] : array();
$assert('base' === ($attrs['backgroundColor'] ?? ''), '2: preset background variable maps to backgroundColor attr', json_encode($attrs));
$assert('contrast' === ($attrs['textColor'] ?? ''), '3: preset text variable maps to textColor attr', json_encode($attrs));
$assert('24px' === ($attrs['style']['spacing']['blockGap'] ?? ''), '4: gap maps to spacing.blockGap', json_encode($attrs['style']['spacing'] ?? array()));
$assert('flex' === ($attrs['layout']['type'] ?? ''), '5: display:flex maps to flex layout', json_encode($attrs['layout'] ?? array()));
$assert('space-between' === ($attrs['layout']['justifyContent'] ?? ''), '6: justify-content maps to layout.justifyContent', json_encode($attrs['layout'] ?? array()));
$assert(! isset($attrs['style']['box-shadow']), '7: unsupported box-shadow is not stored as block style', json_encode($attrs['style'] ?? array()));
$assert(! is_string($attrs['style'] ?? null), '8: block style attr is structured, never a raw style string');

$groupHtml = '<div class="hero-row" style="display:flex;justify-content:center;gap:1rem;min-height:100svh;padding:2rem;background:var(--wp--preset--color--base)"><p>Hello</p><p>World</p></div>';
$groupResult = ( new HtmlTransformer() )->transform($groupHtml, array())->toArray();
$group = $groupResult['blocks'][0] ?? array();
$groupAttrs = is_array($group['attrs'] ?? null) ? $group['attrs'] : array();
$groupInnerHtml = (string) ($group['innerHTML'] ?? '');

$assert('core/columns' === ($group['blockName'] ?? ''), '9: horizontal flex content wrapper becomes columns', (string) ($group['blockName'] ?? '(none)'));
$assert('center' === ($groupAttrs['layout']['justifyContent'] ?? ''), '10: group flex justify-content is normalized to layout attr', json_encode($groupAttrs['layout'] ?? array()));
$assert(str_contains($groupInnerHtml, 'has-base-background-color has-background'), '11: rendered wrapper uses preset color classes', $groupInnerHtml);
$assert(str_contains($groupInnerHtml, 'is-layout-flex wp-block-columns-is-layout-flex'), '12: rendered wrapper uses layout support classes', $groupInnerHtml);
$assert(! str_contains($groupInnerHtml, 'gap:1rem'), '13: rendered wrapper omits blockGap when the core save shape does not reproduce it', $groupInnerHtml);
$assert(! str_contains($groupInnerHtml, 'display:flex') && ! str_contains($groupInnerHtml, 'justify-content:center'), '14: rendered wrapper does not carry raw flex declarations', $groupInnerHtml);
$assert('100svh' === ($groupAttrs['style']['dimensions']['minHeight'] ?? ''), '15: min-height maps to Gutenberg dimensions support', json_encode($groupAttrs['style']['dimensions'] ?? array()));
$assert(str_contains($groupInnerHtml, 'min-height:100svh'), '16: rendered wrapper preserves section min-height geometry', $groupInnerHtml);

$cardHtml = '<section class="pricing-shell" style="max-width:1120px;margin:0 auto;padding:5rem 2rem"><article class="pricing-card" style="max-width:360px;padding:2rem;background:#fff"><h2>Team</h2><p>Scale every launch.</p></article></section>';
$cardResult = ( new HtmlTransformer() )->transform($cardHtml, array())->toArray();
$cardShell = $cardResult['blocks'][0] ?? array();
$card = $cardShell['innerBlocks'][0] ?? array();
$cardShellAttrs = is_array($cardShell['attrs'] ?? null) ? $cardShell['attrs'] : array();
$cardAttrs = is_array($card['attrs'] ?? null) ? $card['attrs'] : array();
$cardMarkup = (string) ($cardResult['serialized_blocks'] ?? '');

$assert(! isset($cardShellAttrs['style']['dimensions']['maxWidth']), '17: core/group omits max-width attr that Gutenberg save does not reproduce', json_encode($cardShellAttrs['style']['dimensions'] ?? array()));
$assert(! isset($cardAttrs['style']['dimensions']['maxWidth']), '18: nested core/group omits max-width attr that Gutenberg save does not reproduce', json_encode($cardAttrs['style']['dimensions'] ?? array()));
$assert(! str_contains($cardMarkup, 'max-width:1120px'), '19: rendered core/group wrapper omits unsupported max-width style', $cardMarkup);
$assert(! str_contains($cardMarkup, 'max-width:360px'), '20: rendered nested core/group wrapper omits unsupported max-width style', $cardMarkup);
$assert(! str_contains($cardMarkup, 'style="max-width:1120px;margin:0 auto;padding:5rem 2rem"'), '21: rendered section does not keep unsupported raw style wholesale', $cardMarkup);

$geometryAssets = $cardResult['assets'] ?? array();
$geometryCss = implode("\n", array_map(static fn (array $asset): string => (string) ($asset['content'] ?? ''), is_array($geometryAssets) ? $geometryAssets : array()));
$assert(str_contains((string) ($cardShellAttrs['className'] ?? ''), 'be-inline-geometry-'), '22: unsupported inline container sizing gets a deterministic generated class', json_encode($cardShellAttrs));
$assert(str_contains($geometryCss, 'max-width:1120px'), '23: generated stylesheet preserves unsupported inline container max-width', $geometryCss);

$spacerResult = ( new HtmlTransformer() )->transform('<div class="spacer rhythm" style="width:50%;max-width:42rem;aspect-ratio:4 / 1;height:3rem"></div>', array())->toArray();
$spacer = $spacerResult['blocks'][0] ?? array();
$spacerAttrs = is_array($spacer['attrs'] ?? null) ? $spacer['attrs'] : array();
$spacerMarkup = (string) ($spacerResult['serialized_blocks'] ?? '');
$spacerCss = implode("\n", array_map(static fn (array $asset): string => (string) ($asset['content'] ?? ''), is_array($spacerResult['assets'] ?? null) ? $spacerResult['assets'] : array()));

$assert('core/spacer' === ($spacer['blockName'] ?? ''), '24: empty spacer stays a native core/spacer', (string) ($spacer['blockName'] ?? '(none)'));
$assert('3rem' === ($spacerAttrs['height'] ?? ''), '25: native spacer height owns only the height declaration', json_encode($spacerAttrs));
$assert(str_contains((string) ($spacerAttrs['className'] ?? ''), 'be-inline-geometry-'), '26: spacer retains a geometry carrier for non-height declarations', json_encode($spacerAttrs));
$assert(str_contains($spacerCss, 'width:50%') && str_contains($spacerCss, 'max-width:42rem') && str_contains($spacerCss, 'aspect-ratio:4 / 1'), '27: spacer carrier preserves mixed width/max-width/aspect-ratio geometry', $spacerCss);
$assert(! str_contains($spacerCss, 'height:3rem'), '28: spacer does not emit an orphan height carrier rule', $spacerCss);
$assert(str_contains($spacerMarkup, 'style="height:3rem"'), '29: spacer serialization retains native height support', $spacerMarkup);

$anchorResult = ( new HtmlTransformer() )->transform('<a class="article-link" href="/read" style="width:50%;max-width:20rem;aspect-ratio:2 / 1">Read</a>', array())->toArray();
$anchorMarkup = (string) ($anchorResult['serialized_blocks'] ?? '');
$anchorCss = implode("\n", array_map(static fn (array $asset): string => (string) ($asset['content'] ?? ''), is_array($anchorResult['assets'] ?? null) ? $anchorResult['assets'] : array()));
$assert(! str_contains($anchorMarkup, 'wp-block-button__width-50'), '30: ordinary anchors do not claim core/button width support', $anchorMarkup);
$assert(str_contains($anchorMarkup, 'be-inline-geometry-') && str_contains($anchorCss, 'width:50%'), '31: ordinary anchors retain applicable width geometry', $anchorMarkup . "\n" . $anchorCss);

$buttonResult = ( new HtmlTransformer() )->transform('<a class="button" href="/buy" style="width:50%;max-width:20rem;aspect-ratio:2 / 1">Buy</a>', array())->toArray();
$button = $buttonResult['blocks'][0]['innerBlocks'][0] ?? array();
$buttonAttrs = is_array($button['attrs'] ?? null) ? $button['attrs'] : array();
$buttonCss = implode("\n", array_map(static fn (array $asset): string => (string) ($asset['content'] ?? ''), is_array($buttonResult['assets'] ?? null) ? $buttonResult['assets'] : array()));
$assert(50 === ($buttonAttrs['width'] ?? null), '32: recognized core/button owns its canonical width', json_encode($buttonAttrs));
$assert(! str_contains($buttonCss, 'width:50%') && str_contains($buttonCss, 'max-width:20rem') && str_contains($buttonCss, 'aspect-ratio:2 / 1'), '33: core/button removes only native-owned width from mixed geometry', $buttonCss);

$specificityHtml = '<style>#target{width:12rem}</style><main><p id="target" style="width:30rem">Specificity</p></main>';
$specificityResult = ( new HtmlTransformer() )->transform($specificityHtml, array())->toArray();
$specificityCss = implode("\n", array_map(static fn (array $asset): string => (string) ($asset['content'] ?? ''), is_array($specificityResult['assets'] ?? null) ? $specificityResult['assets'] : array()));
$assert(str_contains($specificityCss, 'width:30rem !important'), '34: carrier beats an authored normal ID width selector', $specificityCss);
$geometryProbe = new StaticStyleParityProbe(true);
$geometryComparator = new StaticStyleParityComparator();
$missingCarrier = $geometryComparator->compare(
    $geometryProbe->extract($specificityHtml),
    $geometryProbe->extract('<main><p id="target">Specificity</p></main>', '#target{width:12rem}')
);
$assert((float) ($missingCarrier['parity']['score'] ?? 1.0) < 1.0, '35: geometry v2 fails the specificity reproduction without its carrier', json_encode($missingCarrier['parity'] ?? array()));
$specificityParity = ( new StaticStyleParityRunner() )->compareSourceToTransformWithGeometry($specificityHtml);
$assert(1.0 === (float) ($specificityParity['geometry_v2']['parity']['score'] ?? 0.0), '36: geometry v2 passes the specificity reproduction with its emitted carrier', json_encode($specificityParity['geometry_v2']['parity'] ?? array()));

$cascadeHtml = '<style>#target{width:12rem}.authored-important{width:8rem!important}</style><main><p id="target" class="authored-important" style="width:30rem">Cascade</p></main>';
$cascadeResult = ( new HtmlTransformer() )->transform($cascadeHtml, array())->toArray();
$cascadeMarkup = (string) ($cascadeResult['serialized_blocks'] ?? '');
$cascadeCss = implode("\n", array_map(static fn (array $asset): string => (string) ($asset['content'] ?? ''), is_array($cascadeResult['assets'] ?? null) ? $cascadeResult['assets'] : array()));
$assert(str_contains($cascadeMarkup, 'be-inline-geometry-') && str_contains($cascadeCss, 'width:30rem !important'), '37: carrier preserves inline width against authored ID selectors', $cascadeMarkup . "\n" . $cascadeCss);
$assert(strpos($cascadeCss, '.be-inline-geometry-') < strpos($cascadeCss, '.authored-important{width:8rem!important}'), '38: authored !important rule remains ordered after the carrier', $cascadeCss);
$cascadeParity = ( new StaticStyleParityRunner() )->compareSourceToTransformWithGeometry($cascadeHtml);
$assert(1.0 === (float) ($cascadeParity['static_v1']['parity']['score'] ?? 0.0), '39: static v1 remains comparable for cascade fixture', json_encode($cascadeParity['static_v1']['parity'] ?? array()));
$assert(1.0 === (float) ($cascadeParity['geometry_v2']['parity']['score'] ?? 0.0), '40: geometry v2 respects authored !important cascade', json_encode($cascadeParity['geometry_v2']['parity'] ?? array()));

$manyGeometry = '<main>' . str_repeat('<div id="duplicate" class="tile tile" style="width:10rem;min-height:2rem;aspect-ratio:1 / 1"></div>', 200) . '</main>';
$manyResult = ( new HtmlTransformer() )->transform($manyGeometry, array())->toArray();
$manyMarkup = (string) ($manyResult['serialized_blocks'] ?? '');
$manyCss = implode("\n", array_map(static fn (array $asset): string => (string) ($asset['content'] ?? ''), is_array($manyResult['assets'] ?? null) ? $manyResult['assets'] : array()));
preg_match_all('/be-inline-geometry-[a-z0-9-]+/', $manyMarkup, $manyClasses);
preg_match_all('/\.be-inline-geometry-[a-z0-9-]+\{/', $manyCss, $manyRules);
$assert(200 === count(array_unique($manyClasses[0] ?? array())), '41: duplicate classes and IDs still receive one stable carrier class per element', (string) count(array_unique($manyClasses[0] ?? array())));
$assert(200 === count($manyRules[0] ?? array()), '42: final serialized carrier classes retain exactly one emitted rule each', (string) count($manyRules[0] ?? array()));

$importantGeometryHtml = '<p style="width:30rem!important;min-height:12rem!important">Important geometry</p>';
$importantGeometryResult = ( new HtmlTransformer() )->transform($importantGeometryHtml, array())->toArray();
$importantGeometryMarkup = (string) ($importantGeometryResult['serialized_blocks'] ?? '');
$importantGeometryParity = ( new StaticStyleParityRunner() )->compareSourceToTransformWithGeometry($importantGeometryHtml);
$assert(str_contains($importantGeometryMarkup, 'width:30rem!important;min-height:12rem!important'), '43: width and min-height !important remain serialized inline', $importantGeometryMarkup);
$assert(1.0 === (float) ($importantGeometryParity['geometry_v2']['parity']['score'] ?? 0.0), '44: geometry v2 preserves width and min-height !important', json_encode($importantGeometryParity['geometry_v2']['parity'] ?? array()));

$collisionAllocator = new GeometryCarrierClassAllocator(static fn (string $signature): string => str_repeat('a', 64));
$collisionFirst = $collisionAllocator->allocate('first-signature');
$collisionSecond = $collisionAllocator->allocate('second-signature');
$assert($collisionFirst !== $collisionSecond && $collisionFirst === $collisionAllocator->allocate('first-signature'), '45: colliding digests allocate distinct stable carrier classes', $collisionFirst . ' / ' . $collisionSecond);
$manyRepeat = ( new HtmlTransformer() )->transform($manyGeometry, array())->toArray();
$assert(
    (string) ($manyResult['serialized_blocks'] ?? '') === (string) ($manyRepeat['serialized_blocks'] ?? '')
    && json_encode($manyResult['assets'] ?? array()) === json_encode($manyRepeat['assets'] ?? array()),
    '46: repeated transforms retain byte-identical carrier markup and assets'
);

$importantButton = ( new HtmlTransformer() )->transform('<a class="button" href="/buy" style="width:50%!important;min-width:12rem!important;max-width:30rem!important;height:3rem!important;aspect-ratio:2 / 1!important;flex-basis:20rem!important">Buy</a>', array())->toArray();
$importantButtonMarkup = (string) ($importantButton['serialized_blocks'] ?? '');
$assert(! str_contains($importantButtonMarkup, 'wp-block-button__width-50') && str_contains($importantButtonMarkup, 'width:50%!important') && str_contains($importantButtonMarkup, 'min-width:12rem!important') && str_contains($importantButtonMarkup, 'max-width:30rem!important') && str_contains($importantButtonMarkup, 'height:3rem!important') && str_contains($importantButtonMarkup, 'aspect-ratio:2 / 1!important') && str_contains($importantButtonMarkup, 'flex-basis:20rem!important'), '47: core/button preserves source-important geometry on its wrapper without native width classes', $importantButtonMarkup);

$variableGeometryHtml = '<p style="--box-base:30rem;--box-width:var(--box-base,20rem);width:var(--box-width)">Variable geometry</p>';
$variableGeometryResult = ( new HtmlTransformer() )->transform($variableGeometryHtml, array())->toArray();
$variableGeometryMarkup = (string) ($variableGeometryResult['serialized_blocks'] ?? '');
$variableGeometryParity = ( new StaticStyleParityRunner() )->compareSourceToTransformWithGeometry($variableGeometryHtml);
$assert(str_contains($variableGeometryMarkup, '--box-base:30rem;--box-width:var(--box-base,20rem)') && str_contains($variableGeometryMarkup, 'be-inline-geometry-'), '48: local custom properties used by geometry survive serialization', $variableGeometryMarkup);
$assert(1.0 === (float) ($variableGeometryParity['geometry_v2']['parity']['score'] ?? 0.0), '49: geometry v2 resolves local and transitive custom properties', json_encode($variableGeometryParity['geometry_v2']['parity'] ?? array()));
$missingVariableGeometry = $geometryComparator->compare(
    $geometryProbe->extract($variableGeometryHtml),
    $geometryProbe->extract('<p>Variable geometry</p>', '.be-inline-geometry-test{width:var(--box-width)}')
);
$assert((float) ($missingVariableGeometry['parity']['score'] ?? 1.0) < 1.0, '50: geometry v2 fails when a required local custom property is absent', json_encode($missingVariableGeometry['parity'] ?? array()));

$columnsMaxWidthHtml = '<div class="feature-row" style="display:flex;max-width:var(--max-w);margin:0 auto"><div><p>A</p></div><div><p>B</p></div></div>';
$columnsMaxWidthResult = ( new HtmlTransformer() )->transform($columnsMaxWidthHtml, array())->toArray();
$columnsMaxWidthBlock = $columnsMaxWidthResult['blocks'][0] ?? array();
$columnsMaxWidthAttrs = is_array($columnsMaxWidthBlock['attrs'] ?? null) ? $columnsMaxWidthBlock['attrs'] : array();
$columnsMaxWidthMarkup = (string) ($columnsMaxWidthResult['serialized_blocks'] ?? '');

$assert('core/columns' === ($columnsMaxWidthBlock['blockName'] ?? ''), '24: horizontal flex wrapper still becomes columns', (string) ($columnsMaxWidthBlock['blockName'] ?? '(none)'));
$assert(! isset($columnsMaxWidthAttrs['style']['dimensions']['maxWidth']), '25: core/columns omits max-width attr that Gutenberg save does not reproduce', json_encode($columnsMaxWidthAttrs['style']['dimensions'] ?? array()));
$assert(! str_contains($columnsMaxWidthMarkup, 'max-width:var(--max-w)'), '26: rendered core/columns wrapper omits unsupported max-width style', $columnsMaxWidthMarkup);

$labelHtml = '<section class="pricing"><div class="section-head"><div class="tag">Pricing</div><h2>Simple plans</h2></div><article class="pricing-card"><div class="tier-name">Team</div><div class="tier-price"><span class="amount">$29</span>/mo</div><div class="use-case-result">Launch faster</div></article></section>';
$labelCss = '.tag{display:inline-flex;align-items:center;gap:6px;padding:4px 12px;border-radius:100px}.pricing-card{padding:2rem}.tier-name{font-family:monospace;font-size:11px;letter-spacing:.12em;text-transform:uppercase}.tier-price{display:flex;align-items:flex-end;gap:6px}.use-case-result{display:flex;align-items:center;gap:8px;padding:10px 14px;border-radius:6px}';
$labelResult = ( new HtmlTransformer() )->transform($labelHtml, array('static_css' => $labelCss))->toArray();
$labelMarkup = (string) ($labelResult['serialized_blocks'] ?? '');

$assert(str_contains($labelMarkup, '<div class="wp-block-group tag'), '25: box-model section badge stays a group wrapper', $labelMarkup);
$assert(str_contains($labelMarkup, '<p class="tier-name">Team</p>'), '26: typography-only card tier label collapses to a styled paragraph so its font scale applies', $labelMarkup);
$assert(str_contains($labelMarkup, '<div class="wp-block-group tier-price'), '27: box-model card price row stays a group wrapper', $labelMarkup);
$assert(str_contains($labelMarkup, '<div class="wp-block-group use-case-result'), '28: box-model card result row stays a group wrapper', $labelMarkup);
$assert(! preg_match('/<!-- wp:group[^>]*"className":"tier-name"/', $labelMarkup), '29: typography-only tier label does not round-trip as a group wrapping a default paragraph', $labelMarkup);

$stackHtml = '<div class="hero-content"><p>Eyebrow</p><h1>Low Tide Table</h1><div></div><p>Local shrimp.</p><div><p>Next Run</p></div><div><a href="#reserve">Reserve</a></div></div>';
$stackResult = ( new HtmlTransformer() )->transform($stackHtml, array())->toArray();
$stack = $stackResult['blocks'][0] ?? array();

$assert('core/group' === ($stack['blockName'] ?? ''), '30: multi-child hero content stack stays a group, not columns', (string) ($stack['blockName'] ?? '(none)'));
$assert('hero-content' === (($stack['attrs']['className'] ?? '')), '31: multi-child hero content stack keeps source class for stylesheet materialization', json_encode($stack['attrs'] ?? array()));

$paragraphColorHtml = '<p class="has-text-color hero-tagline" style="color:var(--wp--preset--color--contrast)">Steeped daily.</p>';
$paragraphColorResult = ( new HtmlTransformer() )->transform($paragraphColorHtml, array())->toArray();
$paragraphColorMarkup = (string) ($paragraphColorResult['serialized_blocks'] ?? '');
$paragraphColorAttrs = $paragraphColorResult['blocks'][0]['attrs'] ?? array();

$assert(str_contains($paragraphColorMarkup, '<p class="has-contrast-color has-text-color hero-tagline">Steeped daily.</p>'), '32: paragraph preset text color emits one has-text-color token plus source class', $paragraphColorMarkup);
$assert(! str_contains($paragraphColorMarkup, 'has-text-color has-text-color'), '33: paragraph color support never duplicates has-text-color', $paragraphColorMarkup);
$assert('hero-tagline' === ($paragraphColorAttrs['className'] ?? ''), '34: paragraph className excludes generated color support classes', json_encode($paragraphColorAttrs));

$textPresetHtml = '<p class="masthead__bio" style="color:var(--wp--preset--color--text)">I am a cognitive neuroscientist.</p>';
$textPresetResult = ( new HtmlTransformer() )->transform($textPresetHtml, array())->toArray();
$textPresetBlock = $textPresetResult['blocks'][0] ?? array();
$textPresetAttrs = is_array($textPresetBlock['attrs'] ?? null) ? $textPresetBlock['attrs'] : array();
$textPresetInnerHtml = (string) ($textPresetBlock['innerHTML'] ?? '');

$assert(! isset($textPresetAttrs['textColor']), '35: preset text slug stays custom color to avoid duplicate has-text-color classes', json_encode($textPresetAttrs));
$assert('var(--wp--preset--color--text)' === ($textPresetAttrs['style']['color']['text'] ?? ''), '36: preset text color value remains visually preserved', json_encode($textPresetAttrs['style'] ?? array()));
$assert('masthead__bio' === ($textPresetAttrs['className'] ?? ''), '37: source paragraph class remains preserved without generated color class leakage', json_encode($textPresetAttrs));
$assert(! str_contains($textPresetInnerHtml, 'has-text-color has-text-color'), '38: rendered paragraph does not carry duplicate generated text color classes', $textPresetInnerHtml);

$paintCss = '.pricing-card{background:radial-gradient(circle at 20% 10%,rgba(255,255,255,.9),rgba(255,255,255,0) 38%),linear-gradient(180deg,#fff,#f5efe4);background-position:center top;background-size:120% 80%,100% 100%;background-repeat:no-repeat;box-shadow:0 28px 80px rgba(20,12,4,.18);padding:2rem;border-radius:24px}';
$paintHtml = '<main><section class="pricing-card"><h2>Roast Club</h2><p>Fresh coffee every week.</p></section></main>';
$paintResult = ( new HtmlTransformer() )->transform($paintHtml, array('static_css' => $paintCss))->toArray();
$paintBlock = $paintResult['blocks'][0] ?? array();
$paintAttrs = is_array($paintBlock['attrs'] ?? null) ? $paintBlock['attrs'] : array();

$assert('pricing-card' === ($paintAttrs['className'] ?? ''), '39: high-value card wrapper keeps source class for class-owned paint CSS', json_encode($paintAttrs));
$assert(! isset($paintAttrs['style']['box-shadow']), '40: class-owned box-shadow is not stored as an unsupported block style attr', json_encode($paintAttrs['style'] ?? array()));
$assert(! isset($paintAttrs['style']['background-position']) && ! isset($paintAttrs['style']['background-size']), '41: background layer controls stay out of block style attrs', json_encode($paintAttrs['style'] ?? array()));

$rulesMethod = new ReflectionMethod(HtmlTransformer::class, 'staticStyleRules');
$paintRules = $rulesMethod->invoke(new HtmlTransformer(), '', $paintCss);
$paintDeclarations = $paintRules[0]['declarations'] ?? array();

$assert(($paintDeclarations['background'] ?? '') === 'radial-gradient(circle at 20% 10%,rgba(255,255,255,.9),rgba(255,255,255,0) 38%),linear-gradient(180deg,#fff,#f5efe4)', '42: radial and layered backgrounds survive safe CSS resolution', json_encode($paintDeclarations));
$assert(($paintDeclarations['background-position'] ?? '') === 'center top', '43: background-position survives safe CSS resolution', json_encode($paintDeclarations));
$assert(($paintDeclarations['background-size'] ?? '') === '120% 80%,100% 100%', '44: background-size survives safe CSS resolution', json_encode($paintDeclarations));
$assert(($paintDeclarations['background-repeat'] ?? '') === 'no-repeat', '45: background-repeat survives safe CSS resolution', json_encode($paintDeclarations));
$assert(($paintDeclarations['box-shadow'] ?? '') === '0 28px 80px rgba(20,12,4,.18)', '46: box-shadow survives safe CSS resolution', json_encode($paintDeclarations));

if ( $failures > 0 ) {
    fwrite(STDERR, "Block style support conversion tests: {$failures} failed, {$passes} passed\n");
    exit(1);
}

fwrite(STDOUT, "Block style support conversion tests: {$passes} passed\n");
