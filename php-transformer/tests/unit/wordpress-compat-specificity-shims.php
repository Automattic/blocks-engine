<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\WordPressCompatCss;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\AuthorStyleAnalysis;

$cases = array(
    array(array(), ''),
    array(array(array('kind' => 'html', 'content' => 'blocks-engine-specificity-site-0')), ''),
    array(array(), '/* blocks-engine-specificity-site-0 */'),
);
$expected = array(
    array('blocks-engine-specificity-site-0', 'blocks-engine-specificity-class-site-0', 'blocks-engine-specificity-id-site-0'),
    array('blocks-engine-specificity-site-1', 'blocks-engine-specificity-class-site-0', 'blocks-engine-specificity-id-site-0'),
    array('blocks-engine-specificity-site-1', 'blocks-engine-specificity-class-site-0', 'blocks-engine-specificity-id-site-0'),
);
foreach ($cases as $index => [$files, $css]) {
    $avoid = array($css);
    foreach ($files as $file) if ('html' === ($file['kind'] ?? '')) $avoid[] = $file['content'];
    $actual = array_map(static fn(string $kind): string => AuthorStyleAnalysis::allocateSiteMarker($kind, $avoid), array('specificity', 'specificity-class', 'specificity-id'));
    if ($actual !== $expected[$index]) throw new RuntimeException('Site shim collision allocation changed.');
    (new WordPressCompatCss())->projectNavigationStylesheet($css, $files);
}
// Constructor allocation and WordPressCompatCss must share the same primitive
// for all three shim families, including HTML/CSS collisions.
foreach ($cases as $index => [$files, $css]) {
    $html = '';
    foreach ($files as $file) if ('html' === ($file['kind'] ?? '')) $html .= (string) ($file['content'] ?? '');
    $document = new DOMDocument();
    $document->loadHTML('<html><body></body></html>');
    $analysis = new AuthorStyleAnalysis($html, $css, array(), $document->getElementsByTagName('body')->item(0));
    $constructorShims = array($analysis->specificityShim(), $analysis->classSpecificityShim(), $analysis->idSpecificityShim());
    if ($constructorShims !== $expected[$index]) throw new RuntimeException('AuthorStyleAnalysis and compatibility shim names differ.');
}
$source = file_get_contents(dirname(__DIR__, 2) . '/src/ArtifactCompiler/WordPressCompatCss.php');
if (false === $source || str_contains($source, 'new AuthorStyleAnalysis') || str_contains($source, '$html = implode')) {
    throw new RuntimeException('WordPressCompatCss rebuilt or joined site HTML for specificity context.');
}
echo "WordPress compatibility specificity shim allocation passed\n";
