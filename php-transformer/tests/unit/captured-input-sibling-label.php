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
echo "Captured input sibling label regression passed\n";
