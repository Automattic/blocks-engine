<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

// A positional child utility selects source controls, not the field shells a
// form provider inserts later. Keep the margin on the control, exactly once.
// Rules that reach every element (a margin reset) must keep reaching shells.
$result = (new HtmlTransformer())->transform(
    '<form class="stack"><input placeholder="Name" required><input type="email" placeholder="Email" required><textarea placeholder="Message"></textarea><button type="submit">Send</button></form>',
    array('static_css' => '*{margin:0}@layer utilities{:where(.stack>:not(:last-child)){margin-block-end:16px}}')
)->toArray();
$css = implode('', array_column($result['assets'], 'content'));
if (!str_contains($css, ':where(.stack>:not(:last-child)):not(:where(.grunion-field-wrap))')) {
    throw new RuntimeException('Structural margins must exclude invented provider field shells.');
}
if (1 !== preg_match_all('/:not\(:where\(\.grunion-field-wrap\)\)[^{]*\{/', $css, $unused) || 1 === preg_match('/:not\(:where\(\.grunion-field-wrap\)\)[^{]*\{margin:0\}/', $css)) {
    throw new RuntimeException('Only the positional child rule may exclude provider field shells; resets still reach them.');
}
$rows = $result['fallbacks'][0]['presentation_graph']['controls'] ?? array();
foreach (array_slice($rows, 0, 3) as $row) {
    if (($row['control']['styles']['margin_block_end'] ?? null) !== '16px') {
        throw new RuntimeException('Source control margin must remain in the public presentation contract.');
    }
}
echo "Structural form margins retain one source box owner.\n";
