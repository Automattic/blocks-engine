<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\NativeSelectRuntime;

$assert = static function (bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); };
$state = htmlspecialchars(json_encode(array('version' => 1, 'kind' => 'select', 'options' => array(array('selected' => false, 'defaultSelected' => true), array('selected' => true, 'defaultSelected' => false)))), ENT_QUOTES);
$result = (new HtmlTransformer())->transform('<select id="plan" name="plan" multiple required data-hook="yes" data-blocks-engine-capture-native-control-state="' . $state . '"><optgroup label="Plans"><option value="" selected></option><option value="pro" disabled>Pro &amp; Plus</option></optgroup></select>')->toArray();
$block = $result['blocks'][0];
$assert('core/list' === $block['blockName'], 'Native select must be an editable core list');
$assert(array('core/list-item', 'core/list-item') === array_column($block['innerBlocks'], 'blockName'), 'Blank and named options are editable core items');
$assert(!str_contains($result['serialized_blocks'], 'authored-select'), 'No select companion is emitted');
$assert(true === $block['innerBlocks'][0]['attrs']['metadata']['blocksEngineSelectOption']['selected'], 'Default selected is retained');
$assert(false === $block['innerBlocks'][0]['attrs']['metadata']['blocksEngineSelectOption']['initialSelected'], 'Current selected remains distinct');

// Run the generated public theme filter with the WordPress escaping surface.
function add_filter($name, $callback, ...$args) { $GLOBALS['select_filter'] = $callback; }
function esc_attr($text) { return htmlspecialchars((string) $text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function esc_html($text) { return esc_attr($text); }
function wp_strip_all_tags($text) { return strip_tags($text); }
function wp_json_encode($data, $flags = 0) { return json_encode($data, $flags); }
function wp_kses_post($text) { return $text; }
eval(NativeSelectRuntime::source());
$render = $GLOBALS['select_filter'];
$html = $render('', $block);
$assert(str_contains($html, '<select id="plan" name="plan" required multiple data-hook="yes"'), 'Native identity, form semantics and hooks survive');
$assert(str_contains($html, '<optgroup label="Plans"><option value="" selected></option><option value="pro" disabled>Pro &amp; Plus</option>'), 'Groups, blank values and disabled choices survive');
$assert(str_contains($html, 'data-blocks-engine-control-state='), 'Current state is replayable without overwriting defaults');
$block['innerBlocks'][1]['innerHTML'] = '<li>Edited &amp; editable</li>';
$assert(str_contains($render('', $block), '>Edited &amp; editable</option>'), 'Core editor label changes reach native output');
// Non-capture form options omit valueDeclared; their declared values still submit.
$form = (new HtmlTransformer())->transform('<form action="/filter"><select name="type"><option value="">All</option><option value="house" selected>House</option></select></form>')->toArray();
$formHtml = \Automattic\BlocksEngine\PhpTransformer\Tests\Support\NativeSelectRendererFixture::render($form['serialized_blocks']);
$assert(str_contains($formHtml, '<option value="">All</option><option value="house" selected>House</option>'), 'Ordinary form option values survive native rendering without capture metadata');
echo "Core native select regression passed\n";
