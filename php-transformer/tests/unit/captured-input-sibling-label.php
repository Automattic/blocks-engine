<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\NativeInputRuntime;

function add_filter($name, $callback, ...$args) { $GLOBALS['input_filter'] = $callback; }
function esc_attr($text) { return htmlspecialchars((string) $text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function esc_html($text) { return esc_attr($text); }
function wp_kses_post($text) { return $text; }
eval(NativeInputRuntime::source());

$state = htmlspecialchars(json_encode(array('version' => 1, 'kind' => 'input', 'type' => 'text', 'value' => '', 'defaultValue' => '', 'checked' => false, 'defaultChecked' => false, 'indeterminate' => false)), ENT_QUOTES);
$input = '<input id="name" class="field" data-blocks-engine-capture-native-control-state="' . $state . '">';
$label = '<label for="name" id="caption" class="caption">Full Name</label>';
$flatten = static function (array $blocks) use (&$flatten): array {
    $out = array();
    foreach ($blocks as $block) {
        $out[] = $block;
        $out = array_merge($out, $flatten($block['innerBlocks'] ?? array()));
    }
    return $out;
};
foreach (array(false, true) as $after) {
    $source = '<style>.fields>:not([hidden])~:not([hidden]){margin-top:6px}.field{display:flex;height:36px}.caption{line-height:1}</style><form><div class="fields">'
        . ($after ? $input . $label : $label . $input) . '</div><button type="submit">Send</button></form>';
    $result = (new HtmlTransformer())->transform($source)->toArray();
    $fields = array_values(array_filter($flatten($result['blocks']), static fn (array $block): bool => isset($block['attrs']['metadata']['blocksEngineInput'])));
    if (1 !== count($fields)) throw new RuntimeException('Expected one captured core field');
    $html = ($GLOBALS['input_filter'])('', $fields[0]);
    $dom = new DOMDocument();
    $dom->loadHTML('<div id="test">' . $html . '</div>');
    $xpath = new DOMXPath($dom);
    if (1 !== $xpath->query('//*[@id="test"]/label[@for="name"]')->length
        || 1 !== $xpath->query('//*[@id="test"]/input[@id="name"]')->length
        || 1 !== $xpath->query($after ? '//input/following-sibling::label' : '//label/following-sibling::input')->length) {
        throw new RuntimeException('External label must remain a sibling in source order: ' . $html);
    }
}
// Ordinary authored controls use generated blocks, not the capture-backed
// core runtime. Do not leak runtime-only association attributes into their
// serialized contract or change their existing wrapper topology.
$ordinary = '<style>input,select,textarea{border:1px solid red}</style><form>'
    . '<label for="ordinary">Name</label><input id="ordinary">'
    . '<label for="choice">Choice</label><select id="choice"><option>A</option></select>'
    . '<label for="message">Message</label><textarea id="message"></textarea>'
    . '<button type="submit">Send</button></form>';
$result = (new HtmlTransformer())->transform($ordinary)->toArray();
foreach ($flatten($result['blocks']) as $block) {
    $attrs = $block['attrs'] ?? array();
    if (array_key_exists('labelFor', $attrs)
        || (array_key_exists('labelAfterControl', $attrs) && false === $attrs['labelAfterControl'])) {
        throw new RuntimeException('Capture-only label attributes leaked into ordinary control output');
    }
}
// Property replay uses the generated input, not the core runtime. A trailing
// external caption must not acquire runtime-only attributes or be reordered.
$dirtyState = htmlspecialchars(json_encode(array('version' => 1, 'kind' => 'input', 'type' => 'text', 'value' => 'typed', 'defaultValue' => '', 'checked' => false, 'defaultChecked' => false, 'indeterminate' => false)), ENT_QUOTES);
$result = (new HtmlTransformer())->transform('<form><div>'
    . '<input id="dirty" data-blocks-engine-capture-native-control-state="' . $dirtyState . '">'
    . '<label for="dirty">Name</label></div><button type="submit">Send</button></form>')->toArray();
$foundDirty = false;
foreach ($flatten($result['blocks']) as $block) {
    if ('dirty' !== ($block['attrs']['id'] ?? null)) continue;
    $foundDirty = true;
    if (!str_ends_with($block['blockName'], '/authored-input')
        || isset($block['attrs']['labelFor']) || !empty($block['attrs']['labelAfterControl'])) {
        throw new RuntimeException('Core sibling-label metadata changed a replay-backed input');
    }
}
if (!$foundDirty) throw new RuntimeException('Expected the replay-backed input');
echo "Captured input sibling label regression passed\n";
