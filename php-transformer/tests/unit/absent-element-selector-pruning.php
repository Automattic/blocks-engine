<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

// Exercise the public compiler, including responsive and interaction variants.
$result = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array(
    'index.html' => '<!doctype html><html><head><link rel="stylesheet" href="site.css"></head><body><main><p>Hello</p></main></body></html>',
    'site.css' => 'fieldset{outline-color:salmon}@media(min-width:700px){fieldset:hover{outline-color:orchid}p:hover{color:teal}}p{color:navy}',
)))->toArray();
$css = implode("\n", array_column(array_filter($result['assets'], static fn(array $asset): bool => 'css' === ($asset['kind'] ?? '')), 'content'));
foreach (array('salmon' => false, 'orchid' => false, 'teal' => true, 'navy' => true) as $value => $expected) {
    if (str_contains($css, $value) !== $expected) throw new RuntimeException('Unexpected presence of ' . $value);
}
echo "Absent element selector pruning passed\n";
