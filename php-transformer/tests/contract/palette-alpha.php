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
// Never approximate an unmatched RGB value or bypass the existing state,
// important, or breakpoint safety gates while adding a tint preset.
foreach (array('.text-bg-70{color:rgb(255 0 0 / .7)}' => 'alpha', '.text-bg-70:hover{color:red}' => 'state_variant', '.text-bg-70{color:hsl(var(--bg)/.7)!important}' => 'important', '@media(max-width:600px){.text-bg-70{color:red}}' => 'breakpoint_variant') as $css => $reason) {
    $unsafe = $artifact;
    $unsafe['files']['index.html'] = str_replace('</style>', $css . '</style>', $unsafe['files']['index.html']);
    $result = (new ArtifactCompiler())->compile($unsafe)->toArray()['source_reports']['wordpress_site_plan'];
    $assert($result['theme']['design_system']['metrics']['refusal_reasons'][$reason] > 0, 'Safety refusal retained: ' . $reason);
    $assert(!str_contains($result['pages'][0]['canonical_block_markup'], '"textColor":"base-alpha-70"'), 'Unsafe tint is not assigned to block color support.');
}
echo "Palette alpha contract passed: refusal_reasons.alpha=0; textColor=base-alpha-70.\n";
