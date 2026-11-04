<?php
declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

$css = '@media screen{/* untouched */.note{color:red}}';
$files = array();
foreach (array('one', 'two', 'three') as $page) {
    $files[] = array('path' => "$page.html", 'metadata' => array('compilation' => array('scope' => 'page', 'id' => "$page.html")), 'content' => '<html><head><style>' . $css . '</style></head><body><main><p class="note">' . $page . '</p></main></body></html>');
}
$artifact = array('entrypoint' => 'one.html', 'files' => $files);
$compiler = new ArtifactCompiler();
$shared = $compiler->prepareShared($artifact);
$pages = $compiler->preparePages($artifact, $shared);
$receipts = $compiler->compilePreparedPages($shared, $pages);
$compiler->compose($shared, array_values($receipts));
$counts = $compiler->cssTokenizationCounts();
if (1 !== ($counts[$css] ?? 0) || array(1) !== array_values(array_unique($counts))) {
    throw new RuntimeException('Every distinct stylesheet must be tokenized exactly once across staged pages.');
}
$other = new ArtifactCompiler();
if (array() !== $other->cssTokenizationCounts()) throw new RuntimeException('CSS parses leaked across compiler instances.');
echo "Staged CSS tokenization contract passed\n";
