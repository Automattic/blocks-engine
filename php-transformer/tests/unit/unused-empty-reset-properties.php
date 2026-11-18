<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

// Empty custom properties are valid reset values, but without a reader they
// affect no rendering. Keep read empty values: var() must not take its fallback.
$result = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array(
    'index.html' => '<!doctype html><html><head><link rel="stylesheet" href="site.css"></head><body><main><p class="paint">Hello</p></main></body></html>',
    'site.css' => '*,:before,:after{--unused-empty: ;--used-empty: ;--wp--editor-empty: }.paint{color:var(--used-empty,red);--component-empty: }',
)))->toArray();
$css = implode("\n", array_column(array_filter($result['assets'], static fn(array $asset): bool => 'css' === ($asset['kind'] ?? null)), 'content'));
if (str_contains($css, '--unused-empty')) throw new RuntimeException('Unread empty reset values must not ship');
foreach (array('--used-empty:', 'var(--used-empty,red)', '--wp--editor-empty:', '--component-empty:') as $kept) {
    if (!str_contains($css, $kept)) throw new RuntimeException('Lost protected custom property: ' . $kept);
}
echo "Unused empty reset properties passed\n";
