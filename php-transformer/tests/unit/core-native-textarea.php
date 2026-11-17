<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\NativeTextareaRuntime;

// A captured native textarea is an editable core paragraph, rendered natively by the theme.
$assert = static function (bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); };
$state = htmlspecialchars(json_encode(array('version' => 1, 'kind' => 'textarea', 'value' => 'Now & then', 'defaultValue' => "Line <1>\nLine 2")), ENT_QUOTES);
$source = '<form><label class="block"><span class="eyebrow">Upgrade goals</span><textarea id="goals" name="goals" required minlength="3" maxlength="800" class="field min-h-28" placeholder="e.g. more bass" data-hook="yes" data-blocks-engine-capture-native-control-state="' . $state . '">Line &lt;1&gt;' . "\n" . 'Line 2</textarea></label></form>';
$result = (new HtmlTransformer())->transform($source)->toArray();
$flatten = static function (array $blocks) use (&$flatten): array { $out = array(); foreach ($blocks as $block) { $out[] = $block; $out = array_merge($out, $flatten($block['innerBlocks'] ?? array())); } return $out; };
$assert(!str_contains($result['serialized_blocks'], 'authored-textarea'), 'No textarea companion is emitted');
$blocks = array_values(array_filter($flatten($result['blocks']), static fn (array $block): bool => isset($block['attrs']['metadata']['blocksEngineTextarea'])));
$assert(1 === count($blocks) && 'core/paragraph' === $blocks[0]['blockName'], 'Captured textarea must be an editable core paragraph');
$block = $blocks[0];
$assert('Line &lt;1&gt;<br>Line 2' === $block['attrs']['content'], 'Default value is the editable paragraph text');
$assert('e.g. more bass' === ($block['attrs']['placeholder'] ?? ''), 'Editor placeholder follows the native placeholder');

// Run the generated public theme filter with the WordPress escaping surface.
function add_filter($name, $callback, ...$args) { $GLOBALS['textarea_filter'] = array($name, $callback); }
function esc_attr($text) { return htmlspecialchars((string) $text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function esc_html($text) { return esc_attr($text); }
function wp_json_encode($data, $flags = 0) { return json_encode($data, $flags); }
function wp_kses_post($text) { return $text; }
eval(NativeTextareaRuntime::source());
[$hook, $render] = $GLOBALS['textarea_filter'];
$assert('render_block_core/paragraph' === $hook, 'Renderer filters core paragraphs');
$html = $render($block['innerHTML'], $block);
$assert(str_starts_with($html, '<label class="block"><span class="eyebrow">Upgrade goals</span><textarea id="goals" name="goals" placeholder="e.g. more bass" class="field min-h-28" maxlength="800" minlength="3" required data-hook="yes"'), 'Native identity, label, form semantics and hooks survive: ' . $html);
$assert(str_contains($html, ">Line &lt;1&gt;\nLine 2</textarea></label>"), 'Default value renders as native textarea text');
$assert(str_contains($html, 'data-blocks-engine-control-state="{&quot;version&quot;:1,&quot;kind&quot;:&quot;textarea&quot;,&quot;value&quot;:&quot;Now &amp; then&quot;'), 'Current value is replayable without overwriting the default');
$block['innerHTML'] = '<p>Edited &amp; editable</p>';
$assert(str_contains($render($block['innerHTML'], $block), '>Edited &amp; editable</textarea>'), 'Core editor text changes reach native output');
$plain = array('blockName' => 'core/paragraph', 'attrs' => array(), 'innerHTML' => '<p>Prose</p>');
$assert('<p>Prose</p>' === $render('<p>Prose</p>', $plain), 'Ordinary paragraphs are untouched');

// Ordinary styled textareas retain their saved native markup contract.
$ordinary = (new HtmlTransformer())->transform('<form action="/x"><textarea class="t" style="height:40px" name="m">hello</textarea></form>')->toArray();
$assert(!str_contains($ordinary['serialized_blocks'], 'blocksEngineTextarea'), 'Ordinary native textareas keep their existing save contract');
echo "Core native textarea regression passed\n";
