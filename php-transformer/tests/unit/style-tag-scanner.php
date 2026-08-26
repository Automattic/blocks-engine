<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\StyleTagScanner;

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        fwrite(STDERR, 'FAIL: ' . $message . PHP_EOL);
        exit(1);
    }
};

// Parity with the regex the scanner replaced, on well-formed markup.
$html = '<html><head><style type="text/css" media="(max-width: 600px)">.a{color:red}</style>'
    . '<link rel="stylesheet" href="site.css"><STYLE>.b{color:blue}</STYLE>'
    . '<link rel="icon" href="favicon.ico"></head><body><style>p{margin:0}</style></body></html>';
$blocks = StyleTagScanner::styleBlocks($html);
preg_match_all('@<style\b([^>]*)>(.*?)</style>@is', $html, $regex, PREG_SET_ORDER);
$assert(count($regex) === count($blocks), 'scanner finds the same style blocks as the regex');
foreach ($regex as $index => $match) {
    $assert($blocks[$index]['attributes'] === $match[1] && $blocks[$index]['css'] === $match[2], 'scanner block ' . $index . ' matches the regex capture');
}

$tokens = StyleTagScanner::styleAndLinkTags($html);
$assert(array('style', 'link', 'style', 'link', 'style') === array_column($tokens, 'kind'), 'style and link tags interleave in document order');
$assert('<link rel="stylesheet" href="site.css">' === ($tokens[1]['markup'] ?? ''), 'link tokens carry the raw tag markup');
$assert('.b{color:blue}' === ($tokens[2]['css'] ?? ''), 'uppercase style tags are scanned case-insensitively');

$assert(array() === StyleTagScanner::styleBlocks('<style>.unclosed{color:red}'), 'an unclosed style tag yields no match, like the regex');
$assert(array(array('attributes' => '', 'css' => '.real{}')) === StyleTagScanner::styleBlocks('<style-guide>copy</style-guide><style>.real{}</style>'), 'longer tag names are not style boundaries');
$assert(array(
    array('kind' => 'style', 'attributes' => '', 'css' => '.a{content:"x"}<link rel="stylesheet" href="in.css">'),
    array('kind' => 'link', 'markup' => '<link rel="stylesheet" href="out.css">'),
) === StyleTagScanner::styleAndLinkTags('<style>.a{content:"x"}<link rel="stylesheet" href="in.css"></style><link rel="stylesheet" href="out.css">'), 'links inside a style body are not emitted');

// Regression for issue #1241: one style body beyond pcre.backtrack_limit made
// preg_match_all() return false, and every author stylesheet on the page was
// silently dropped. The fixture must run under DEFAULT pcre ini values.
$bigCss = str_repeat('.bulk{background-image:url(data:image/png;base64,' . str_repeat('QUJD', 1500) . ')}', 200) . '.big-marker{color:#00fed1}';
$smallCss = '.small-marker{color:#123456}';
$hugeHtml = '<html><head><style>' . $bigCss . '</style><style media="print">' . $smallCss . '</style></head>'
    . '<body><p class="big-marker">Big</p><p class="small-marker">Small</p></body></html>';

preg_match_all('@<style\b([^>]*)>(.*?)</style>@is', $hugeHtml, $unused);
$assert(PREG_BACKTRACK_LIMIT_ERROR === preg_last_error(), 'precondition: the fixture exhausts the default PCRE backtrack limit');

$scanned = StyleTagScanner::styleBlocks($hugeHtml);
$assert(2 === count($scanned), 'the scanner is immune to the PCRE backtrack limit');
$assert($bigCss === $scanned[0]['css'] && $smallCss === $scanned[1]['css'] && ' media="print"' === $scanned[1]['attributes'], 'oversized and trailing style blocks are captured verbatim');

$result = ( new ArtifactCompiler() )->compile(array(
    'files' => array(
        array( 'path' => 'index.html', 'kind' => 'html', 'content' => $hugeHtml ),
    ),
) )->toArray();
$assets = array_values(array_filter($result['assets'] ?? array(), 'is_array'));
$assetPaths = array_column($assets, 'path');
$assetCss = implode("\n", array_map(static fn (array $asset): string => (string) ($asset['content'] ?? ''), $assets));
$assert(in_array('index.inline-1.css', $assetPaths, true) && in_array('index.inline-2.css', $assetPaths, true), 'both inline style tags materialize as author stylesheet assets');
$assert(str_contains($assetCss, 'color:#00fed1'), 'author styles from the oversized style tag survive compilation');
$assert(str_contains($assetCss, 'color:#123456'), 'author styles from the style tag after the oversized one survive compilation');

fwrite(STDOUT, "StyleTagScanner unit tests passed.\n");
