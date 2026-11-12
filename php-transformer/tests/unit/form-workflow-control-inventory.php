<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\FormControlTopologyBuilder;

// #5801: hidden workflow fields and captured unrendered peers must share
// the same index space as the metadata rows providers receive.
$wizard = '<form class="quote-form">'
    . '<section class="quote-step" data-quote-step="1"><label class="quote-field">Trip<select name="tripType"><option>Umrah</option><option>Hajj</option></select></label><button type="button" data-next>Next</button></section>'
    . '<section class="quote-step" data-quote-step="2" hidden><label>Name<input name="name" required></label><label>Email<input type="email" name="email" required></label></section>'
    . '<section class="quote-step" data-quote-step="3" hidden><label>Requirements<textarea name="requirements"></textarea></label><button type="submit">Send</button></section></form>';
$captured = '<form><div><div><input name="name"></div><div><input name="other" data-blocks-engine-capture-native-control-state=\'{"version":1,"kind":"input","rendered":false,"type":"text","value":"","defaultValue":"","checked":false,"defaultChecked":false,"indeterminate":false}\'></div></div></form>';
$cases = array(
    'issue wizard' => array($wizard, array('tripType', 'name', 'email', 'requirements')),
    'captured peer' => array($captured, array('name', 'other')),
);
foreach ($cases as $label => [$html, $names]) {
    $result = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array('index.html' => '<main>' . $html . '</main>')))->toArray();
    $findings = array_values(array_filter($result['fallbacks'] ?? array(), static fn (array $row): bool => 'html_form_fallback' === ($row['diagnostic_code'] ?? null)));
    $finding = $findings[0] ?? array();
    if (array_diff($names, array_column($finding['controls'] ?? array(), 'name'))) {
        throw new RuntimeException($label . ': missing workflow controls');
    }
    $count = count($finding['controls']);
    FormControlTopologyBuilder::assertValid($finding['control_topology'], $count);
    FormControlTopologyBuilder::assertSiblingRelations($finding['sibling_relations'], $count);
    // Layout control IDs must resolve to the same semantic source element.
    foreach ($finding['layout_graph']['nodes'] ?? array() as $node) {
        if ('control' !== ($node['kind'] ?? null)) continue;
        $index = $node['control'] ?? (int) substr($node['id'], strlen('control-'));
        if (($node['selector'] ?? null) !== ($finding['controls'][$index]['selector'] ?? null)) {
            throw new RuntimeException($label . ': layout control index drift');
        }
    }
    foreach ($finding['presentation_graph']['controls'] ?? array() as $row) {
        if (!isset($finding['controls'][$row['index']])) {
            throw new RuntimeException($label . ': presentation control index drift');
        }
    }
    $entities = array();
    foreach ($result['source_reports']['wordpress_site_plan']['runtime_declarations'] ?? array() as $declaration) {
        if ('forms' === ($declaration['type'] ?? null)) $entities = array_merge($entities, $declaration['payload']['entities'] ?? array());
    }
    if (1 !== count($entities)) throw new RuntimeException($label . ': compiler must declare the form');
    if (str_contains(json_encode($result, JSON_THROW_ON_ERROR), 'control_topology_invalid')) {
        throw new RuntimeException($label . ': unexpected topology decline');
    }
}
// Explicit inventories can include candidates that control() rejects. The
// returned element list must compact them exactly as the metadata does.
$document = new DOMDocument();
$document->loadHTML('<form><input name="first"><span>Not a control</span><label for="last">Last</label><input id="last" name="last"></form>');
$form = $document->getElementsByTagName('form')->item(0);
$candidates = array($form->getElementsByTagName('input')->item(0), $form->getElementsByTagName('span')->item(0), $form->getElementsByTagName('input')->item(1));
$metadata = (new \Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Elements\FormControlMetadataBuilder())->controls($form, array(), $candidates, $emitted);
if (2 !== count($metadata) || array($candidates[0], $candidates[2]) !== $emitted) {
    throw new RuntimeException('Rejected metadata candidate must not occupy a control index');
}
$topology = new FormControlTopologyBuilder();
FormControlTopologyBuilder::assertValid($topology->build($form, $emitted), count($metadata));
FormControlTopologyBuilder::assertSiblingRelations($topology->directLabelControlPairs($form, $emitted), count($metadata));
echo "Form workflow control inventory: passed\n";
