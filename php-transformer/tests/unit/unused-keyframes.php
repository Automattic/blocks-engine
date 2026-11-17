<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

$result = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array(
    'index.html' => '<!doctype html><html><head><link rel="stylesheet" href="site.css"><link rel="stylesheet" href="other.css"></head><body><main><p class="present">Hello</p></main></body></html>',
    'site.css' => '.absent{animation:orphan-motion 1s}@keyframes orphan-motion{to{opacity:.25}}@media(min-width:600px){@keyframes shared-motion{to{opacity:.75}}}@keyframes inline-motion{to{opacity:.9}}',
    'other.css' => '.present:hover{animation:shared-motion 1s}',
)))->toArray();
$css = implode("\n", array_column(array_filter($result['assets'], static fn(array $asset): bool => 'css' === ($asset['kind'] ?? null)), 'content'));
if (str_contains($css, '@keyframes orphan-motion') || str_contains($css, '@keyframes inline-motion')) throw new RuntimeException('Unreferenced keyframes must not ship after selector pruning');
if (!str_contains($css, '@keyframes shared-motion')) throw new RuntimeException('Cross-sheet state animation must survive');
echo "Unused keyframes are pruned across published stylesheets\n";
