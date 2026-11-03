<?php
declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

$method = new ReflectionMethod(ArtifactCompiler::class, 'applyAuthorStylesheetProjections');
$counter = new ReflectionProperty(ArtifactCompiler::class, 'authorStylesheetProjectionCount');
$base = '@namespace svg url(http://www.w3.org/2000/svg);.note{color:red}';
$file = array('path' => 'inline/shared.css', 'kind' => 'css', 'content' => $base);
foreach (array(3, 6) as $count) {
    $projections = array();
    for ($i = 0; $i < $count; ++$i) $projections[] = array('path' => $file['path'], 'owner' => "page-$i", 'content' => $base . '.note{color:blue}');
    $compiler = new ArtifactCompiler();
    $diagnostics = array();
    $args = array(array($file), $projections, array(array('path' => $file['path'], 'content' => $base)), '', array(), &$diagnostics);
    $expected = $method->invokeArgs($compiler, $args);
    $args[0] = array_fill(0, $count, $file);
    $actual = $method->invokeArgs($compiler, $args);
    if ($actual !== $expected || 1 !== $counter->getValue($compiler)) throw new RuntimeException('Repeated paths repeated projection work.');
    if ($count + 1 !== count($actual)) throw new RuntimeException('Expected one shared sheet and one delta per owner.');
    echo "$count routes: " . count($actual) . ' projection assets, ' . strlen(json_encode($actual, JSON_THROW_ON_ERROR)) . " bytes\n";
}
