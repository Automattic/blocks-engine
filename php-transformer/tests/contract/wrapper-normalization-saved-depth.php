<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

// The outer chain must fold to fit the depth budget. The nav boundary's
// children must then be normalized at that saved depth, not their old depth.
$source = '';
for ($i = 0; $i < 16; ++$i) {
    $source .= '<div id="outer-' . $i . '">';
}
$source .= '<nav id="navigation" style="padding:10px">';
for ($i = 0; $i < 4; ++$i) {
    $source .= '<div id="icon-' . $i . '">';
}
$source .= '<img src="https://example.com/icon.png" alt="Menu">';
$source .= str_repeat('</div>', 4) . '</nav>' . str_repeat('</div>', 16);
$result = (new HtmlTransformer())->transform($source)->toArray();
$markup = $result['serialized_blocks'];
if (1 !== substr_count($markup, '<!-- wp:custom/layout-shell ')) {
    throw new RuntimeException('Only the outer over-budget chain needs a shell: ' . $markup);
}
$dom = new DOMDocument();
@$dom->loadHTML($markup);
$xpath = new DOMXPath($dom);
if (1 !== $xpath->query('//nav[@id="navigation"]/div[@id="icon-0"]/div[@id="icon-1"]/div[@id="icon-2"]/div[@id="icon-3"]//img')->length
    || 4 !== substr_count($markup, '<!-- wp:group {"anchor":"icon-')) {
    throw new RuntimeException('Icon wrappers must remain ordered native editable groups: ' . $markup);
}
echo "Wrapper normalization saved depth contract passed.\n";
