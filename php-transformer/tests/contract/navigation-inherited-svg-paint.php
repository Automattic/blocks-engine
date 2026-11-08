<?php
declare(strict_types=1);

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

$html = '<html><head><style>'
    . '.markers{--ink:#888;stroke-opacity:.75;fill-opacity:.5}'
    . '.markers svg{fill:transparent;stroke:var(--ink);stroke-width:4px}'
    . '.markers .selected svg{fill:var(--ink)}'
    . '</style></head><body><nav class="markers" aria-label="Sections"><ul>'
    . '<li><a href="#first"><svg viewBox="0 0 24 24" width="24" height="24"><circle cx="12" cy="12" r="10"/></svg>First</a></li>'
    . '<li><a class="selected" href="#second"><svg viewBox="0 0 24 24" width="24" height="24"><circle cx="12" cy="12" r="10"/></svg>Second</a></li>'
    . '</ul></nav><main><h2 id="first">First section</h2><h2 id="second">Second section</h2></main></body></html>';
$result = (new HtmlTransformer())->transform($html)->toArray();
if (2 !== substr_count($result['serialized_blocks'] ?? '', '<!-- wp:navigation-link ')) {
    throw new RuntimeException('Both source links must become native navigation links.');
}
$css = implode("\n", array_column($result['assets'] ?? array(), 'content'));
preg_match_all('/background-image:url\("data:image\/svg\+xml,([^"\n]+)"\)/', $css, $matches);
$paints = array();
foreach (array_unique($matches[1]) as $encoded) {
    $svg = rawurldecode($encoded);
    $document = new DOMDocument();
    if (!$document->loadXML($svg)) throw new RuntimeException('Marker must be standalone SVG.');
    $circle = $document->getElementsByTagName('circle')->item(0);
    if (!$circle instanceof DOMElement) continue;
    $style = $circle->getAttribute('style');
    foreach (array('stroke:#888', 'stroke-width:4px', 'stroke-opacity:.75', 'fill-opacity:.5') as $declaration) {
        if (!str_contains($style, $declaration)) {
            throw new RuntimeException('Standalone navigation circle lost inherited ' . $declaration . ': ' . $svg);
        }
    }
    if (str_contains($style, 'fill:transparent')) $paints['outlined'] = true;
    if (str_contains($style, 'fill:#888')) $paints['selected'] = true;
}
if (count($paints) !== 2) {
    throw new RuntimeException('Leading-icon backgrounds must retain distinct outlined and selected paints.');
}
echo "Navigation inherited SVG paint passed.\n";
