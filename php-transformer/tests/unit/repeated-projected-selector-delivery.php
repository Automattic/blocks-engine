<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

// Two source rules for one control project onto the same long carrier
// selector list; that list ships once. Author-written repeats stay intact,
// and a competing declaration between repeats keeps its cascade position.
$result = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array(
    'index.html' => '<!doctype html><html><head><style>.btn:hover{opacity:0.5}.btn:hover{cursor:crosshair}.btn:focus{opacity:0.41}.other:focus{opacity:0.52}.btn:focus{opacity:0.63}.shade{padding:2px}.shade{background-image:linear-gradient(red,blue)}</style></head><body><main><p class="shade">Hello</p><p class="other">Other</p><button class="btn">Go</button></main></body></html>',
)))->toArray();
$css = '';
foreach ($result['source_reports']['wordpress_site_plan']['assets'] as $asset) {
    if ('css' === ($asset['kind'] ?? null) && 'inline-style' === ($asset['source'] ?? null) && is_string($asset['content'] ?? null)) $css .= $asset['content'] . "\n";
}
if (!preg_match('/blocks-engine-[^{}]*:hover[^{}]*\{opacity:0\.5;cursor:crosshair\}/', $css)) {
    throw new RuntimeException('Repeated projected selector lists must ship once: ' . $css);
}
if (!preg_match('/opacity:0\.41.*opacity:0\.52.*opacity:0\.63/s', $css)) {
    throw new RuntimeException('Intervening conflicting cascade declarations must keep their order: ' . $css);
}
if (!str_contains($css, '.shade{padding:2px}') || !str_contains($css, '.shade{background-image:linear-gradient(red,blue)}')) {
    throw new RuntimeException('Author-written repeated selectors stay as authored: ' . $css);
}
echo "Projected selector lists ship once without moving conflicts or author rules\n";
