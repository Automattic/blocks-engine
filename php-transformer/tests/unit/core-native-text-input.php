<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\NativeInputRuntime;

// #6168: a captured text field is an editable core block, not a companion.
$assert = static function (bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); };
$state = static fn (string $value, string $default): string => htmlspecialchars(json_encode(array('version' => 1, 'kind' => 'input', 'type' => 'text', 'value' => $value, 'defaultValue' => $default, 'checked' => false, 'defaultChecked' => false, 'indeterminate' => false)), ENT_QUOTES);
$source = '<main><form class="card"><label class="block"><span class="eyebrow">Your vehicle</span><input required minlength="2" maxlength="300" class="field" placeholder="Make, model &amp; year" value="" data-blocks-engine-capture-native-control-state="' . $state('', '') . '"></label><button type="submit">Go</button></form></main>';
$result = (new HtmlTransformer())->transform($source)->toArray();
$flatten = static function (array $blocks) use (&$flatten): array { $out = array(); foreach ($blocks as $block) { $out[] = $block; $out = array_merge($out, $flatten($block['innerBlocks'] ?? array())); } return $out; };
$fields = array_values(array_filter($flatten($result['blocks']), static fn (array $block): bool => isset($block['attrs']['metadata']['blocksEngineInput'])));
$assert(!str_contains($result['serialized_blocks'], 'authored-input'), 'No input companion is emitted');
$assert(1 === count($fields) && 'core/search' === $fields[0]['blockName'], 'Captured text field is an editable core field block');
$assert('Make, model & year' === ($fields[0]['attrs']['placeholder'] ?? null) && 'no-button' === ($fields[0]['attrs']['buttonPosition'] ?? null), 'The placeholder is edited in the core field, with no search button');
$assert(true === ($fields[0]['attrs']['showLabel'] ?? null) && '<span class="eyebrow">Your vehicle</span>' === ($fields[0]['attrs']['label'] ?? null), 'The label is edited as the core field label');
// The editor canvas must not show the field as body text the front end lacks.
$assert(!preg_match('/<!-- wp:paragraph[^>]*blocksEngineInput/', $result['serialized_blocks']), 'The field is not a paragraph');

function add_filter($name, $callback, ...$args) { $GLOBALS['input_filter'] = $callback; }
function esc_attr($text) { return htmlspecialchars((string) $text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function esc_html($text) { return esc_attr($text); }
function wp_kses_post($text) { return $text; }
eval(NativeInputRuntime::source());
$render = $GLOBALS['input_filter'];
$html = $render('', $fields[0]);
$assert('<label class="block"><span class="eyebrow">Your vehicle</span><input type="text" value="" placeholder="Make, model &amp; year" class="field" minlength="2" maxlength="300" required></label>' === $html, 'Native field, label and constraints render on the front end: ' . $html);
$fields[0]['attrs']['placeholder'] = 'Edited & editable';
$assert(str_contains($render('', $fields[0]), 'placeholder="Edited &amp; editable"'), 'Core editor placeholder changes reach the native field');
$assert('<form>x</form>' === $render('<form>x</form>', array('blockName' => 'core/search', 'attrs' => array())), 'Ordinary search blocks are untouched');

// A field whose current value differs from its default keeps the state-replaying companion.
$dirty = (new HtmlTransformer())->transform('<main><input class="field" placeholder="Name" value="" data-blocks-engine-capture-native-control-state="' . $state('typed', '') . '"></main>')->toArray();
$assert(str_contains($dirty['serialized_blocks'], 'authored-input'), 'Captured current state stays replayable');
// External captions must stay siblings: wrapping them around the input loses
// author child/sibling spacing and changes the label's line box.
$external = (new HtmlTransformer())->transform('<form><div class="space-y"><label for="guest" class="caption">Guest</label><input id="guest" data-blocks-engine-capture-native-control-state="' . $state('', '') . '"></div><button>Send</button></form>')->toArray();
$externalFields = array_values(array_filter($flatten($external['blocks']), static fn (array $block): bool => isset($block['attrs']['metadata']['blocksEngineInput'])));
$assert(1 === count($externalFields), 'External label field remains core editable');
$externalHtml = $render('', $externalFields[0]);
$assert(str_contains($externalHtml, '<label for="guest" class="caption">Guest</label><input'), 'External label retains association and sibling topology: ' . $externalHtml);
$assert(!str_contains($html, 'for='), 'Wrapping labels keep their original topology');
echo "Core native text input regression passed\n";
