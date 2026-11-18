<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

// A provider's block label must not replace the source's inline line box.
// The inline caption participates in its parent's strut even when its own
// line-height is smaller; this is visible in stacked booking forms.
foreach (array('' => 'inline', 'display:block;' => 'block', 'display:flex;' => 'flex') as $display => $expected) {
    $result = (new HtmlTransformer())->transform(
        '<form method="post"><div style="line-height:24px"><label for="name" style="' . $display . 'font-size:14px;line-height:14px">Name</label><input id="name" name="name"></div><button type="submit">Send</button></form>'
    )->toArray();
    $graph = $result['fallbacks'][0]['presentation_graph'];
    $label = $graph['controls'][0]['label']['styles'];
    if (($label['display'] ?? null) !== $expected) {
        throw new RuntimeException('Source label display must be explicit for provider materialization: ' . $expected);
    }
    if ('14px' !== ($label['line_height'] ?? null)) {
        throw new RuntimeException('Label typography must remain independent of its display.');
    }
}
echo "Inline form label presentation: 3 passed\n";
