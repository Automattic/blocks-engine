<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

// A small, rarely painted shade must remain editable even when twelve heavier
// colours already fill the palette. Nearby tokens cannot substitute for it.
$colors = array();
$html = '<!doctype html><html><head><style>.stateful{color:#bb1234}.stateful:hover{color:#1234bb}</style></head><body><main>';
for ($i = 0; $i < 20; ++$i) {
    $color = sprintf('#%02x%02x%02x', 20 + $i * 11, 200 - $i * 9, ($i * 37) % 256);
    $colors[] = $color;
    $html .= '<p class="shade-' . $i . '" style="color:' . $color . '">' . str_repeat('Painted copy ', 20 - $i) . '</p>';
}
$html .= '<p class="stateful" style="color:#bb1234">Stateful paint</p></main></body></html>';
$rules = '';
foreach ($colors as $index => $color) $rules .= '.shade-' . $index . '{color:' . $color . '}';
$html = str_replace('</style>', $rules . '</style>', $html);
$plan = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array('index.html' => $html)))->toArray()['source_reports']['wordpress_site_plan'];
foreach ($plan['writes'] as $write) {
    if ('theme.json' !== $write['target_path']) continue;
    $theme = json_decode($write['payload']['data'], true, 512, JSON_THROW_ON_ERROR);
}
$palette = array_column($theme['settings']['color']['palette'], 'color', 'slug');
preg_match_all('/<!-- wp:paragraph (\{.*?\}) -->/', $plan['pages'][0]['canonical_block_markup'], $paragraphs);
if (count($paragraphs[1]) !== count($colors) + 1) throw new RuntimeException('Every source paragraph remains native.');
foreach (array_slice($paragraphs[1], 0, count($colors)) as $index => $json) {
    $attrs = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
    if (($palette[$attrs['textColor'] ?? ''] ?? null) !== $colors[$index] || isset($attrs['style']['color']['text'])) {
        throw new RuntimeException('Every painted colour binds to its own exact editable preset, including low-weight shades beyond twelve.');
    }
}
$stateful = json_decode(end($paragraphs[1]), true, 512, JSON_THROW_ON_ERROR);
if (isset($stateful['textColor'])) throw new RuntimeException('Adding exact palette entries must not introduce an important class that freezes hover paint.');
$css = implode("\n", array_column($plan['assets'], 'content'));
if (!str_contains($css, ':hover') || !str_contains($css, '#1234bb')) throw new RuntimeException('Authored hover paint remains reachable in the stylesheet.');
// Registering a low-weight block value must not rewrite unrelated utility
// consumers or promote that value into site-wide element defaults.
if (!preg_match('/\.shade-19[^{}]*\{color:' . preg_quote(end($colors), '/') . '\}/', $css)) {
    throw new RuntimeException('Additional exact block presets leave the long-tail stylesheet rules intact.');
}
echo "Painted palette cap regression passed.\n";
