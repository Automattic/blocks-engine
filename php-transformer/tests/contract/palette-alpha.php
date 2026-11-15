<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};
$artifact = require dirname(__DIR__) . '/fixtures/design-system/palette-alpha.php';
$plan = (new ArtifactCompiler())->compile($artifact)->toArray()['source_reports']['wordpress_site_plan'];
$markup = $plan['pages'][0]['canonical_block_markup'];
$assert(1 === preg_match('/<!-- wp:paragraph (\{.*?\}) -->(<p[^>]*>)Muted body copy on dark\.<\/p>/', $markup, $paragraph), 'The tint remains a native paragraph.');
$attrs = json_decode($paragraph[1], true, 512, JSON_THROW_ON_ERROR);
$assert('base-alpha-70' === ($attrs['textColor'] ?? null), 'The paragraph owns a named preset tint, not Default.');
$assert(str_contains($paragraph[2], 'has-text-color') && str_contains($paragraph[2], 'has-base-alpha-70-color') && !str_contains($paragraph[2], 'style='), 'Saved markup uses core preset classes, without an inline color-mix.');
foreach ($plan['writes'] as $write) {
    if ('theme.json' !== $write['target_path']) continue;
    $theme = json_decode($write['payload']['data'], true, 512, JSON_THROW_ON_ERROR);
}
$palette = array_column($theme['settings']['color']['palette'], 'color', 'slug');
$assert('#f0f3f5' === $palette['base'], 'The opaque source color is preserved.');
$assert('color-mix(in srgb, var(--wp--preset--color--base) 70%, transparent)' === ($palette['base-alpha-70'] ?? null), 'The tint preserves opacity and follows the base palette variable, rather than copying its literal color.');
$assert(0 === $plan['theme']['design_system']['metrics']['refusal_reasons']['alpha'], 'Palette tints have no alpha refusals.');
$extended = $artifact;
$extended['files']['index.html'] = str_replace('</section>', '<div class="tint-panel"><p style="color:hsl(var(--bg)/.5)">Inline tint</p></div></section>', $extended['files']['index.html']);
$extended['files']['index.html'] = str_replace('</style>', '.tint-panel{background:hsl(var(--bg)/.2);padding:8px}</style>', $extended['files']['index.html']);
$extendedPlan = (new ArtifactCompiler())->compile($extended)->toArray()['source_reports']['wordpress_site_plan'];
$extendedMarkup = $extendedPlan['pages'][0]['canonical_block_markup'];
$assert(str_contains($extendedMarkup, '"backgroundColor":"base-alpha-20"') && str_contains($extendedMarkup, 'has-base-alpha-20-background-color'), 'A class-driven background tint uses native background support.');
$assert(str_contains($extendedMarkup, '"textColor":"base-alpha-50"') && !str_contains($extendedMarkup, 'style="color:'), 'A literal inline tint also binds through the existing preset primitive.');
$assert(0 === $extendedPlan['theme']['design_system']['metrics']['refusal_reasons']['alpha'], 'Text and background palette tints leave no alpha refusals.');
// Never bypass the existing state, important, or breakpoint safety gates.
foreach (array( '.text-bg-70:hover{color:red}' => 'state_variant', '.text-bg-70{color:hsl(var(--bg)/.7)!important}' => 'important', '@media(max-width:600px){.text-bg-70{color:red}}' => 'breakpoint_variant') as $css => $reason) {
    $unsafe = $artifact;
    $unsafe['files']['index.html'] = str_replace('</style>', $css . '</style>', $unsafe['files']['index.html']);
    $result = (new ArtifactCompiler())->compile($unsafe)->toArray()['source_reports']['wordpress_site_plan'];
    $assert($result['theme']['design_system']['metrics']['refusal_reasons'][$reason] > 0, 'Safety refusal retained: ' . $reason);
    $assert(!str_contains($result['pages'][0]['canonical_block_markup'], '"textColor":"base-alpha-70"'), 'Unsafe tint is not assigned to block color support.');
}
// A translucent-only surface must not need an opaque sibling to be editable.
$standalone = $artifact;
$standalone['files']['index.html'] = str_replace('</section>', '<div class="standalone-alpha"><p>Translucent-only surface</p></div></section>', $standalone['files']['index.html']);
$standalone['files']['index.html'] = str_replace('</style>', '.standalone-alpha{background:rgba(47,23,15,.9);padding:8px}</style>', $standalone['files']['index.html']);
$standalonePlan = (new ArtifactCompiler())->compile($standalone)->toArray()['source_reports']['wordpress_site_plan'];
$assert(str_contains($standalonePlan['pages'][0]['canonical_block_markup'], '"backgroundColor":"alpha-cpbhapog"'), 'A translucent-only surface binds to an exact preset through the public compiler.');
$assert(str_contains($standalonePlan['pages'][0]['canonical_block_markup'], 'has-alpha-cpbhapog-background-color'), 'The alpha preset class uses the same stable slug that Gutenberg save() generates.');
foreach ($standalonePlan['writes'] as $write) {
    if ('theme.json' !== $write['target_path']) continue;
    $standaloneTheme = json_decode($write['payload']['data'], true, 512, JSON_THROW_ON_ERROR);
}
$standalonePalette = array_column($standaloneTheme['settings']['color']['palette'], 'color', 'slug');
$assert('rgba(47,23,15,.9)' === ($standalonePalette['alpha-cpbhapog'] ?? null), 'The preset retains the authored alpha rather than rounding its paint.');
// Registering a standalone alpha preset must not freeze a class's state,
// or responsive paint into native block support.
foreach (array(
    '.standalone-alpha:hover{background:red}' => 'state_variant',
    '@media(max-width:600px){.standalone-alpha{background:red}}' => 'breakpoint_variant',
) as $css => $reason) {
    $unsafe = $standalone;
    $unsafe['files']['index.html'] = str_replace('</style>', $css . '</style>', $unsafe['files']['index.html']);
    $unsafePlan = (new ArtifactCompiler())->compile($unsafe)->toArray()['source_reports']['wordpress_site_plan'];
    $assert($unsafePlan['theme']['design_system']['metrics']['refusal_reasons'][$reason] > 0, 'Standalone alpha safety refusal retained: ' . $reason);
    $assert(!str_contains($unsafePlan['pages'][0]['canonical_block_markup'], '"backgroundColor":"alpha-cpbhapog"'), 'Unsafe standalone alpha remains on the source stylesheet: ' . $reason);
}
// Adding an opaque sibling must not rebind it to the standalone alpha token.
$siblings = $standalone;
$siblings['files']['index.html'] = str_replace('</section>', '<div class="opaque-sibling"><p>Opaque sibling</p></div><div class="transparent-sibling"><p>Transparent sibling</p></div></section>', $siblings['files']['index.html']);
$siblings['files']['index.html'] = str_replace('</style>', '.opaque-sibling{background:rgb(47,23,15)}.transparent-sibling{background:transparent}</style>', $siblings['files']['index.html']);
$siblingPlan = (new ArtifactCompiler())->compile($siblings)->toArray()['source_reports']['wordpress_site_plan'];
foreach ($siblingPlan['writes'] as $write) {
    if ('theme.json' !== $write['target_path']) continue;
    $siblingTheme = json_decode($write['payload']['data'], true, 512, JSON_THROW_ON_ERROR);
}
$siblingPalette = array_column($siblingTheme['settings']['color']['palette'], 'color', 'slug');
$assert(in_array('#2f170f', $siblingPalette, true), 'The opaque sibling keeps its own opaque palette paint.');
$assert(!in_array('transparent', $siblingPalette, true), 'Fully transparent paint does not create a standalone alpha preset.');
$assert(!str_contains($siblingPlan['pages'][0]['canonical_block_markup'], '"backgroundColor":"alpha-cpbhapog"'), 'With an opaque base, the translucent surface uses a derived tint instead of the standalone token.');
echo "Palette alpha contract passed.\n";
