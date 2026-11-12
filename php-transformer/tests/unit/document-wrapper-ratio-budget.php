<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;
use Automattic\BlocksEngine\PhpTransformer\WordPress\Runtime;

// Visual-only component branches have no content denominator of their own.
// The wrapper budget is a document metric, not a reason to fold each such branch.
$html = str_repeat('<p>Editable document content</p>', 10)
    . '<div id="outer"><div id="inner"><div style="background:red;min-height:20px"></div><div id="a"><div id="b"><div id="c"><div id="d"><p>Component copy</p></div></div></div></div></div></div>';
$result = (new HtmlTransformer())->transform($html)->toArray();
$markup = $result['serialized_blocks'];
if (str_contains($markup, '/layout-shell') || 7 !== substr_count($markup, '<!-- wp:group')) {
    throw new RuntimeException('An in-budget document must retain native visual component wrappers.');
}
$runtime = new Runtime();
$saved = $runtime->serializeBlocks($runtime->parseBlocks($markup));
$dom = new DOMDocument();
@$dom->loadHTML($saved);
if (2 !== (new DOMXPath($dom))->query('//*[@id="outer"]/div[@id="inner"]/div')->length) {
    throw new RuntimeException('Editor save must retain every visual box and direct-child edge.');
}
echo "Document wrapper ratio budget passed\n";
