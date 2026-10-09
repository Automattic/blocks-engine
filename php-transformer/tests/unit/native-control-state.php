<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;
use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Generators\AuthoredControlState;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Support\NativeControlState;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\WordPressSitePlanResolver;

$count = 0;
$assert = static function (bool $condition, string $message) use (&$count): void { ++$count; if (!$condition) throw new RuntimeException($message); };
$marker = static fn (array $state): string => ' data-dla-native-control-state="' . htmlspecialchars(json_encode($state, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8') . '"';
$input = array('version' => 1, 'kind' => 'input', 'type' => 'checkbox', 'value' => 'on', 'defaultValue' => '', 'checked' => true, 'defaultChecked' => false, 'indeterminate' => false);
$select = array('version' => 1, 'kind' => 'select', 'options' => array(array('selected' => false, 'defaultSelected' => true), array('selected' => true, 'defaultSelected' => false)));
$textarea = array('version' => 1, 'kind' => 'textarea', 'value' => "\nCurrent café 🐴 <&\"", 'defaultValue' => "\nDefault β");
$source = '<main><form id="owner" action="/search"><input type="checkbox" id="choice" data-passive="yes"' . $marker($input) . '><label for="choice" data-caption="yes">Choice</label>'
    . '<select id="select"' . $marker($select) . '><optgroup label="Unicode β"><option selected>Alpha</option><option>Beta</option></optgroup></select></form>'
    . '<textarea id="text" form="owner" readonly' . $marker($textarea) . '>Default β</textarea>'
    . '<input id="file" form="owner" type="file"' . $marker(array('version' => 1, 'kind' => 'file')) . '></main>';
$result = (new HtmlTransformer())->transform($source)->toArray();
$flatten = static function (array $blocks) use (&$flatten): array { $out = array(); foreach ($blocks as $block) { $out[] = $block; $out = array_merge($out, $flatten($block['innerBlocks'] ?? array())); } return $out; };
$blocks = $flatten($result['blocks']);
$byId = array(); foreach ($blocks as $block) if (isset($block['attrs']['id'])) $byId[$block['attrs']['id']] = $block;
$assert(array() === $result['fallbacks'], 'typed native controls have no fallback');
$assert('custom/authored-native-form' === $byId['owner']['blockName'], 'admitted GET form keeps native ownership of its typed controls');
$assert('owner' === $byId['text']['attrs']['form'] && 'owner' === $byId['file']['attrs']['form'], 'externally owned typed controls keep their native form owner');
$attrs = $byId['choice']['attrs'];
$assert(false === $attrs['checked'] && true === $attrs['initialChecked'], 'current checked and authored default remain distinct');
$assert(!str_contains($result['serialized_blocks'], NativeControlState::ATTRIBUTE), 'known producer facts are adapted, not retained as a second field registry');
$assert('yes' === $attrs['dataAttributes']['data-passive'], 'passive attributes remain on the native input');
$assert($byId['text']['attrs']['value'] === $textarea['defaultValue'] && $byId['text']['attrs']['initialValue'] === $textarea['value'], 'readonly textarea carries exact default/current LF and Unicode');
$assert('file' === $byId['file']['attrs']['type'] && '' === $byId['file']['attrs']['initialValue'], 'file control stays file without selections');
$assert('custom/authored-select' === $byId['select']['blockName'] && true === $byId['select']['attrs']['options'][0]['selected'] && false === $byId['select']['attrs']['options'][0]['initialSelected'], 'native select retains distinct default/current option state');
$document = new DOMDocument(); $document->loadHTML($result['serialized_blocks'], LIBXML_NOERROR | LIBXML_NOWARNING);
$xpath = new DOMXPath($document);
$assert(1 === $xpath->query('//input[@id="choice"]/following-sibling::*[1][self::label][@for="choice"]')->length, 'associated label stays on its independent clickable source host');
$assert(!$document->getElementById('choice')->hasAttribute('checked'), 'checked property is not substituted into authored default markup');
$assert('yes' === $xpath->query('//label[@for="choice"]')->item(0)->getAttribute('data-caption'), 'label passive attributes remain on the same native host');
$definitions = $result['source_reports']['generated_blocks'];
foreach ($definitions as $definition) if (in_array($definition['name'], array('authored-input', 'authored-select', 'authored-textarea'), true)) {
    $assert('file:./view.js' === $definition['block_json']['viewScript'], 'existing authored block declares its owned view asset');
    $assert(AuthoredControlState::viewScript() === $definition['view_js'], 'all control blocks share the fixed codec/interpreter');
}
foreach (array(array_replace($input, array('checked' => 'true')), array_replace($input, array('type' => 'radio')), $input + array('property' => 'onclick')) as $invalid) {
    $doc = new DOMDocument(); $doc->loadHTML('<input type="checkbox"' . $marker($invalid) . '>');
    $assert(null === NativeControlState::attributes($doc->getElementsByTagName('input')->item(0)), 'invalid or open payload is rejected before adaptation');
}
$sourceWithReplay = $source . '<script data-dla-native-control-runtime>window.capturedReplayExecuted=true;document.querySelector("input[data-dla-native-control-state]").checked=true;</script>';
$artifact = (new ArtifactCompiler())->compile(array('block_namespace' => 'neutral', 'files' => array('index.html' => $sourceWithReplay)))->toArray();
$payload = $artifact['source_reports']['companion_plugin_payload'];
$assert(array() === $payload['preserved_js'], 'no captured source executable is needed by companion payload');
$assert(!str_contains($artifact['serialized_blocks'], 'capturedReplayExecuted'), 'even a forged producer interpreter is discarded rather than trusted/copied');
$assert(!str_contains(json_encode($artifact['assets']), 'capturedReplayExecuted'), 'producer replay code is not emitted as a source JS asset');
$replayPlan = $artifact['source_reports']['wordpress_site_plan'];
$assert(array() === $replayPlan['pages'][0]['document_metadata']['scripts'], 'discarded producer replay code is not declared as a page script');
$assert('proven' === $replayPlan['reference_semantics']['dynamic_client_assets']['status'], 'discarded producer replay code leaves dynamic client assets proven');
(new WordPressSitePlanResolver())->resolve($replayPlan, array('theme_uri' => 'https://example.test/theme', 'require_proven_dynamic_client_assets' => true));
$sourceWithScripts = '<script>window.before=1;</script>' . $sourceWithReplay . '<script>window.after=1;</script>';
$scriptsPlan = (new ArtifactCompiler())->compile(array('block_namespace' => 'neutral', 'files' => array('index.html' => $sourceWithScripts)))->toArray()['source_reports']['wordpress_site_plan'];
$assert(2 === count($scriptsPlan['pages'][0]['document_metadata']['scripts']) && 'proven' === $scriptsPlan['reference_semantics']['dynamic_client_assets']['status'], 'scripts around the discarded replay keep their own bound assets');
$assert('<textarea>&lt;script data-dla-native-control-runtime&gt;text&lt;/script&gt;</textarea>' === NativeControlState::withoutReplayScript('<textarea>&lt;script data-dla-native-control-runtime&gt;text&lt;/script&gt;</textarea>'), 'source-preserving scan keeps script examples in textarea context');
$assert(!str_contains(AuthoredControlState::viewScript(), 'eval(') && !str_contains(AuthoredControlState::viewScript(), 'new Function'), 'owned interpreter has no dynamic code execution');
// A1: typed state never admits a form; submission semantics alone decide native ownership.
$text = static fn (string $type, string $value, string $default): array => array('version' => 1, 'kind' => 'input', 'type' => $type, 'value' => $value, 'defaultValue' => $default, 'checked' => false, 'defaultChecked' => false, 'indeterminate' => false);
$typedControls = '<input name="n"' . $marker($text('text', 'Ann', '')) . '><input type="email" name="e"' . $marker($text('email', 'a@b.c', '')) . '>'
    . '<textarea name="m"' . $marker(array('version' => 1, 'kind' => 'textarea', 'value' => 'Hi', 'defaultValue' => '')) . '></textarea><button type="submit">Send</button>';
foreach (array('post' => '<form method="post" action="/contact.php">', 'unspecified' => '<form>') as $case => $open) {
    $provider = (new HtmlTransformer())->transform('<main>' . $open . $typedControls . '</form></main>')->toArray();
    $assert(!str_contains($provider['serialized_blocks'], 'authored-native-form'), "{$case} form with typed controls is not admitted as a native GET form");
    $finding = array_values(array_filter($provider['fallbacks'], static fn (array $fallback): bool => 'form_requires_runtime' === ($fallback['reason'] ?? '')))[0] ?? array();
    $assert('materialize_detected_form_with_form_provider' === ($finding['actionability'] ?? ''), "{$case} typed form stays available to provider materialization");
    $states = array_column($finding['controls'] ?? array(), 'native_state');
    $assert(3 === count($states) && 'Ann' === $states[0]['value'] && 'Hi' === $states[2]['value'], "{$case} provider control contract carries validated typed current/default state");
}

// A2: the parser-dropped newline after <textarea> is not part of the value.
$textareaBlock = static function (string $html) use ($flatten): array {
    foreach ($flatten((new HtmlTransformer())->transform('<main>' . $html . '</main>')->toArray()['blocks']) as $block) if (str_ends_with($block['blockName'] ?? '', '/authored-textarea')) return $block;
    throw new RuntimeException('textarea did not lower');
};
$untyped = $textareaBlock("<textarea class=\"t\" style=\"height:40px\">\nhello</textarea>");
$assert('hello' === $untyped['attrs']['value'] && str_contains($untyped['innerHTML'], '>hello</textarea>'), 'untyped textarea drops exactly the parser-ignored leading LF');
$assert(!array_key_exists('dataAttributes', $untyped['attrs']) && !str_contains($untyped['innerHTML'], AuthoredControlState::ATTRIBUTE), 'untyped textarea keeps base attributes without an empty object or state payload');
$kept = $textareaBlock("<textarea class=\"t\" style=\"height:40px\">\n\nkeep</textarea>");
$assert("\nkeep" === $kept['attrs']['value'] && str_contains($kept['innerHTML'], ">\n\nkeep</textarea>"), 'a genuine leading LF survives through the serialization guard');
$typedLf = $textareaBlock('<textarea' . $marker(array('version' => 1, 'kind' => 'textarea', 'value' => "\nNow", 'defaultValue' => "\nDefault")) . ">\nDefault</textarea>");
$assert("\nDefault" === $typedLf['attrs']['value'] && "\nNow" === $typedLf['attrs']['initialValue'] && str_contains($typedLf['innerHTML'], ">\n\nDefault</textarea>"), 'typed default/current LF semantics stay exact');

// No-op typed state needs no payload; native markup already reproduces it.
$noop = (new HtmlTransformer())->transform('<main><input id="same" value="v"' . $marker($text('text', 'v', 'v')) . '><textarea id="same-area"' . $marker(array('version' => 1, 'kind' => 'textarea', 'value' => 'x', 'defaultValue' => 'x')) . '>x</textarea></main>')->toArray();
$assert(!str_contains($noop['serialized_blocks'], AuthoredControlState::ATTRIBUTE) && !str_contains($noop['serialized_blocks'], 'initialValue'), 'typed control whose current equals default emits no state payload');

// A3: editing a radio's checked state through the registered editor clears same-owner peers.
$inputEditor = (new Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Generators\AuthoredInputBlockGenerator())->definition('custom')['assets']['index.js'];
$radio = static fn (string $id, array $extra = array()): array => array_merge(array('type' => 'radio', 'id' => $id, 'name' => 'shared'), $extra);
$editorBlocks = array(
    array('clientId' => 'form-1', 'name' => 'custom/authored-native-form', 'attributes' => array('id' => 'first'), 'parent' => null),
    array('clientId' => 'a', 'name' => 'custom/authored-input', 'attributes' => $radio('a', array('checked' => true, 'initialChecked' => false)), 'parent' => 'form-1'),
    array('clientId' => 'b', 'name' => 'custom/authored-input', 'attributes' => $radio('b', array('checked' => false, 'initialChecked' => true)), 'parent' => 'form-1'),
    array('clientId' => 'external', 'name' => 'custom/authored-input', 'attributes' => $radio('external', array('form' => 'first', 'checked' => true)), 'parent' => null),
    array('clientId' => 'form-2', 'name' => 'custom/authored-native-form', 'attributes' => array('id' => 'second'), 'parent' => null),
    array('clientId' => 'other', 'name' => 'custom/authored-input', 'attributes' => $radio('other', array('checked' => true)), 'parent' => 'form-2'),
    array('clientId' => 'checkbox', 'name' => 'custom/authored-input', 'attributes' => array('type' => 'checkbox', 'id' => 'checkbox', 'name' => 'shared', 'checked' => true), 'parent' => 'form-1'),
);
$editorRunner = <<<'JS'
const vm = require('node:vm');
const [script, blocksJson, target] = process.argv.slice(1);
const blocks = JSON.parse(blocksJson); const byId = Object.fromEntries(blocks.map(block => [block.clientId, block]));
let definition;
const store = {
  getBlockParents: id => { const parents = []; for (let parent = byId[id].parent; parent; parent = byId[parent].parent) parents.unshift(parent); return parents; },
  getBlockName: id => byId[id]?.name ?? null,
  getBlockAttributes: id => byId[id]?.attributes ?? null,
  getClientIdsWithDescendants: () => blocks.map(block => block.clientId),
};
const dispatch = { updateBlockAttributes: (id, next) => { byId[id].attributes = { ...byId[id].attributes, ...next }; } };
const RawHTML = function RawHTML() {};
const createElement = (type, props, ...children) => type === RawHTML ? children[0] : { type, props: { ...(props || {}), children } };
const component = name => { const fn = function () {}; fn.displayName = name; return fn; };
vm.runInNewContext(Buffer.from(script, 'base64').toString(), { window: { wp: {
  blocks: { registerBlockType: (name, settings) => { definition = settings; } },
  blockEditor: { RichText: component('RichText'), InspectorControls: component('InspectorControls') },
  components: { PanelBody: component('PanelBody'), TextControl: component('TextControl'), SelectControl: component('SelectControl'), ToggleControl: component('ToggleControl') },
  element: { createElement, RawHTML, Fragment: 'Fragment' },
  data: { select: () => store, dispatch: () => dispatch },
} } });
const block = byId[target];
const tree = definition.edit({ clientId: target, name: block.name, attributes: block.attributes, setAttributes: next => dispatch.updateBlockAttributes(target, next) });
const find = node => { if (!node || typeof node !== 'object') return null; if (node.props?.label === 'Default checked') return node; for (const child of [].concat(node.props?.children || [])) { const hit = find(child); if (hit) return hit; } return null; };
find(tree).props.onChange(true);
process.stdout.write(JSON.stringify(blocks.filter(item => item.name.endsWith('/authored-input')).map(item => ({ id: item.clientId, attributes: item.attributes, saved: definition.save({ attributes: item.attributes }) }))));
JS;
$edited = json_decode((string) shell_exec('node -e ' . escapeshellarg($editorRunner) . ' ' . escapeshellarg(base64_encode($inputEditor)) . ' ' . escapeshellarg(json_encode($editorBlocks, JSON_THROW_ON_ERROR)) . ' a'), true);
$assert(is_array($edited), 'registered authored-input editor runs against a block-editor store');
$edited = array_column($edited, null, 'id');
$checkedState = static function (string $saved): ?bool {
    if (1 !== preg_match('/data-blocks-engine-control-state="([^"]+)"/', $saved, $match)) return str_contains($saved, ' checked');
    return json_decode(html_entity_decode($match[1], ENT_QUOTES, 'UTF-8'), true)['checked'];
};
$assert(true === $edited['a']['attributes']['checked'] && true === $edited['a']['attributes']['initialChecked'] && true === $checkedState($edited['a']['saved']), 'edited radio is the saved default and current choice');
foreach (array('b', 'external') as $peer) {
    $assert(false === $edited[$peer]['attributes']['checked'] && false === $edited[$peer]['attributes']['initialChecked'] && false === $checkedState($edited[$peer]['saved']) && !str_contains($edited[$peer]['saved'], ' checked'), "same-owner radio peer {$peer} no longer wins on save/frontend/reopen");
}
$assert(true === $edited['other']['attributes']['checked'] && true === $edited['checkbox']['attributes']['checked'], 'radios in another form owner and non-radio controls keep their state');

if ($path = getenv('NATIVE_CONTROL_RESULT')) file_put_contents($path, json_encode(array('result' => $artifact, 'fragment' => $result), JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
echo "Native control state: {$count} assertions passed\n";
