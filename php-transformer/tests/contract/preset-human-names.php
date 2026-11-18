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
h1{font-size:38px}small{font-size:11px}
</style></head><body><main><h1>A human scale</h1>
<p class="sand">A warm surface with enough copy to lead its family.</p>
<p class="near">An exact nearby shade.</p><p>Readable body copy.</p>
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
$palette = $theme['settings']['color']['palette'];
$assert(in_array('Warm Sand', array_column($palette, 'name'), true), 'Prefer the source design-token name.');
$assert(!in_array('#c6b8aa', array_column($palette, 'color'), true), 'Near shade must not clutter the site palette.');
$assert(in_array('#c7baae', array_column($palette, 'color'), true), 'Keep the family representative exact.');
$assert(in_array('Reading Size', array_column($theme['settings']['typography']['fontSizes'], 'name'), true), 'Keep source font-size token names.');
$assert(in_array('Section Gap', array_column($theme['settings']['spacing']['spacingSizes'], 'name'), true), 'Keep source spacing token names.');
foreach (array($palette, $theme['settings']['typography']['fontSizes'], $theme['settings']['spacing']['spacingSizes']) as $presets) {
    foreach ($presets as $preset) {
        $assert(1 !== preg_match('/^(?:Accent[ -]*\d+|Size[ -]*\d+(?:px)?|[\d.]+px|#[0-9a-f]+)$/i', $preset['name']), 'No machine-only preset names.');
    }
}
$markup = $plan['pages'][0]['canonical_block_markup'];
$css = implode("\n", array_column($plan['assets'], 'content'));
$assert(str_contains($markup . $css, '#c6b8aa'), 'Near shade must remain exact local paint, not bind to its representative.');
$sizes = array_column($theme['settings']['typography']['fontSizes'], 'size');
$assert(in_array('38px', $sizes, true) || in_array('2.375rem', $sizes, true) || str_contains($css, '38px'), 'The title size stays exact.');
echo "Human preset names contract passed.\n";
