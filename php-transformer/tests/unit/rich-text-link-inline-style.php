<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\WordPress\BlockValidityValidator;

// Issue #3463: inline link values must beat author link CSS after compilation,
// using the same core-native carrier as spans, not a style on core/link.
$html = '<html><head><style>a{color:#fbb153}</style></head><body><main>'
    . '<h2><a href="/Blog-Actualites/index.html" style="color: white;">news</a></h2>'
    . '<p>See <a href="/blog/" target="_blank" rel="noopener noreferrer" style="color:red">news</a> now</p>'
    . '<ul><li>Read <a class="story" href="/story/" style="color:blue;font-weight:bold"><span style="color:green">nested</span> news</a></li></ul>'
    . '<p><a class="plain" href="/plain/">Unstyled</a></p>'
    . '<p>Enroll <a href="/course/" style="color:#555;text-decoration:none">course</a></p>'
    . '<p>Bare <a href="/bare/" style="text-decoration:none">bare</a></p>'
    . '<p>Call <a href="/tel/" style="color:red;border-bottom:1px solid red;display:inline-flex">tel</a></p>'
    . '</main></body></html>';
$result = (new ArtifactCompiler())->compile(array(
    'entrypoint' => 'website/index.html',
    'files' => array('website/index.html' => $html),
))->toArray();
$markup = (string) ($result['serialized_blocks'] ?? '');
$document = new DOMDocument();
$document->loadHTML('<?xml encoding="utf-8" ?><body>' . $markup . '</body>', LIBXML_NOERROR | LIBXML_NOWARNING);
$xpath = new DOMXPath($document);
$assert = static function (bool $ok, string $label) use ($markup): void {
    if (!$ok) {
        fwrite(STDERR, "FAIL: {$label}\n{$markup}\n");
        exit(1);
    }
};
foreach (array('/Blog-Actualites/index.html' => 'white', '/blog/' => 'red', '/story/' => 'blue') as $href => $color) {
    $link = $xpath->query('//a[@href="' . $href . '"]')->item(0);
    $assert($link instanceof DOMElement, 'link destination survives: ' . $href);
    $carrier = $link->firstElementChild;
    $assert($carrier instanceof DOMElement && 'mark' === $carrier->tagName, 'visual carrier is inside the link');
    $assert(str_contains($carrier->getAttribute('style'), 'color:' . $color), 'inline color survives: ' . $color);
    $assert(str_contains($carrier->getAttribute('style'), 'background-color:transparent'), 'carrier resets highlight background');
    $assert('none' === $carrier->getAttribute('role') && !$link->hasAttribute('style'), 'core-native non-highlight carrier');
}
$link = $xpath->query('//a[@href="/blog/"]')->item(0);
$assert('_blank' === $link->getAttribute('target') && 'noopener noreferrer' === $link->getAttribute('rel'), 'target and rel survive');
$story = $xpath->query('//a[@href="/story/"]')->item(0);
$assert(str_contains($story->getAttribute('class'), 'story'), 'link identity stays on link');
$assert(str_contains($story->firstElementChild->getAttribute('style'), 'font-weight:bold'), 'non-color visual declaration survives');
$assert(1 === $xpath->query('//a[@href="/story/"]/mark/mark[contains(@style,"color:green")]')->length, 'nested color retains precedence');
$assert(0 === $xpath->query('//a[@href="/plain/"]/mark')->length, 'author-only link color is not frozen on a carrier');
// A descendant cannot cancel the link's own underline, so decorations stay
// on the anchor through a generated class + stylesheet rule, not the mark.
$css = '';
foreach ($result['assets'] ?? array() as $asset) {
    if ('css' === ($asset['kind'] ?? '')) $css .= (string) ($asset['content'] ?? '') . "\n";
}
foreach (array('/course/', '/bare/') as $href) {
    $link = $xpath->query('//a[@href="' . $href . '"]')->item(0);
    $assert($link instanceof DOMElement && !$link->hasAttribute('style'), 'decorated link survives without inline style: ' . $href);
    $assert(1 === preg_match('/\\b(be-inline-geometry-[0-9a-f]+)\\b/', $link->getAttribute('class'), $match), 'decoration class is on the anchor: ' . $href);
    $assert(str_contains($css, 'a.' . $match[1] . '{text-decoration:none!important}'), 'anchor rule removes the underline: ' . $href);
    $assert(0 === $xpath->query('.//*[contains(@style,"text-decoration")]', $link)->length, 'decoration is not moved to a descendant: ' . $href);
}
$course = $xpath->query('//a[@href="/course/"]/mark')->item(0);
$assert($course instanceof DOMElement && str_contains($course->getAttribute('style'), 'color:#555'), 'inheritable color stays on the mark');
$assert(0 === $xpath->query('//a[@href="/bare/"]/mark')->length, 'decoration-only link needs no mark carrier');
// Box values style the link's own box, so they stay on the anchor too.
$tel = $xpath->query('//a[@href="/tel/"]')->item(0);
$assert(1 === preg_match('/\\b(be-inline-geometry-[0-9a-f]+)\\b/', $tel->getAttribute('class'), $match)
    && str_contains($css, 'a.' . $match[1] . '{border-bottom:1px solid red!important;display:inline-flex!important}'), 'box values are owned by the anchor');
$telMark = $tel->firstElementChild;
$assert($telMark instanceof DOMElement && 'mark' === $telMark->tagName && str_contains($telMark->getAttribute('style'), 'color:red')
    && !str_contains($telMark->getAttribute('style'), 'border') && !str_contains($telMark->getAttribute('style'), 'display'), 'mark carries only inherited text styling');
$validity = (new BlockValidityValidator())->validateBlocks($result['blocks'] ?? array());
$assert('pass' === ($validity['status'] ?? ''), 'compiled blocks remain valid');

// Optional output for the real-browser regression check.
if (isset($argv[1])) {
    file_put_contents($argv[1], '<!doctype html><style>' . $css . 'a{color:#fbb153}</style>' . $markup);
}
echo "Rich-text link inline style tests passed\n";
