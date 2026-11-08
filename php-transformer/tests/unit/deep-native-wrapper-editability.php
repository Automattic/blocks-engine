<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

// Depth is reported, not enforced by replacing editable ancestors with a
// generated carrier. IDs and child selectors require every source boundary.
$html = '<style>div > div { margin-left: 1px }</style>';
for ($index = 0; $index < 24; $index++) {
    $html .= '<div id="frame-' . $index . '">';
}
$html .= '<h2>Editable terminal</h2>' . str_repeat('</div>', 24);
$result = (new HtmlTransformer())->transform($html)->toArray();
$markup = (string) ($result['serialized_blocks'] ?? '');
if (str_contains($markup, '/layout-shell') || substr_count($markup, '<!-- wp:group') !== 24 || !str_contains($markup, '<!-- wp:heading')) {
    throw new RuntimeException('Deep source wrappers must remain native Groups: ' . $markup);
}
$document = new DOMDocument();
@$document->loadHTML($markup);
$xpath = new DOMXPath($document);
for ($index = 0; $index < 23; $index++) {
    if (1 !== $xpath->query('//*[@id="frame-' . $index . '"]/div[@id="frame-' . ($index + 1) . '"]')->length) {
        throw new RuntimeException('Lost wrapper parent-child edge at ' . $index);
    }
}
// Empty terminals occur after site-wide extraction (for example, when a
// duplicated navigation subtree is removed). They must not turn otherwise
// editable div ancestors back into generated layout shells.
$empty = (new HtmlTransformer())->transform('<div id="outer"><div id="middle"><div id="empty" style="height:20px;background:red"></div></div></div>')->toArray();
$emptyMarkup = (string) ($empty['serialized_blocks'] ?? '');
if (str_contains($emptyMarkup, '/layout-shell') || substr_count($emptyMarkup, '<!-- wp:group') !== 3) {
    throw new RuntimeException('Empty wrapper terminals must remain native Groups: ' . $emptyMarkup);
}
$emptyDocument = new DOMDocument();
@$emptyDocument->loadHTML($emptyMarkup);
$emptyXpath = new DOMXPath($emptyDocument);
if (1 !== $emptyXpath->query('//*[@id="outer"]/div[@id="middle"]/div[@id="empty"]')->length) {
    throw new RuntimeException('Empty terminal lost its source parent-child edges');
}
echo "Deep native wrapper editability passed\n";
