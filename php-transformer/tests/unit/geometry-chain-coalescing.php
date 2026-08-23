<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

$assert = static function (bool $condition, string $message): void { if (!$condition) throw new RuntimeException($message); };
$transform = static fn(string $html, array $options = array()): array => (new HtmlTransformer())->transform($html, $options)->toArray();
$engineCss = static function (array $result): string {
    return implode('', array_map(static fn(array $asset): string => (string) ($asset['content'] ?? ''), $result['assets'] ?? array()));
};

$coalesced = $transform('<div class="image-carrier" style="padding-top:0;margin-left:0;text-align:left"><img src="hero.jpg" alt="Hero" width="640" height="360"></div>');
$image = $coalesced['blocks'][0] ?? array();
$markup = (string) ($coalesced['serialized_blocks'] ?? '');
$assert('core/image' === ($image['blockName'] ?? null) && array() === ($image['innerBlocks'] ?? null), 'A render-neutral carrier around a synthetic image coalesces into the native image block.');
$assert(str_contains((string) ($image['attrs']['className'] ?? ''), 'image-carrier') && str_contains((string) ($image['attrs']['className'] ?? ''), 'blocks-engine-synthetic-image-figure'), 'Coalescing moves the carrier selector and synthetic figure class onto the image block.');
$assert('640px' === ($image['attrs']['width'] ?? null) && '360px' === ($image['attrs']['height'] ?? null) && str_contains($markup, 'src="hero.jpg"'), 'Image geometry and source survive coalescing.');

$maxDepth = static function (array $blocks, int $depth = 0) use (&$maxDepth): int {
    $maximum = $depth;
    foreach ($blocks as $block) $maximum = max($maximum, $maxDepth($block['innerBlocks'] ?? array(), $depth + 1));
    return $maximum;
};
$svgChain = '<svg viewBox="0 0 10 10" aria-label="Mark"><path d="M0 0h10v10H0z"/></svg>';
for ($depth = 0; $depth < 44; ++$depth) $svgChain = '<div class="depth-' . $depth . '">' . $svgChain . '</div>';
$deepSvg = $transform($svgChain);
$assert('core/image' === ($deepSvg['blocks'][0]['blockName'] ?? null) && $maxDepth($deepSvg['blocks'] ?? array()) <= 20 && 1 === count(array_filter($deepSvg['assets'] ?? array(), static fn (array $asset): bool => 'svg' === ($asset['kind'] ?? null))), 'A depth-44 passive SVG image chain coalesces to one native image without losing its materialized asset.');

$wowChain = '<wow-image class="captured-media"><img src="hero.jpg" alt="Hero"></wow-image>';
for ($depth = 0; $depth < 39; ++$depth) $wowChain = '<div class="depth-' . $depth . '">' . $wowChain . '</div>';
$deepWow = $transform($wowChain);
$assert('core/image' === ($deepWow['blocks'][0]['blockName'] ?? null) && $maxDepth($deepWow['blocks'] ?? array()) <= 20 && str_contains((string) ($deepWow['blocks'][0]['attrs']['className'] ?? ''), 'captured-media') && str_contains((string) ($deepWow['serialized_blocks'] ?? ''), 'src="hero.jpg"'), 'A depth-39 custom media chain coalesces while preserving media selector ownership and image semantics.');

$fullWidth = $transform('<div style="width:100%"><div class="surface"><p>Copy</p></div></div>');
$fullWidthBlock = $fullWidth['blocks'][0] ?? array();
$assert('core/group' === ($fullWidthBlock['blockName'] ?? null) && str_contains((string) ($fullWidthBlock['attrs']['className'] ?? ''), 'surface') && str_contains((string) ($fullWidthBlock['attrs']['className'] ?? ''), 'be-inline-geometry-') && str_contains($engineCss($fullWidth), 'width:100% !important'), 'A full-width transparent normal-flow shell coalesces its generated width carrier onto the surviving group.');

$priceBoxes = $transform('<div class="outer-frame" style="display:block;width:200px;height:40px;position:static"><div class="inner-surface" style="display:block;width:200px;height:40px;position:static"><p>$10.00</p></div></div>');
$assert('core/group' === ($priceBoxes['blocks'][0]['blockName'] ?? null) && 'core/group' === ($priceBoxes['blocks'][0]['innerBlocks'][0]['blockName'] ?? null) && str_contains((string) ($priceBoxes['serialized_blocks'] ?? ''), '$10.00'), 'Descendant price text preserves content without assigning commerce identity to generic ancestor boxes.');

foreach (array(
    '<div style="width:80%"><div class="surface"><p>Copy</p></div></div>',
    '<div style="width:100%;padding:1px"><div class="surface"><p>Copy</p></div></div>',
    '<div style="width:100%"><div class="surface" style="width:50%"><p>Copy</p></div></div>',
    '<div style="width:100%"><img src="hero.jpg" alt="Hero"></div>',
    '<div style="width:100%"><div class="surface" style="display:flex"><p>Copy</p></div></div>',
    '<style>.shell .surface{color:red}</style><div class="shell" style="width:100%"><div class="surface"><p>Copy</p></div></div>',
    '<div id="shell" style="width:100%"><div class="surface"><p>Copy</p></div></div>',
    '<div role="region" style="width:100%"><div class="surface"><p>Copy</p></div></div>',
    '<div data-state="open" style="width:100%"><div class="surface"><p>Copy</p></div></div>',
    '<div onclick="return false" style="width:100%"><div class="surface"><p>Copy</p></div></div>',
    '<style>.shell{background:red}</style><div class="shell" style="width:100%"><div class="surface"><p>Copy</p></div></div>',
    '<div class="slider" style="width:100%"><div class="surface"><p>Copy</p></div></div>',
) as $html) {
    $result = $transform($html);
    $root = $result['blocks'][0] ?? array();
    $assert('core/group' === ($root['blockName'] ?? null) && array() !== ($root['innerBlocks'] ?? array()), 'Non-transparent full-width shells retain their source wrapper.');
}

$structuralLayout = $transform('<table><tr><td><div style="width:100%"><div class="surface"><p>Copy</p></div></div></td></tr></table>');
$assert('core/table' === ($structuralLayout['blocks'][0]['blockName'] ?? null), 'Structural-layout parents retain their full-width child boundary.');

$runtimeTarget = $transform('<div class="shell" style="width:100%"><div class="surface"><p>Copy</p></div></div>', array('runtime_dom_selectors' => array('.shell')));
$assert('core/group' === ($runtimeTarget['blocks'][0]['blockName'] ?? null) && array() !== ($runtimeTarget['blocks'][0]['innerBlocks'] ?? array()), 'Runtime DOM targets retain their full-width shell.');

$selectorOwned = $transform('<style>.image-carrier img{border:1px solid red}</style><div class="image-carrier"><img src="hero.jpg" alt="Hero"></div>');
$assert('core/group' === ($selectorOwned['blocks'][0]['blockName'] ?? null), 'A selector whose descendant relationship would change retains its carrier.');

$slider = $transform('<div class="slider image-carrier"><img src="hero.jpg" alt="Hero"></div>');
$assert('core/group' === ($slider['blocks'][0]['blockName'] ?? null), 'Runtime-shaped slider topology is never flattened around media.');

$customSelectorOwned = $transform('<style>.shell wow-image{border:1px solid red}</style><div class="shell"><wow-image><img src="hero.jpg" alt="Hero"></wow-image></div>');
$assert('core/group' === ($customSelectorOwned['blocks'][0]['blockName'] ?? null), 'A custom media carrier whose ancestor selector relationship would change retains its wrapper.');

fwrite(STDOUT, "Geometry chain coalescing contract passed\n");
