<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

$html = <<<'HTML'
<!doctype html><html><head><style>
:root{--warm-sand:#c7baae;--reading-size:16px;--section-gap:17px}
body{background:white;color:#202020;font-size:var(--reading-size)}
main{padding:var(--section-gap)}
.sand{background:var(--warm-sand);padding:18px}
.near{background:#c6b8aa;padding:15px}
.b1{background:#71675f;color:#fff}.b2{background:#65564b;color:#fff}.b3{background:#9c8569}.b4{background:#9d8b7b}
.s1{background:#ab9a88}.s2{background:#ddd4ca}.s3{background:#eee1d5}
h1{font-size:38px}small{font-size:11px}
</style></head><body><main><h1>A human scale</h1>
<p class="sand">A warm surface with enough copy to lead its family.</p>
<p class="near">An exact nearby shade.</p><p>Readable body copy.</p>
<section><p class="b1">Brown one, a near-hue brown surface with copy.</p>
<p class="b2">Brown two, a darker near-hue brown surface with copy.</p>
<p class="b3">Brown three, a warmer mid brown surface with copy.</p>
<p class="b4">Brown four, a lighter taupe brown surface with copy.</p>
<p class="s1">Sand one, a deeper sand surface with copy.</p>
<p class="s2">Sand two, a pale sand surface with copy.</p>
<p class="s3">Sand three, the palest sand surface with copy.</p></section>
<small>Fine print</small></main></body></html>
HTML;
$plan = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array('index.html' => $html)))->toArray()['source_reports']['wordpress_site_plan'];
foreach ($plan['writes'] as $write) {
    if ('theme.json' === $write['target_path']) $theme = json_decode($write['payload']['data'], true, 512, JSON_THROW_ON_ERROR);
}
$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};
$metrics = $plan['theme']['design_system']['metrics'];
$assert(0 === ($metrics['unnamed_preset_count'] ?? null), 'Site plan must report unnamed_preset_count = 0.');
$assert(0 === ($metrics['duplicate_preset_name_count'] ?? null), 'Site plan must report duplicate_preset_name_count = 0.');
$palette = $theme['settings']['color']['palette'];
foreach (array('#71675f', '#65564b', '#9c8569', '#9d8b7b', '#ab9a88', '#ddd4ca', '#eee1d5') as $brown) {
    $assert(in_array($brown, array_column($palette, 'color'), true), "Fixture brown {$brown} must be a distinct palette entry.");
}
$assert(in_array('Warm Sand', array_column($palette, 'name'), true), 'Prefer the source design-token name.');
$assert(!in_array('#c6b8aa', array_column($palette, 'color'), true), 'Near shade must not clutter the site palette.');
$assert(in_array('#c7baae', array_column($palette, 'color'), true), 'Keep the family representative exact.');
$assert(in_array('Reading Size', array_column($theme['settings']['typography']['fontSizes'], 'name'), true), 'Keep source font-size token names.');
$assert(in_array('Section Gap', array_column($theme['settings']['spacing']['spacingSizes'], 'name'), true), 'Keep source spacing token names.');
foreach (array('color' => $palette, 'font size' => $theme['settings']['typography']['fontSizes'], 'spacing' => $theme['settings']['spacing']['spacingSizes']) as $category => $presets) {
    $names = array_map('strtolower', array_column($presets, 'name'));
    $repeated = array_keys(array_filter(array_count_values($names), static fn(int $count): bool => $count > 1));
    $assert(array() === $repeated, "Every {$category} preset needs a distinct display name; repeated: " . implode(', ', $repeated) . '.');
    $assert(count(array_unique(array_column($presets, 'slug'))) === count($presets), "Every {$category} preset keeps a distinct slug.");
    foreach ($presets as $preset) {
        $assert(1 !== preg_match('/^(?:Accent[ -]*\d+|Size[ -]*\d+(?:px)?|[\d.]+px|#[0-9a-f]+)$/i', $preset['name']), 'No machine-only preset names.');
        $assert(1 !== preg_match('/\s\d+$|\(#?[0-9a-f]{6}\)$/i', $preset['name']), "Distinguish {$preset['name']} by a property, not an appended number or value.");
    }
}
$markup = $plan['pages'][0]['canonical_block_markup'];
$css = implode("\n", array_column($plan['assets'], 'content'));
$assert(str_contains($markup . $css, '#c6b8aa'), 'Near shade must remain exact local paint, not bind to its representative.');
$sizes = array_column($theme['settings']['typography']['fontSizes'], 'size');
$assert(in_array('38px', $sizes, true) || in_array('2.375rem', $sizes, true) || str_contains($css, '38px'), 'The title size stays exact.');
printf("unnamed_preset_count=%d duplicate_preset_name_count=%d\n", $metrics['unnamed_preset_count'], $metrics['duplicate_preset_name_count']);
echo "Human preset names contract passed.\n";
