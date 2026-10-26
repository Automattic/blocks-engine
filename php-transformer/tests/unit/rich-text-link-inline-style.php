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
$validity = (new BlockValidityValidator())->validateBlocks($result['blocks'] ?? array());
$assert('pass' === ($validity['status'] ?? ''), 'compiled blocks remain valid');

// Optional output for the real-browser regression check.
if (isset($argv[1])) {
    file_put_contents($argv[1], '<!doctype html><style>a{color:#fbb153}</style>' . $markup);
}
echo "Rich-text link inline style tests passed\n";
