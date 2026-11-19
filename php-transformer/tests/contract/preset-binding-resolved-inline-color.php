<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

// Both paragraphs share their captured class list, but their inline paint is
// authoritative. Resolving a root variable must not make that evidence weaker
// than the equivalent plain colour (including on a state-guarded class).
foreach (array('', '.copy:hover{color:#fff}', '@media(min-width:700px){.copy{color:#fff!important}}') as $state) {
    $plan = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array(
        'index.html' => '<!doctype html><html><head><style>:root{--ink:9 77% 48%}.copy{font-size:18px}' . $state . '</style></head><body><main><p class="copy" style="color:hsl(var(--ink));background-color:hsl(var(--ink))">Orange copy</p><p class="copy" style="color:#123456">Blue copy</p></main></body></html>',
    )))->toArray()['source_reports']['wordpress_site_plan'];
    $markup = $plan['pages'][0]['canonical_block_markup'];
    if (!preg_match('/<!-- wp:paragraph (\{.*?\}) -->(<p[^>]*>)Orange copy<\/p>/', $markup, $match)) throw new RuntimeException('The paragraph remains native.');
    $attrs = json_decode($match[1], true, 512, JSON_THROW_ON_ERROR);
    foreach ($plan['writes'] as $write) {
        if ('theme.json' !== $write['target_path']) continue;
        $theme = json_decode($write['payload']['data'], true, 512, JSON_THROW_ON_ERROR);
        $palette = array_column($theme['settings']['color']['palette'], 'color', 'slug');
    }
    $token = $attrs['style']['color']['text'] ?? '';
    $slug = str_starts_with($token, 'var:preset|color|') ? substr($token, strlen('var:preset|color|')) : '';
    if (($palette[$slug] ?? null) !== '#d9381c' || isset($attrs['textColor']) || !str_contains($match[2], 'color:var(--wp--preset--color--' . $slug . ')')) throw new RuntimeException('Resolved paint binds independently of shared-class observations without changing its inline cascade slot.');
    if (($attrs['style']['color']['background'] ?? null) !== $token || isset($attrs['backgroundColor']) || !str_contains($match[2], 'background-color:var(--wp--preset--color--' . $slug . ')')) throw new RuntimeException('Resolved background paint also retains its inline cascade slot.');
}
// Consistent class evidence must still use core's ordinary preset attributes,
// including when conversion serialized a custom-property colour inline.
$plan = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array(
    'index.html' => '<!doctype html><html><head><style>:root{--ink:9 77% 48%}.copy{color:hsl(var(--ink));background-color:hsl(var(--ink))}</style></head><body><main><p class="copy" style="color:hsl(var(--ink));background-color:hsl(var(--ink))">Consistent copy</p></main></body></html>',
)))->toArray()['source_reports']['wordpress_site_plan'];
$markup = $plan['pages'][0]['canonical_block_markup'];
if (!preg_match('/<!-- wp:paragraph (\{.*?\}) -->(<p[^>]*>)Consistent copy<\/p>/', $markup, $match)) throw new RuntimeException('Consistent paint remains a native paragraph.');
$attrs = json_decode($match[1], true, 512, JSON_THROW_ON_ERROR);
if (!isset($attrs['textColor'], $attrs['backgroundColor']) || isset($attrs['style']['color'])) throw new RuntimeException('Consistent resolved paint retains ordinary core preset binding.');
echo "Resolved inline colour binding contract passed.\n";
