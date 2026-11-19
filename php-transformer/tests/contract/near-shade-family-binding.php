<?php
declare(strict_types=1);

// A near shade the consolidated palette omits (ΔE*ab ≤
// COLOR_CONSOLIDATION_DISTANCE) binds to its family representative through
// the one colour binding rule, so a Global Styles palette change restyles it
// with its family (#6754, restoring #3043). The palette itself stays
// consolidated: `base` #ffffff and `contrast` #111111 only.

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\Css\CssColor;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\DesignSystem;

$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};

$html = <<<'HTML'
<style>
:root{--paper:#f7f7f4}
body{margin:0;background:#fff;color:#111;font-family:sans-serif}
.band{padding:40px;background:var(--paper)}
.card{padding:24px;background:#fff;border:1px solid #111}
.note{color:#151515}
</style>
<main>
<section class="band"><h1>Paper band</h1><p class="note">Near-black note text.</p></section>
<section><div class="card"><h2>White card</h2><p>Body copy in the main text colour.</p></div></section>
<section class="band"><h2>Second band</h2><p class="note">More near-black note text.</p></section>
</main>
HTML;

$plan = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array('index.html' => $html)))->toArray()['source_reports']['wordpress_site_plan'];
$theme = array();
$css = '';
foreach ($plan['writes'] as $write) {
    if ('theme.json' === $write['target_path']) $theme = json_decode($write['payload']['data'], true, 512, JSON_THROW_ON_ERROR);
    if (str_ends_with($write['target_path'], '.css')) $css .= $write['payload']['data'];
}
$palette = array_column($theme['settings']['color']['palette'] ?? array(), 'color', 'slug');
$assert(array('base' => '#ffffff', 'contrast' => '#111111') === $palette, 'The palette stays consolidated: near shades do not re-enter it (' . json_encode($palette) . ').');
$assert((CssColor::distance('#f7f7f4', '#ffffff') ?? INF) <= DesignSystem::COLOR_CONSOLIDATION_DISTANCE && (CssColor::distance('#151515', '#111111') ?? INF) <= DesignSystem::COLOR_CONSOLIDATION_DISTANCE, 'The fixture shades are near shades of base and contrast.');

// Stylesheet and custom-property paths: the source value stays the fallback.
$assert(str_contains($css, ':root{--paper:var(--wp--preset--color--base,#f7f7f4)}'), 'The --paper custom property binds to base (' . $css . ').');
$assert(str_contains($css, '.band{padding:40px;background:var(--wp--preset--color--base,var(--paper))}'), 'The .band background binds to base through --paper (' . $css . ').');
$assert(str_contains($css, '.note{color:var(--wp--preset--color--contrast,#151515)}'), 'The .note colour binds to contrast (' . $css . ').');

// Block attribute path.
$markup = $plan['pages'][0]['canonical_block_markup'];
preg_match_all('/<!-- wp:(group|paragraph) (\{[^\n]*?\}) -->/', $markup, $blocks, PREG_SET_ORDER);
$bands = $notes = array();
foreach ($blocks as [, $name, $json]) {
    $attributes = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
    $class = $attributes['className'] ?? null;
    if ('group' === $name && 'band' === $class) $bands[] = ($attributes['backgroundColor'] ?? '') . '|' . json_encode($attributes['style']['color'] ?? null);
    if ('paragraph' === $name && 'note' === $class) $notes[] = ($attributes['textColor'] ?? '') . '|' . json_encode($attributes['style']['color'] ?? null);
}
$assert(array('base|null', 'base|null') === $bands, 'Each .band block binds its background to base with no literal (' . json_encode($bands) . ').');
$assert(array('contrast|null', 'contrast|null') === $notes, 'Each .note block binds its text to contrast with no literal (' . json_encode($notes) . ').');

// Before the fix the two bands and two notes stayed literal: 0.8.
$coverage = $plan['theme']['design_system']['metrics']['restyle_coverage'] ?? null;
$assert(is_numeric($coverage) && $coverage > 0.8 && 1.0 === (float) $coverage, 'restyle_coverage rises to 1 once near shades follow the palette (' . json_encode($coverage) . ').');
$assert(array('#151515' => '#111111', '#f7f7f4' => '#ffffff') === ($plan['theme']['design_system']['near_shades'] ?? null), 'The report names each omitted shade and the representative it binds to.');

echo "Near shade family binding contract passed.\n";
