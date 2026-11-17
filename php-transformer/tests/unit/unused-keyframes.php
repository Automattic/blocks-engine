<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

$result = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array(
    'index.html' => '<!doctype html><html><head><link rel="stylesheet" href="site.css"></head><body><main><p>Hello</p></main></body></html>',
    'other.html' => '<!doctype html><html><head><link rel="stylesheet" href="other.css"></head><body><p class="moving">Other</p><script>const name = "runtime-motion";</script></body></html>',
    'site.css' => '@layer animation-inventory{@keyframes unused-motion{from{opacity:0}to{opacity:1}}@keyframes shared-motion{from{opacity:.5}to{opacity:1}}@keyframes runtime-motion{to{opacity:.9}}}p{color:teal}',
    'other.css' => '.moving{animation:shared-motion 1s}',
)))->toArray();
$css = implode("\n", array_column(array_filter($result['assets'], static fn(array $asset): bool => 'css' === ($asset['kind'] ?? null)), 'content'));
if (str_contains($css, '@keyframes unused-motion')) throw new RuntimeException('Unreferenced nested keyframes must not ship');
foreach (array('shared-motion', 'runtime-motion') as $name) {
    if (!str_contains($css, '@keyframes ' . $name)) throw new RuntimeException('Cross-route and runtime animation consumers must survive: ' . $name);
}
echo "Unused keyframes are pruned using whole-site consumers\n";
