<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

// The compact control has no class font rule, and is smaller than the
// dominant button element. Binding must not require either kind of proof:
// its own serialized inline declaration already owns the resting size.
$html = '<!doctype html><html><head><style>
:root{--compact-size:12px}
body{font:16px Arial}p{font-size:12px}button{font-size:18px}
.compact:hover{font-size:14px!important}
</style></head><body><main>'
    . str_repeat('<p>Small scale text</p><button>Dominant action</button>', 6)
    . '<button class="compact" style="font-size:12px">Compact action</button>'
    . '<button class="compact" style="font-size:var(--compact-size)">Variable compact action</button>'
    . '</main></body></html>';
$plan = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array('index.html' => $html)))->toArray()['source_reports']['wordpress_site_plan'];
$markup = implode('\n', array_column(array_merge($plan['pages'], $plan['template_parts']), 'canonical_block_markup'));
if (!preg_match('/<(?:a|button)\b([^>]*)>Compact action<\//', $markup, $match)) throw new RuntimeException('The compact button stays native.');
if (!str_contains($match[1], 'font-size:var(--wp--preset--font-size--')) throw new RuntimeException('An exact inline compact size must bind without a class-painted size.');
if (preg_match('/has-(?!custom-font-size\b)\S+-font-size/', $match[1])) throw new RuntimeException('No important font-size class may mask the hover rule.');
if (!preg_match('/<(?:a|button)\b([^>]*)>Variable compact action<\//', $markup, $variableMatch)) throw new RuntimeException('The variable-sized button stays native.');
if (!str_contains($variableMatch[1], 'font-size:var(--wp--preset--font-size--')) throw new RuntimeException('A resolved source variable must bind to the exact inline preset too.');
if (preg_match('/has-(?!custom-font-size\b)\S+-font-size/', $variableMatch[1])) throw new RuntimeException('Variable-sized buttons must also preserve hover priority.');
// Both serialized variables must point to a registered, exact-size token,
// not merely any preset whose name happens to pass the markup assertions.
$theme = array();
foreach ($plan['writes'] as $write) {
    if ('theme.json' === $write['target_path']) $theme = json_decode($write['payload']['data'], true, 512, JSON_THROW_ON_ERROR);
}
$sizes = array_column($theme['settings']['typography']['fontSizes'], 'size', 'slug');
foreach (array($match[1], $variableMatch[1]) as $attributes) {
    if (!preg_match('/font-size:var\(--wp--preset--font-size--([^)]+)\)/', $attributes, $preset)) throw new RuntimeException('Missing inline size reference.');
    if ('12px' !== ($sizes[$preset[1]] ?? null)) throw new RuntimeException('Compact typography must retain exactly 12px.');
}
$css = implode('\n', array_map(static fn(array $write): string => str_ends_with($write['target_path'], '.css') ? $write['payload']['data'] : '', $plan['writes']));
if (!str_contains($css, 'font-size:14px!important')) throw new RuntimeException('The interaction rule keeps its original size and priority.');
echo "Inline compact button font-size presets passed.\n";
