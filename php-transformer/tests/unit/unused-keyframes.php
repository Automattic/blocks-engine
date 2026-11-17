<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

$artifact = array('entrypoint' => 'index.html', 'files' => array(
    'index.html' => '<!doctype html><html><head><link rel="stylesheet" href="site.css"><link rel="stylesheet" href="other.css"></head><body><main><p class="live">Hello</p></main></body></html>',
    'site.css' => '@keyframes unused-motion{from{opacity:.123}to{opacity:.456}}@media(min-width:600px){@keyframes cross-sheet-motion{from{opacity:.234}to{opacity:.567}}@-webkit-keyframes unused-prefixed-motion{from{opacity:.321}to{opacity:.654}}@keyframes state-motion{from{opacity:.345}to{opacity:.678}}}.absent{animation:unused-motion 1s}',
    'other.css' => '.live{animation:cross-sheet-motion 1s}@media(min-width:800px){.live:hover{animation:state-motion 1s}}',
));
$result = (new ArtifactCompiler())->compile($artifact)->toArray();
$css = implode("\n", array_column(array_filter($result['assets'], static fn(array $asset): bool => 'css' === ($asset['kind'] ?? '')), 'content'));
if (str_contains($css, '@keyframes unused-motion')) throw new RuntimeException('Unreferenced animation inventory must not ship');
if (!str_contains($css, '@keyframes cross-sheet-motion')) throw new RuntimeException('Cross-sheet animation references must remain');
if (str_contains($css, '@-webkit-keyframes unused-prefixed-motion')) throw new RuntimeException('Nested prefixed animation inventory must not ship');
if (!str_contains($css, '@keyframes state-motion')) throw new RuntimeException('Responsive state animation references must remain');
echo "Unused keyframes are pruned across published stylesheets\n";
