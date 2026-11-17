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
$assert(1 === count($fields) && 'core/paragraph' === $fields[0]['blockName'], 'Captured text field is an editable core paragraph');
$assert(str_contains($fields[0]['innerHTML'], 'Make, model &amp; year'), 'The placeholder is the editable core text');

function add_filter($name, $callback, ...$args) { $GLOBALS['input_filter'] = $callback; }
function esc_attr($text) { return htmlspecialchars((string) $text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function esc_html($text) { return esc_attr($text); }
function wp_strip_all_tags($text) { return trim(strip_tags($text)); }
function wp_kses_post($text) { return $text; }
eval(NativeInputRuntime::source());
$render = $GLOBALS['input_filter'];
$html = $render('', $fields[0]);
$assert('<label class="block"><span class="eyebrow">Your vehicle</span><input type="text" value="" placeholder="Make, model &amp; year" class="field" minlength="2" maxlength="300" required></label>' === $html, 'Native field, label and constraints render on the front end: ' . $html);
$fields[0]['innerHTML'] = '<p>Edited &amp; editable</p>';
$assert(str_contains($render('', $fields[0]), 'placeholder="Edited &amp; editable"'), 'Core editor text changes reach the native field');
$assert('<p>x</p>' === $render('<p>x</p>', array('blockName' => 'core/paragraph', 'attrs' => array())), 'Ordinary paragraphs are untouched');

// A field whose current value differs from its default keeps the state-replaying companion.
$dirty = (new HtmlTransformer())->transform('<main><input class="field" placeholder="Name" value="" data-blocks-engine-capture-native-control-state="' . $state('typed', '') . '"></main>')->toArray();
$assert(str_contains($dirty['serialized_blocks'], 'authored-input'), 'Captured current state stays replayable');
echo "Core native text input regression passed\n";
