<?php
declare(strict_types=1);

// A near shade the consolidated palette omits (ΔE*ab ≤
// COLOR_CONSOLIDATION_DISTANCE) follows its family representative through a
// stylesheet relative colour, so a Global Styles palette change restyles it
// with its family (#6754, restoring #3043), while the default render stays
// pixel-exact: the derivation reproduces the source value at the default
// palette, after a literal fallback. The palette itself stays consolidated:
// `base` #ffffff and `contrast` #111111 are its only independent colours; a
// near shade a block paints as text adds a token whose value is that same
// derivation, so the block owns its text colour without a new colour.

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
$note = 'rgb(from var(--wp--preset--color--contrast) calc(r + 4) calc(g + 4) calc(b + 4))';
$assert(array('base' => '#ffffff', 'contrast' => '#111111', 'contrast-shade-bfbfbf' => $note) === $palette, 'The palette stays consolidated: near shades re-enter it only as derivations of their representative (' . json_encode($palette) . ').');
$assert((CssColor::distance('#f7f7f4', '#ffffff') ?? INF) <= DesignSystem::COLOR_CONSOLIDATION_DISTANCE && (CssColor::distance('#151515', '#111111') ?? INF) <= DesignSystem::COLOR_CONSOLIDATION_DISTANCE, 'The fixture shades are near shades of base and contrast.');
$assert(array('#151515' => '#111111', '#f7f7f4' => '#ffffff') === ($plan['theme']['design_system']['near_shades'] ?? null), 'The report names each omitted shade and its representative.');

// Each rule keeps its literal, and a feature-queried copy right after it
// derives the shade from the representative preset.
$supports = '@supports (color:rgb(from red r g b))';
$paper = 'rgb(from var(--wp--preset--color--base) calc(r - 8) calc(g - 8) calc(b - 11))';
$assert(str_contains($css, ':root{--paper:#f7f7f4}' . $supports . '{:root{--paper:' . $paper . '}}'), 'The --paper custom property keeps its literal and derives from base (' . $css . ').');
$assert(str_contains($css, '.band{padding:40px;background:var(--paper)}'), 'The .band rule reads --paper unchanged, so it follows the derivation (' . $css . ').');
$assert(str_contains($css, '.note{color:#151515}' . $supports . '{.note{color:' . $note . '}}'), 'The .note colour keeps its literal and derives from contrast (' . $css . ').');

// What .band and .note paint: evaluate the derivations against a palette.
$paint = static function (string $derivation, array $palette): string {
    preg_match('/^rgb\(from var\(--wp--preset--color--([a-z0-9-]+)\) (.*)\)$/', $derivation, $match);
    $base = CssColor::parse($palette[$match[1]]);
    preg_match_all('/([rgb])|calc\(([rgb]) ([+-]) (\d+)\)/', $match[2], $parts, PREG_SET_ORDER);
    $rgb = array();
    foreach ($parts as $i => $part) $rgb[] = max(0, min(255, (int) round($base[$i]) + ('' === ($part[3] ?? '') ? 0 : ('+' === $part[3] ? 1 : -1) * (int) $part[4])));
    return vsprintf('#%02x%02x%02x', $rgb);
};
$assert('#f7f7f4' === $paint($paper, $palette) && '#151515' === $paint($note, $palette), 'At the default palette .band and .note paint their exact source colours.');
$restyled = array('base' => '#1d2433', 'contrast' => '#f2f2f2');
$assert('#151c28' === $paint($paper, $restyled) && '#f6f6f6' === $paint($note, $restyled), 'Overriding base and contrast repaints .band and .note with their family.');

// Blocks carry no derivation (safecss strips it from inline styles) and no
// representative preset (it would repaint the shade). The .note text names
// its derived near-shade token; the .band background stays with its rule.
$markup = $plan['pages'][0]['canonical_block_markup'];
preg_match_all('/<!-- wp:(group|paragraph) (\{[^\n]*?\}) -->/', $markup, $blocks, PREG_SET_ORDER);
$painted = array();
foreach ($blocks as [, $name, $json]) {
    $attributes = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
    if (!in_array($attributes['className'] ?? null, array('band', 'note'), true)) continue;
    $painted[] = $attributes['className'] . ':' . json_encode(array_intersect_key($attributes, array_flip(array('backgroundColor', 'textColor'))) + array_intersect_key($attributes['style'] ?? array(), array('color' => true)));
}
$assert(array('band:[]', 'note:{"textColor":"contrast-shade-bfbfbf"}', 'band:[]', 'note:{"textColor":"contrast-shade-bfbfbf"}') === $painted, 'Only the .note text binds, to its derived near-shade token (' . json_encode($painted) . ').');
$assert(!str_contains($markup, 'rgb(from'), 'No block markup carries a relative colour.');

echo "Near shade family binding contract passed.\n";
