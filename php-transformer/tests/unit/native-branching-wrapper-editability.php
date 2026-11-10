<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

// A branching terminal is a traversal-only freeform, not evidence that the
// surrounding native Groups need a companion. In particular its children
// need not themselves be Groups (page content commonly starts with text).
$result = (new HtmlTransformer())->transform(
    '<style>.frame>.inner{color:red}</style>'
    . '<div id="root"><div id="master"><div id="pages"><div class="frame"><div class="inner"><h1>Hello</h1><p>Text</p></div></div></div></div></div>'
)->toArray();
$markup = $result['serialized_blocks'];
if (str_contains($markup, '/layout-shell') || str_contains($markup, 'wp:html') || 5 !== substr_count($markup, '<!-- wp:group')) {
    throw new RuntimeException('Branching native wrapper chains must stay editable core Groups: ' . $markup);
}
$dom = new DOMDocument();
@$dom->loadHTML($markup);
$xpath = new DOMXPath($dom);
if (1 !== $xpath->query('//div[@id="root"]/div[@id="master"]/div[@id="pages"]/div[contains(@class,"frame")]/div[contains(@class,"inner")]/h1[following-sibling::p]')->length) {
    throw new RuntimeException('Native wrapper ancestry and ordered editable children must survive.');
}
echo "native branching wrapper editability passed\n";
