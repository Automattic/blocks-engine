<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

$result = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array(
    'index.html' => '<!doctype html><html><head><style>.sample:hover{opacity:0.73}.sample:hover{cursor:crosshair}.sample:focus{opacity:0.41}.other:focus{opacity:0.52}.sample:focus{opacity:0.63}</style></head><body><main><p class="sample">Hello</p><p class="other">Other</p></main></body></html>',
)))->toArray();
$css = '';
foreach ($result['source_reports']['wordpress_site_plan']['assets'] as $asset) {
    if ('css' === ($asset['kind'] ?? null) && 'inline-style' === ($asset['source'] ?? null) && is_string($asset['content'] ?? null)) $css .= $asset['content'] . "\n";
}
if (!preg_match('/\{[^{}]*opacity:0\.73;cursor:crosshair[^{}]*\}/', $css)) {
    throw new RuntimeException('Independent inline rules with identical projected selectors must share one selector list: ' . $css);
}
// A conflicting declaration between repeated selectors must not move across it.
if (!preg_match('/opacity:0\.41.*opacity:0\.52.*opacity:0\.63/s', $css)) {
    throw new RuntimeException('Intervening conflicting cascade declarations must keep their order: ' . $css);
}
echo "Inline stylesheet delivery folds independent repeated selectors without moving conflicts\n";
