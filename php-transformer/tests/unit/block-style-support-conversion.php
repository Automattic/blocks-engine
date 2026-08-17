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
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\StyleAttributeMapper;

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

$uniformBorder = ( new StyleAttributeMapper() )->map(array(
    'border-top-width' => '12.808px',
    'border-right-width' => '12.808px',
    'border-bottom-width' => '12.808px',
    'border-left-width' => '12.808px',
    'border-style' => 'solid',
    'border-color' => '#ffffff',
));
$assert(
    array( 'width' => '12.808px', 'style' => 'solid', 'color' => '#ffffff' ) === ($uniformBorder['style']['border'] ?? array()),
    '8a: equal physical border widths collapse without redundant side objects',
    json_encode($uniformBorder)
);

$classBorderImage = ( new HtmlTransformer() )->transform(
    '<img class="photo" src="/photo.jpg" alt="Portrait">',
    array('static_css' => '.photo{border-top-width:12.808px;border-right-width:12.808px;border-bottom-width:12.808px;border-left-width:12.808px;border-style:solid;border-color:#fff}')
)->toArray();
$classBorderImageAttrs = $classBorderImage['blocks'][0]['attrs'] ?? array();
$assert('12.808px' === ($classBorderImageAttrs['style']['border']['width'] ?? ''), '8b: matched class CSS physical border widths reach native image support', json_encode($classBorderImageAttrs));

$groupHtml = '<div class="hero-row" style="display:flex;justify-content:center;gap:1rem;min-height:100svh;padding:2rem;background:var(--wp--preset--color--base)"><p>Hello</p><p>World</p></div>';
$groupResult = ( new HtmlTransformer() )->transform($groupHtml, array())->toArray();
$group = $groupResult['blocks'][0] ?? array();
$groupAttrs = is_array($group['attrs'] ?? null) ? $group['attrs'] : array();
$groupInnerHtml = (string) ($group['innerHTML'] ?? '');

$assert('core/group' === ($group['blockName'] ?? ''), '9: horizontal flex rows retain the supported core/group contract', (string) ($group['blockName'] ?? '(none)'));
$assert(! isset($groupAttrs['layout']), '10: CSS-owned layout does not opt into Gutenberg layout metadata', json_encode($groupAttrs));
$assert(str_contains($groupInnerHtml, 'wp-block-group') && str_contains($groupInnerHtml, 'hero-row') && str_contains($groupInnerHtml, 'blocks-engine-css-owned-layout'), '11: rendered wrapper uses core Group with the CSS-owned marker', $groupInnerHtml);
$assert(! str_contains($groupInnerHtml, 'is-layout-flex'), '12: CSS-owned layout does not opt into core flex layout CSS', $groupInnerHtml);
$assert(! str_contains($groupInnerHtml, 'gap:1rem'), '13: author layout wrapper stores no blockGap', $groupInnerHtml);
$assert(! str_contains($groupInnerHtml, 'display:flex') && ! str_contains($groupInnerHtml, 'justify-content:center'), '14: source CSS remains the layout authority', $groupInnerHtml);
$assert(! isset($groupAttrs['style']['spacing']['blockGap']), '15: core group save omits block gap without a core layout attribute', json_encode($groupAttrs));
$assert(str_contains($groupInnerHtml, 'min-height:100svh'), '16: core group retains supported dimensions', $groupInnerHtml);

$nativeGridHtml = '<div class="tpl-grid" style="display:grid;grid-template-columns:repeat(auto-fit, minmax(290px, 1fr));gap:1.2rem"><p>Fallback card</p></div>';
$nativeGridResult = ( new HtmlTransformer() )->transform($nativeGridHtml, array())->toArray();
$nativeGrid = $nativeGridResult['blocks'][0] ?? array();
$nativeGridAttrs = is_array($nativeGrid['attrs'] ?? null) ? $nativeGrid['attrs'] : array();
$nativeGridMarkup = (string) ($nativeGridResult['serialized_blocks'] ?? '');
$nativeGridCss = implode("\n", array_map(static fn (array $asset): string => (string) ($asset['content'] ?? ''), is_array($nativeGridResult['assets'] ?? null) ? $nativeGridResult['assets'] : array()));

$assert('grid' === ($nativeGridAttrs['layout']['type'] ?? ''), '16a: representative tpl-grid shape retains native Group grid layout', json_encode($nativeGridAttrs));
$assert(! isset($nativeGridAttrs['style']['spacing']['blockGap']), '16b: native Group grids do not emit noncanonical blockGap attributes', json_encode($nativeGridAttrs));
$assert(! str_contains($nativeGridMarkup, 'gap:1.2rem'), '16c: native Group grid markup omits inline gap that Gutenberg save does not reproduce', $nativeGridMarkup);
$assert(str_contains($nativeGridCss, 'gap:1.2rem !important'), '16d: inline native Group grid gap moves to the generated geometry carrier', $nativeGridCss);

$unorderedListSource = '<ul style="list-style:none"><li>Alpha</li></ul>';
$unorderedListResult = ( new HtmlTransformer() )->transform($unorderedListSource)->toArray();
$unorderedListAttrs = $unorderedListResult['blocks'][0]['attrs'] ?? array();
$unorderedListCss = implode("\n", array_map(static fn (array $asset): string => (string) ($asset['content'] ?? ''), is_array($unorderedListResult['assets'] ?? null) ? $unorderedListResult['assets'] : array()));
$assert(str_contains($unorderedListSource, 'list-style:none'), 'L1 precondition: unordered list fixture authors list-style:none', $unorderedListSource);
$assert(str_contains((string) ($unorderedListAttrs['className'] ?? ''), 'be-inline-geometry-') && str_contains($unorderedListCss, 'list-style:none') && ! str_contains($unorderedListCss, 'list-style:none !important'), 'L1: unordered list carries authored list-style:none without !important', $unorderedListCss);

$orderedListResult = ( new HtmlTransformer() )->transform('<ol style="list-style:none"><li>First</li></ol>')->toArray();
$orderedListAttrs = $orderedListResult['blocks'][0]['attrs'] ?? array();
$orderedListCss = implode("\n", array_map(static fn (array $asset): string => (string) ($asset['content'] ?? ''), is_array($orderedListResult['assets'] ?? null) ? $orderedListResult['assets'] : array()));
$assert(true === ($orderedListAttrs['ordered'] ?? false) && str_contains((string) ($orderedListAttrs['className'] ?? ''), 'be-inline-geometry-') && str_contains($orderedListCss, 'list-style:none') && ! str_contains($orderedListCss, 'list-style:none !important'), 'L2: ordered list carries authored list-style:none without !important', $orderedListCss);

$plainListResult = ( new HtmlTransformer() )->transform('<ul><li>Marker remains</li></ul>')->toArray();
$plainListMarkup = (string) ($plainListResult['serialized_blocks'] ?? '');
$assert(
    '<!-- wp:list --><ul class="wp-block-list"><!-- wp:list-item {"content":"Marker remains"} --><li>Marker remains</li><!-- /wp:list-item --></ul><!-- /wp:list -->' === $plainListMarkup
    && array() === ($plainListResult['assets'] ?? array()),
    'L3: a list without authored list-style keeps canonical marker-rendering output and gains no carrier',
    $plainListMarkup
);

$listLonghandsResult = ( new HtmlTransformer() )->transform('<ul style="list-style-type:square;list-style-position:inside;list-style-image:url(https://example.com/marker.svg)"><li>Detailed marker</li></ul>')->toArray();
$listLonghandsCss = implode("\n", array_map(static fn (array $asset): string => (string) ($asset['content'] ?? ''), is_array($listLonghandsResult['assets'] ?? null) ? $listLonghandsResult['assets'] : array()));
$assert(
    str_contains($listLonghandsCss, 'list-style-type:square')
    && str_contains($listLonghandsCss, 'list-style-position:inside')
    && str_contains($listLonghandsCss, 'list-style-image:url(https://example.com/marker.svg)')
    && ! str_contains($listLonghandsCss, '!important'),
    'list-style longhands ride the generated carrier without !important',
    $listLonghandsCss
);

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

$buttonResult = ( new HtmlTransformer() )->transform('<a class="button" href="/buy" style="padding:8px 12px;background:#135e96;width:50%;max-width:20rem;aspect-ratio:2 / 1">Buy</a>', array())->toArray();
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

$importantButton = ( new HtmlTransformer() )->transform('<a class="button" href="/buy" style="padding:8px 12px;background:#135e96;width:50%!important;min-width:12rem!important;max-width:30rem!important;height:3rem!important;aspect-ratio:2 / 1!important;flex-basis:20rem!important">Buy</a>', array())->toArray();
$importantButtonMarkup = (string) ($importantButton['serialized_blocks'] ?? '');
$assert(! str_contains($importantButtonMarkup, 'wp-block-button__width-50') && str_contains($importantButtonMarkup, 'width:50%!important') && str_contains($importantButtonMarkup, 'min-width:12rem!important') && str_contains($importantButtonMarkup, 'max-width:30rem!important') && str_contains($importantButtonMarkup, 'height:3rem!important') && str_contains($importantButtonMarkup, 'aspect-ratio:2 / 1!important') && str_contains($importantButtonMarkup, 'flex-basis:20rem!important'), '47: core/button preserves source-important geometry on its wrapper without native width classes', $importantButtonMarkup);

$variableGeometryHtml = '<p style="--box-base:30rem;--box-width:var(--box-base,20rem);width:var(--box-width)">Variable geometry</p>';
$variableGeometryResult = ( new HtmlTransformer() )->transform($variableGeometryHtml, array())->toArray();
$variableGeometryMarkup = (string) ($variableGeometryResult['serialized_blocks'] ?? '');
$variableGeometryCss = implode("\n", array_map(static fn (array $asset): string => (string) ($asset['content'] ?? ''), $variableGeometryResult['assets'] ?? array()));
$variableGeometryParity = ( new StaticStyleParityRunner() )->compareSourceToTransformWithGeometry($variableGeometryHtml);
$assert(! str_contains($variableGeometryMarkup, '--box-base:') && str_contains($variableGeometryMarkup, 'be-inline-geometry-') && str_contains($variableGeometryCss, '--box-base:30rem !important;--box-width:var(--box-base,20rem) !important'), '48: local custom properties used by geometry survive in generated carrier CSS', $variableGeometryMarkup);
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

$assert('core/group' === ($columnsMaxWidthBlock['blockName'] ?? ''), '24: horizontal flex wrapper retains the core/group consumer contract', (string) ($columnsMaxWidthBlock['blockName'] ?? '(none)'));
$assert(! isset($columnsMaxWidthAttrs['style']['dimensions']), '25: CSS-owned group omits unsupported core dimensions support', json_encode($columnsMaxWidthAttrs));
$assert(! str_contains($columnsMaxWidthMarkup, 'max-width:var(--max-w)'), '26: CSS-owned group leaves unsupported geometry to source CSS', $columnsMaxWidthMarkup);
$gridGeometryHtml = '<div class="layout-grid"><div>Left</div><div>Right</div></div>';
$gridGeometryResult = ( new HtmlTransformer() )->transform($gridGeometryHtml, array('static_css' => '.layout-grid{display:grid;grid-template-columns:1fr 1fr;max-width:72rem;margin:0 auto;padding:0 2rem}'))->toArray();
$gridGeometryBlock = $gridGeometryResult['blocks'][0] ?? array();
$gridGeometryMarkup = (string) ($gridGeometryResult['serialized_blocks'] ?? '');
$assert('core/group' === ($gridGeometryBlock['blockName'] ?? ''), '27: CSS-owned grids stay core/group instead of core Columns', (string) ($gridGeometryBlock['blockName'] ?? '(none)'));
$assert(! str_contains($gridGeometryMarkup, '<!-- wp:columns') && ! str_contains($gridGeometryMarkup, 'max-width:72rem'), '28: CSS-owned grid geometry never enters a core Columns save wrapper', $gridGeometryMarkup);

$nativeColumnsHtml = '<div class="wp-block-columns"><div class="wp-block-column"><p>Left</p></div><div class="wp-block-column"><p>Right</p></div></div>';
$nativeColumnsResult = ( new HtmlTransformer() )->transform($nativeColumnsHtml, array('static_css' => '.wp-block-columns{display:flex}'))->toArray();
$nativeColumnsBlock = $nativeColumnsResult['blocks'][0] ?? array();
$assert('core/columns' === ($nativeColumnsBlock['blockName'] ?? ''), '29: explicit native Columns markup remains core Columns', (string) ($nativeColumnsBlock['blockName'] ?? '(none)'));

$labelHtml = '<section class="pricing"><div class="section-head"><div class="tag">Pricing</div><h2>Simple plans</h2></div><article class="pricing-card"><div class="tier-name">Team</div><div class="tier-price"><span class="amount">$29</span>/mo</div><div class="use-case-result">Launch faster</div></article></section>';
$labelCss = '.tag{display:inline-flex;align-items:center;gap:6px;padding:4px 12px;border-radius:100px}.pricing-card{padding:2rem}.tier-name{font-family:monospace;font-size:11px;letter-spacing:.12em;text-transform:uppercase}.tier-price{display:flex;align-items:flex-end;gap:6px}.use-case-result{display:flex;align-items:center;gap:8px;padding:10px 14px;border-radius:6px}';
$labelResult = ( new HtmlTransformer() )->transform($labelHtml, array('static_css' => $labelCss))->toArray();
$labelMarkup = (string) ($labelResult['serialized_blocks'] ?? '');

$assert(str_contains($labelMarkup, '<div class="wp-block-group tag'), '25: box-model section badge stays a group wrapper', $labelMarkup);
$assert(str_contains($labelMarkup, '<p class="tier-name">Team</p>'), '26: typography-only card tier label collapses to a styled paragraph so its font scale applies', $labelMarkup);
$assert(str_contains($labelMarkup, '<div class="wp-block-group tier-price blocks-engine-css-owned-layout'), '27: CSS-owned card price row uses the marked core group wrapper', $labelMarkup);
$assert(str_contains($labelMarkup, '<div class="wp-block-group use-case-result'), '28: box-model card result row stays a group wrapper', $labelMarkup);
$assert(! preg_match('/<!-- wp:group[^>]*"className":"tier-name"/', $labelMarkup), '29: typography-only tier label does not round-trip as a group wrapping a default paragraph', $labelMarkup);

// A universal reset (`* { margin: 0; padding: 0 }`) sets zero-valued box
// properties on every element. Those must not count as box chrome, or an
// eyebrow like `<div class="eyebrow">The Shop</div>` would be disqualified from
// collapsing and would render at the wrong scale with default block spacing.
// The wrapper also uses `display:inline-flex;gap` only to align a `::before`
// dash — flex without child elements is not block geometry.
$resetHtml = '<header class="page-header"><div class="eyebrow">The Shop</div><h1>Carry something home.</h1></header>';
$resetCss = '*,*::before,*::after{margin:0;padding:0}.eyebrow{display:inline-flex;align-items:center;gap:0.8rem;font-size:0.68rem;letter-spacing:0.22em;text-transform:uppercase}.eyebrow::before{content:"";display:block;width:2.2rem;height:1px}';
$resetResult = ( new HtmlTransformer() )->transform($resetHtml, array('static_css' => $resetCss))->toArray();
$resetMarkup = (string) ($resetResult['serialized_blocks'] ?? '');
$assert(str_contains($resetMarkup, '<p class="eyebrow">The Shop</p>'), '29b: a pure-text eyebrow under a universal zero reset collapses to a styled paragraph', $resetMarkup);
$assert(! preg_match('/<!-- wp:group[^>]*"className":"eyebrow"/', $resetMarkup), '29c: the zero-reset eyebrow does not round-trip as a one-child group', $resetMarkup);

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
$assert(! isset($paintAttrs['style']['color']['background']) && ! isset($paintAttrs['style']['color']['gradient']) && ! isset($paintAttrs['backgroundColor']), '47: class-owned layered paint does not become competing color support', json_encode($paintAttrs));
$assert(! str_contains((string) ($paintResult['serialized_blocks'] ?? ''), 'has-background'), '48: class-owned layered paint emits no Gutenberg background class', (string) ($paintResult['serialized_blocks'] ?? ''));

$classGradientHtml = '<main><section class="artist-card"><p>Artist</p></section></main>';
$classGradientCss = '.artist-card{background:linear-gradient(135deg,#ff5c8a 0%,#583c87 100%);padding:2rem}';
$classGradientResult = ( new HtmlTransformer() )->transform($classGradientHtml, array('static_css' => $classGradientCss))->toArray();
$classGradient = $classGradientResult['blocks'][0] ?? array();
$classGradientAttrs = is_array($classGradient['attrs'] ?? null) ? $classGradient['attrs'] : array();
$classGradientMarkup = (string) ($classGradientResult['serialized_blocks'] ?? '');

$assert('artist-card' === ($classGradientAttrs['className'] ?? ''), '49: class-owned directional gradient retains its author class', json_encode($classGradientAttrs));
$assert(! isset($classGradientAttrs['style']['color']) && ! isset($classGradientAttrs['backgroundColor']), '50: class-owned directional gradient stays out of color support', json_encode($classGradientAttrs));
$assert(! str_contains($classGradientMarkup, 'has-background') && ! str_contains($classGradientMarkup, 'background:linear-gradient'), '51: class-owned directional gradient does not serialize competing support paint', $classGradientMarkup);

$mapper = new StyleAttributeMapper();
$inlineGradient = $mapper->map(array('background' => 'linear-gradient(135deg,#ff5c8a 0%,#583c87 100%)'));
$inlineSolid = $mapper->map(array('background-color' => '#243b53'));
$assert('linear-gradient(135deg,#ff5c8a 0%,#583c87 100%)' === ($inlineGradient['style']['color']['gradient'] ?? ''), '52: inline directional gradient remains Gutenberg color support', json_encode($inlineGradient));
$assert('#243b53' === ($inlineSolid['style']['color']['background'] ?? ''), '53: inline solid background remains Gutenberg color support', json_encode($inlineSolid));
$assert(str_contains($mapper->serialize($inlineGradient['style'])['style'], 'background:linear-gradient(135deg,#ff5c8a 0%,#583c87 100%)') && str_contains($mapper->serialize($inlineSolid['style'])['style'], 'background-color:#243b53'), '54: inline paint support serializes valid Gutenberg-compatible declarations');

$inlineSolidResult = ( new HtmlTransformer() )->transform('<main><section style="background-color:#243b53"><p>Solid</p></section></main>', array())->toArray();
$inlineSolidBlock = $inlineSolidResult['blocks'][0] ?? array();
$inlineSolidMarkup = (string) ($inlineSolidResult['serialized_blocks'] ?? '');
$assert('#243b53' === ($inlineSolidBlock['attrs']['style']['color']['background'] ?? ''), '55: HtmlTransformer maps inline solid background to native color support', json_encode($inlineSolidBlock));
$assert(str_contains($inlineSolidMarkup, 'has-background') && str_contains($inlineSolidMarkup, 'background-color:#243b53'), '56: inline solid background serializes Gutenberg support markup', $inlineSolidMarkup);

$inlineGradientResult = ( new HtmlTransformer() )->transform('<main><section style="background:linear-gradient(135deg,#ff5c8a 0%,#583c87 100%)"><p>Gradient</p></section></main>', array())->toArray();
$inlineGradientBlock = $inlineGradientResult['blocks'][0] ?? array();
$inlineGradientMarkup = (string) ($inlineGradientResult['serialized_blocks'] ?? '');
$assert('linear-gradient(135deg,#ff5c8a 0%,#583c87 100%)' === ($inlineGradientBlock['attrs']['style']['color']['gradient'] ?? ''), '57: HtmlTransformer maps inline directional gradient to native color support', json_encode($inlineGradientBlock));
$assert(str_contains($inlineGradientMarkup, 'has-background') && str_contains($inlineGradientMarkup, 'background:linear-gradient(135deg,#ff5c8a 0%,#583c87 100%)'), '58: inline directional gradient serializes Gutenberg support markup', $inlineGradientMarkup);

$compoundPaintCss = '.gallery{--card-overlay:#203040;--card-start:#ff5c8a;--card-end:#583c87}.gallery .artist-card{background:radial-gradient(circle at 20% 10%,rgba(255,255,255,.8),transparent 40%),linear-gradient(135deg,var(--card-start),var(--card-end));background-position:center top;background-size:120% 80%,100% 100%;background-repeat:no-repeat}';
$compoundPaintHtml = '<main class="gallery"><section class="artist-card" style="background-color:var(--card-overlay)"><p>Artist</p></section></main>';
$compoundPaintResult = ( new HtmlTransformer() )->transform($compoundPaintHtml, array('static_css' => $compoundPaintCss))->toArray();
$compoundPaintBlock = $compoundPaintResult['blocks'][0]['innerBlocks'][0] ?? array();
$compoundPaintMarkup = (string) ($compoundPaintResult['serialized_blocks'] ?? '');
$compoundPaintCssAsset = implode("\n", array_map(static fn (array $asset): string => 'css' === ($asset['kind'] ?? '') ? (string) ($asset['content'] ?? '') : '', $compoundPaintResult['assets'] ?? array()));

$assert('var(--card-overlay)' === ($compoundPaintBlock['attrs']['style']['color']['background'] ?? ''), '59: inherited CSS variable used by inline background-color remains the only mapped paint override', json_encode($compoundPaintBlock));
$assert(! isset($compoundPaintBlock['attrs']['style']['color']['gradient']) && str_contains($compoundPaintMarkup, 'has-background') && str_contains($compoundPaintMarkup, 'background-color:var(--card-overlay)'), '60: inline background-color support does not consume class-owned layers', $compoundPaintMarkup);
$assert(str_contains($compoundPaintCssAsset, '.gallery{--card-overlay:#203040;--card-start:#ff5c8a;--card-end:#583c87}') && str_contains($compoundPaintCssAsset, 'artist-card') && str_contains($compoundPaintCssAsset, 'background:radial-gradient(circle at 20% 10%,rgba(255,255,255,.8),transparent 40%),linear-gradient(135deg,var(--card-start),var(--card-end))') && str_contains($compoundPaintCssAsset, 'background-position:center top') && str_contains($compoundPaintCssAsset, 'background-size:120% 80%,100% 100%') && str_contains($compoundPaintCssAsset, 'background-repeat:no-repeat'), '61: projected compound selector preserves inherited variables and layered background shorthand', $compoundPaintCssAsset);

$compoundSourceProbe = ( new StaticStyleParityProbe() )->extract($compoundPaintHtml, $compoundPaintCss);
$compoundCandidateProbe = ( new StaticStyleParityProbe() )->extract(StaticStyleParityRunner::candidateHtmlFromSerializedBlocks($compoundPaintMarkup), $compoundPaintCssAsset);
$assert(0 < (int) ($compoundSourceProbe['summary']['styled_total'] ?? 0) && 0 < (int) ($compoundCandidateProbe['summary']['styled_total'] ?? 0), '62: layered background cascade case produces nonzero source and candidate style probes', json_encode(array($compoundSourceProbe['summary'] ?? array(), $compoundCandidateProbe['summary'] ?? array())));

$amberQuoteHtml = '<blockquote style="margin:0 0 1.6rem;padding-left:1.2rem;border-left:2px solid var(--secondary);font-family:var(--head);font-size:2.2rem;font-weight:700;letter-spacing:-.02em">Comfort is a result, never a method</blockquote>';
$amberQuoteResult = ( new HtmlTransformer() )->transform($amberQuoteHtml, array())->toArray();
$amberQuote = $amberQuoteResult['blocks'][0] ?? array();
$amberQuoteAttrs = is_array($amberQuote['attrs'] ?? null) ? $amberQuote['attrs'] : array();
$amberQuoteCss = implode("\n", array_map(static fn (array $asset): string => 'css' === ($asset['kind'] ?? '') ? (string) ($asset['content'] ?? '') : '', $amberQuoteResult['assets'] ?? array()));
$amberQuoteLeftBorder = is_array($amberQuoteAttrs['style']['border']['left'] ?? null) ? $amberQuoteAttrs['style']['border']['left'] : array();

$assert(
    'core/quote' === ($amberQuote['blockName'] ?? '')
        && '1.2rem' === ($amberQuoteAttrs['style']['spacing']['padding']['left'] ?? '')
        && 'var(--head)' === ($amberQuoteAttrs['style']['typography']['fontFamily'] ?? '')
        && '2.2rem' === ($amberQuoteAttrs['style']['typography']['fontSize'] ?? '')
        && '700' === ($amberQuoteAttrs['style']['typography']['fontWeight'] ?? '')
        && '-.02em' === ($amberQuoteAttrs['style']['typography']['letterSpacing'] ?? ''),
    'N1 setup: amber quote exercises native spacing and typography conversion',
    json_encode($amberQuote)
);
$assert(
    array( 'width' => '2px', 'style' => 'solid', 'color' => 'var(--secondary)' ) === $amberQuoteLeftBorder
        || (
            str_contains($amberQuoteCss, 'border-left-color:var(--secondary) !important')
            && str_contains($amberQuoteCss, 'border-left-style:solid !important')
            && str_contains($amberQuoteCss, 'border-left-width:2px !important')
        ),
    'N1: amber quote retains its authored left border through native support or a carrier',
    json_encode(array( 'attrs' => $amberQuoteAttrs, 'css' => $amberQuoteCss ))
);

$borderMapper = new StyleAttributeMapper();
$shorthandBorder = $borderMapper->map(array( 'border' => '2px solid red' ));
$leftBorder = $borderMapper->map(array( 'border-left' => '2px solid var(--secondary)' ));
$individualBorder = $borderMapper->map(array(
    'border-width' => '3px',
    'border-style' => 'dashed',
    'border-color' => '#123456',
));
$globalThenLeftBorder = $borderMapper->map(array(
    'border' => '4px dashed blue',
    'border-left' => '2px solid red',
));
$leftThenGlobalBorder = $borderMapper->map(array(
    'border-left' => '2px solid red',
    'border' => '4px dashed blue',
));
$globalThenNoLeftBorder = $borderMapper->map(array(
    'border' => '4px solid blue',
    'border-left' => 'none',
));
$globalThenColorOnlyLeftBorder = $borderMapper->map(array(
    'border' => '4px solid blue',
    'border-left' => 'red',
));
$assert(
    array( 'width' => '2px', 'style' => 'solid', 'color' => 'red' ) === ($shorthandBorder['style']['border'] ?? array()),
    'N2: border shorthand maps to native width, style, and color',
    json_encode($shorthandBorder)
);
$assert(
    array( 'left' => array( 'width' => '2px', 'style' => 'solid', 'color' => 'var(--secondary)' ) ) === ($leftBorder['style']['border'] ?? array()),
    'N2: per-side border shorthand maps to the native left-side object',
    json_encode($leftBorder)
);
$assert(
    array( 'width' => '3px', 'style' => 'dashed', 'color' => '#123456' ) === ($individualBorder['style']['border'] ?? array()),
    'N2: individual border width, style, and color forms map natively',
    json_encode($individualBorder)
);
$assert(
    array(
        'width' => '4px',
        'style' => 'dashed',
        'color' => 'blue',
        'left' => array( 'width' => '2px', 'style' => 'solid', 'color' => 'red' ),
    ) === ($globalThenLeftBorder['style']['border'] ?? array()),
    'N2: a later per-side shorthand overrides an earlier global shorthand',
    json_encode($globalThenLeftBorder)
);
$assert(
    array( 'width' => '4px', 'style' => 'dashed', 'color' => 'blue' ) === ($leftThenGlobalBorder['style']['border'] ?? array()),
    'N2: a later global shorthand resets an earlier per-side shorthand',
    json_encode($leftThenGlobalBorder)
);
$assert(
    'none' === ($globalThenNoLeftBorder['style']['border']['left']['style'] ?? ''),
    'N2: a later none side shorthand actively cancels an earlier global border',
    json_encode($globalThenNoLeftBorder)
);
$assert(
    array( 'width' => 'medium', 'style' => 'none', 'color' => 'red' ) === ($globalThenColorOnlyLeftBorder['style']['border']['left'] ?? array()),
    'N2: a partial side shorthand resets omitted components instead of inheriting the global border',
    json_encode($globalThenColorOnlyLeftBorder)
);

$nativeBorderGroupResult = ( new HtmlTransformer() )->transform(
    '<section style="border-left:2px solid var(--secondary)"><p>Native border</p></section>',
    array()
)->toArray();
$nativeBorderGroup = $nativeBorderGroupResult['blocks'][0] ?? array();
$nativeBorderGroupAttrs = is_array($nativeBorderGroup['attrs'] ?? null) ? $nativeBorderGroup['attrs'] : array();
$nativeBorderGroupMarkup = (string) ($nativeBorderGroupResult['serialized_blocks'] ?? '');
$assert(
    'core/group' === ($nativeBorderGroup['blockName'] ?? '')
        && array( 'width' => '2px', 'style' => 'solid', 'color' => 'var(--secondary)' ) === ($nativeBorderGroupAttrs['style']['border']['left'] ?? array())
        && ! str_contains((string) ($nativeBorderGroupAttrs['className'] ?? ''), 'be-inline-geometry-')
        && str_contains($nativeBorderGroupMarkup, 'border-left-color:var(--secondary)')
        && str_contains($nativeBorderGroupMarkup, 'border-left-style:solid')
        && str_contains($nativeBorderGroupMarkup, 'border-left-width:2px'),
    'N2: a border-supporting Group serializes the native per-side border',
    json_encode(array( 'block' => $nativeBorderGroup, 'markup' => $nativeBorderGroupMarkup ))
);

$matchedNativeBorderGroupResult = ( new HtmlTransformer() )->transform(
    '<section class="matched-border"><p>Matched native border</p></section>',
    array( 'static_css' => '.matched-border{border-left:3px dotted #123456}' )
)->toArray();
$matchedNativeBorderGroup = $matchedNativeBorderGroupResult['blocks'][0] ?? array();
$matchedNativeBorderGroupAttrs = is_array($matchedNativeBorderGroup['attrs'] ?? null) ? $matchedNativeBorderGroup['attrs'] : array();
$assert(
    array( 'width' => '3px', 'style' => 'dotted', 'color' => '#123456' ) === ($matchedNativeBorderGroupAttrs['style']['border']['left'] ?? array())
        && ! str_contains((string) ($matchedNativeBorderGroupAttrs['className'] ?? ''), 'be-inline-geometry-'),
    'N2: matched stylesheet per-side shorthand reaches native Group border support',
    json_encode($matchedNativeBorderGroupResult)
);

$repeatedBorderGroupResult = ( new HtmlTransformer() )->transform(
    '<section style="border-left:2px solid red;border:4px dashed blue;border-left:1px dotted green"><p>Ordered native border</p></section>',
    array()
)->toArray();
$repeatedBorderGroupAttrs = $repeatedBorderGroupResult['blocks'][0]['attrs'] ?? array();
$assert(
    '4px' === ($repeatedBorderGroupAttrs['style']['border']['width'] ?? '')
        && 'dashed' === ($repeatedBorderGroupAttrs['style']['border']['style'] ?? '')
        && 'blue' === ($repeatedBorderGroupAttrs['style']['border']['color'] ?? '')
        && array( 'width' => '1px', 'style' => 'dotted', 'color' => 'green' ) === ($repeatedBorderGroupAttrs['style']['border']['left'] ?? array()),
    'N2: a repeated side shorthand after a global shorthand keeps its final authored position',
    json_encode($repeatedBorderGroupResult)
);

$radiusRoundTrip = $borderMapper->map(array( 'border-radius' => 'var(--radius)' ));
$assert(
    array( 'radius' => 'var(--radius)' ) === ($radiusRoundTrip['style']['border'] ?? array())
        && 'border-radius:var(--radius)' === $borderMapper->serialize($radiusRoundTrip['style'])['style'],
    'N3: existing radius mapping and serialization round-trip unchanged',
    json_encode($radiusRoundTrip)
);

$assert(
    ! isset($amberQuoteAttrs['style']['border'])
        && str_contains((string) ($amberQuoteAttrs['className'] ?? ''), 'be-inline-geometry-')
        && str_contains($amberQuoteCss, 'border-left-color:var(--secondary) !important')
        && str_contains($amberQuoteCss, 'border-left-style:solid !important')
        && str_contains($amberQuoteCss, 'border-left-width:2px !important'),
    'N4: a Quote without declared border support uses the carrier and emits no ignored native border attribute',
    json_encode(array( 'attrs' => $amberQuoteAttrs, 'css' => $amberQuoteCss ))
);

// The core style engine attaches `has-border-color` to the uniform
// `border.color` definition only; `border.{side}` carries no classnames. The
// class is an all-sides signal because core ships
// `html :where(.has-border-color){border-style:solid}` in
// wp-includes/css/dist/block-library/common.css. Emitting it for a one-sided
// authored border makes WordPress paint the three unauthored sides at the
// initial `medium` width (3px) in `currentColor`, growing the box by 6px.
$sideOnlyBorderSerialized = $borderMapper->serialize(array(
    'border' => array( 'left' => array( 'width' => '2px', 'style' => 'solid', 'color' => 'var(--secondary)' ) ),
));
$assert(
    '' === $sideOnlyBorderSerialized['classes'],
    'N5: a per-side border color emits no all-sides has-border-color class',
    json_encode($sideOnlyBorderSerialized)
);
$assert(
    'border-left-color:var(--secondary);border-left-style:solid;border-left-width:2px' === $sideOnlyBorderSerialized['style'],
    'N5: a per-side border still serializes its own side declarations',
    json_encode($sideOnlyBorderSerialized)
);

$uniformBorderSerialized = $borderMapper->serialize(array(
    'border' => array( 'width' => '2px', 'style' => 'solid', 'color' => 'red' ),
));
$assert(
    'has-border-color' === $uniformBorderSerialized['classes'],
    'N5: a uniform border color keeps the core has-border-color class',
    json_encode($uniformBorderSerialized)
);

$assert(
    ! str_contains($nativeBorderGroupMarkup, 'has-border-color'),
    'N5: a Group with only an authored left border serializes without has-border-color',
    $nativeBorderGroupMarkup
);

if ( $failures > 0 ) {
    fwrite(STDERR, "Block style support conversion tests: {$failures} failed, {$passes} passed\n");
    exit(1);
}

fwrite(STDOUT, "Block style support conversion tests: {$passes} passed\n");
