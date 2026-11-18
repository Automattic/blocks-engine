<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

$result = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array(
    'index.html' => '<!doctype html><html><head><link rel="stylesheet" href="site.css"></head><body><main><p data-tone="quiet">Hello</p></main></body></html>',
    'other.html' => '<!doctype html><html><body><p data-other="on">Other route</p></body></html>',
    'runtime.js' => 'document.querySelector("p").setAttribute("data-runtime", "on"); document.querySelector("p").dataset.runtimeTone = "on";',
    'site.css' => '[data-absent]{outline-color:salmon}@media(min-width:700px){[data-absent]:hover{outline-color:orchid}[data-tone]:hover{color:teal}}[data-other]{color:navy}[data-runtime]{color:olive}[data-runtime-tone]{color:chocolate}p:not([data-absent]){color:maroon}:is([data-absent],[data-tone]){color:indigo}',
)))->toArray();
$css = implode("\n", array_column(array_filter($result['assets'], static fn(array $asset): bool => 'css' === ($asset['kind'] ?? '')), 'content'));
foreach (array('salmon' => false, 'orchid' => false, 'teal' => true, 'navy' => true, 'olive' => true, 'chocolate' => true, 'maroon' => true, 'indigo' => true) as $value => $expected) {
    if (str_contains($css, $value) !== $expected) throw new RuntimeException('Unexpected presence of ' . $value);
}
echo "Absent attribute selector pruning passed\n";
