<?php
declare(strict_types=1);

// A control the source keeps hidden (a conditional field that script reveals)
// publishes the deciding declaration, so a consumer does not render it as a
// visible, required field. The `hidden` attribute is the user-agent's
// `display: none`, which any author rule may override. A control's own
// `display: none` (a custom checkbox behind its visible label) is not hiding.

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

$html = '<main><style>.reshown[hidden]{display:block}</style><form action="/c" method="post">'
    . '<label for="n">Name</label><input id="n" name="name" required>'
    . '<div class="cond" hidden data-show-when="onsite"><label for="a">Address</label><input id="a" name="addr" required></div>'
    . '<div class="reshown" hidden><label for="b">Shown</label><input id="b" name="b"></div>'
    . '<div style="display:none"><label for="c">Styled</label><input id="c" name="c"></div>'
    . '<label class="chip"><input type="checkbox" name="d" hidden> Chip</label>'
    . '<button type="submit">Send</button></form></main>';
$controls = array();
foreach ((new HtmlTransformer())->transform($html)->toArray()['fallbacks'][0]['controls'] ?? array() as $control) {
    $controls[$control['name'] ?? $control['type']] = $control['hidden'] ?? null;
}
$expected = array(
    'name' => null,
    'addr' => array('property' => 'display', 'value' => 'none', 'selector' => '[hidden]', 'source_path' => 'user-agent'),
    'b' => null,
    'c' => array('property' => 'display', 'value' => 'none', 'selector' => '[style]', 'source_path' => 'inline-style'),
    'd' => null,
    'submit' => null,
);
if ($expected !== $controls) {
    fwrite(STDERR, "FAIL: hidden control facts\n" . json_encode($controls, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
    exit(1);
}
echo "form hidden control passed\n";
