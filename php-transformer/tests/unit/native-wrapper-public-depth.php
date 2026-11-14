<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;
use Automattic\BlocksEngine\PhpTransformer\WordPress\Runtime;

// Short CSS-addressable ancestry must spend the public saved-tree budget,
// not inherit the private uninterrupted-chain limit from deeper branches.
$html = '<style>.frame .paint{background:red}</style>';
for ($i = 0; $i < 6; ++$i) {
    $html .= '<div id="box-' . $i . '" class="frame">';
}
$html .= '<div class="paint"><div id="first"><p>First</p><p>Second</p></div><div id="second"><p>Third</p><p>Fourth</p></div></div>' . str_repeat('</div>', 6);
$result = (new HtmlTransformer())->transform($html)->toArray();
$markup = (string) $result['serialized_blocks'];
if (str_contains($markup, '/layout-shell') || 9 !== substr_count($markup, '<!-- wp:group')) {
    throw new RuntimeException('An in-budget native tree must not become generated shell transport: ' . $markup);
}
$runtime = new Runtime();
$saved = $runtime->serializeBlocks($runtime->parseBlocks($markup));
$document = new DOMDocument();
@$document->loadHTML($saved);
$xpath = new DOMXPath($document);
$path = '';
for ($i = 0; $i < 6; ++$i) {
    $path .= '/div[@id="box-' . $i . '"]';
}
if (4 !== $xpath->query('/html/body' . $path . '/div[contains(@class,"paint")]/div/p')->length) {
    throw new RuntimeException('Core save must preserve every source box and child-selector edge.');
}
// Raising the native budget must not disable compaction beyond that budget.
$deepHtml = '<style>.frame p{color:red}</style><p>Outside</p>';
for ($i = 0; $i < 22; ++$i) {
    $deepHtml .= '<div id="deep-' . $i . '" class="frame">';
}
$deepHtml .= '<p>Inside</p>' . str_repeat('</div>', 22);
$deepResult = (new HtmlTransformer())->transform($deepHtml)->toArray();
if (!str_contains((string) $deepResult['serialized_blocks'], '/layout-shell')) {
    throw new RuntimeException('Over-budget source ancestry must still use compact shell transport.');
}
echo "Native wrapper public depth passed\n";
