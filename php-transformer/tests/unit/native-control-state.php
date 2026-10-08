<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;
use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Generators\AuthoredControlState;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Support\NativeControlState;

$count = 0;
$assert = static function (bool $condition, string $message) use (&$count): void { ++$count; if (!$condition) throw new RuntimeException($message); };
$marker = static fn (array $state): string => ' data-dla-native-control-state="' . htmlspecialchars(json_encode($state, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8') . '"';
$input = array('version' => 1, 'kind' => 'input', 'type' => 'checkbox', 'value' => 'on', 'defaultValue' => '', 'checked' => true, 'defaultChecked' => false, 'indeterminate' => false);
$select = array('version' => 1, 'kind' => 'select', 'options' => array(array('selected' => false, 'defaultSelected' => true), array('selected' => true, 'defaultSelected' => false)));
$textarea = array('version' => 1, 'kind' => 'textarea', 'value' => "\nCurrent café 🐴 <&\"", 'defaultValue' => "\nDefault β");
$source = '<main><form id="owner"><input type="checkbox" id="choice" data-passive="yes"' . $marker($input) . '><label for="choice" data-caption="yes">Choice</label>'
    . '<select id="select"' . $marker($select) . '><optgroup label="Unicode β"><option selected>Alpha</option><option>Beta</option></optgroup></select>'
    . '<textarea id="text" readonly' . $marker($textarea) . '>Default β</textarea>'
    . '<input id="file" type="file"' . $marker(array('version' => 1, 'kind' => 'file')) . '></form></main>';
$result = (new HtmlTransformer())->transform($source)->toArray();
$flatten = static function (array $blocks) use (&$flatten): array { $out = array(); foreach ($blocks as $block) { $out[] = $block; $out = array_merge($out, $flatten($block['innerBlocks'] ?? array())); } return $out; };
$blocks = $flatten($result['blocks']);
$byId = array(); foreach ($blocks as $block) if (isset($block['attrs']['id'])) $byId[$block['attrs']['id']] = $block;
$assert(array() === $result['fallbacks'], 'typed native controls have no fallback');
$assert('custom/authored-native-form' === $byId['owner']['blockName'], 'unspecified native form retains ownership rather than becoming a readable/provider form');
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
$assert('<textarea>&lt;script data-dla-native-control-runtime&gt;text&lt;/script&gt;</textarea>' === NativeControlState::withoutReplayScript('<textarea>&lt;script data-dla-native-control-runtime&gt;text&lt;/script&gt;</textarea>'), 'source-preserving scan keeps script examples in textarea context');
$assert(!str_contains(AuthoredControlState::viewScript(), 'eval(') && !str_contains(AuthoredControlState::viewScript(), 'new Function'), 'owned interpreter has no dynamic code execution');
if ($path = getenv('NATIVE_CONTROL_RESULT')) file_put_contents($path, json_encode(array('result' => $artifact, 'fragment' => $result), JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
echo "Native control state: {$count} assertions passed\n";
