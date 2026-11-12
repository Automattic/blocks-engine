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
    . '<section class="quote-step" data-quote-step="3" hidden><label>Requirements<textarea name="requirements"></textarea></label><input name="website" style="display:none"><div style="display:none"><input name="conditional"></div><button type="submit">Send</button></section></form>';
$captured = '<form><div><div><input name="name"></div><div><input name="other" data-blocks-engine-capture-native-control-state=\'{"version":1,"kind":"input","rendered":false,"type":"text","value":"","defaultValue":"","checked":false,"defaultChecked":false,"indeterminate":false}\'></div></div></form>';
$choiceConfig = htmlspecialchars(json_encode(array(
    'group' => array('label' => 'Travel party'),
    'choices' => array(
        array('index' => 0, 'tag' => 'button', 'label' => 'Solo'),
        array('index' => 1, 'tag' => 'button', 'label' => 'Family'),
    ),
    'states' => array(),
), JSON_THROW_ON_ERROR), ENT_QUOTES);
$wizardWithChoices = str_replace('<label>Name', '<div data-blocks-engine-choice-group="true" data-blocks-engine-choice-config="' . $choiceConfig . '"><button type="button">Solo</button><button type="button">Family</button></div><label>Name', $wizard);
$cases = array(
    'issue wizard' => array($wizard, array('tripType', 'name', 'email', 'requirements')),
    'captured peer' => array($captured, array('name', 'other')),
    'hidden choices' => array($wizardWithChoices, array('tripType', 'name', 'email', 'requirements')),
);
foreach ($cases as $label => [$html, $names]) {
    $result = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array('index.html' => '<main>' . $html . '</main>')))->toArray();
    $findings = array_values(array_filter($result['fallbacks'] ?? array(), static fn (array $row): bool => 'html_form_fallback' === ($row['diagnostic_code'] ?? null)));
    $finding = $findings[0] ?? array();
    if (array_diff($names, array_column($finding['controls'] ?? array(), 'name'))) {
        throw new RuntimeException($label . ': missing workflow controls');
    }
    if ('captured peer' !== $label) {
        foreach ($finding['controls'] as $control) {
            $name = $control['name'] ?? '';
            if (in_array($name, array('name', 'email', 'requirements'), true) || 'submit' === ($control['type'] ?? '')) {
                $panel = in_array($name, array('name', 'email'), true) ? 1 : 2;
                if (isset($control['hidden']) || array('panel' => $panel, 'rendered' => false) !== ($control['workflow_step'] ?? null)) {
                    throw new RuntimeException($label . ': workflow controls must be step gated, not unconditionally hidden');
                }
            }
            if (in_array($name, array('website', 'conditional'), true) && 'none' !== ($control['hidden']['value'] ?? null)) {
                throw new RuntimeException($label . ': declarations inside a step must remain hidden');
            }
        }
        if (array_diff(array('website', 'conditional'), array_column($finding['controls'], 'name'))) {
            throw new RuntimeException($label . ': missing hidden controls inside workflow panel');
        }
    }
    $count = count($finding['controls']);
    $readableIndices = array();
    $walk = static function (array $blocks) use (&$walk, &$readableIndices): void {
        foreach ($blocks as $block) {
            if (isset($block['form_control_index'])) $readableIndices[] = $block['form_control_index'];
            $walk($block['innerBlocks'] ?? array());
        }
    };
    $walk($finding['readable_blocks'] ?? array());
    $hiddenPanels = array();
    $choiceIndices = array();
    $inspect = static function (array $blocks) use (&$inspect, &$hiddenPanels, &$choiceIndices): void {
        foreach ($blocks as $block) {
            foreach ($block['attrs']['wrappers'] ?? array() as $wrapper) {
                $attributes = $wrapper['attributes'] ?? array();
                if (isset($attributes['data-quote-step']) && !empty($attributes['hidden'])) {
                    $hiddenPanels[] = (string) $attributes['data-quote-step'];
                }
            }
            if (isset($block['form_choice_group_index'])) $choiceIndices[] = $block['form_choice_group_index'];
            $inspect($block['innerBlocks'] ?? array());
        }
    };
    $inspect($finding['readable_blocks'] ?? array());
    if ('captured peer' !== $label && array('2', '3') !== $hiddenPanels) {
        throw new RuntimeException($label . ': hidden workflow panel shells must retain their hidden attributes');
    }
    if ('hidden choices' === $label && (array(0) !== $choiceIndices || 'Travel party' !== ($finding['choice_groups'][0]['group']['label'] ?? null))) {
        throw new RuntimeException($label . ': hidden choice group index drift');
    }
    sort($readableIndices);
    if (range(0, $count - 1) !== array_values(array_unique($readableIndices))) {
        throw new RuntimeException($label . ': readable form must cover the full control inventory');
    }
    if (count($readableIndices) !== count(array_unique($readableIndices))) {
        throw new RuntimeException($label . ': readable control indexes must appear exactly once');
    }
    FormControlTopologyBuilder::assertValid($finding['control_topology'], $count);
    FormControlTopologyBuilder::assertSiblingRelations($finding['sibling_relations'], $count);
    // Layout control IDs must resolve to the same semantic source element.
    foreach ($finding['layout_graph']['nodes'] ?? array() as $node) {
        if ('control' !== ($node['kind'] ?? null)) continue;
        $index = $node['control'] ?? (int) substr($node['id'], strlen('control-'));
        if (($node['source']['selector'] ?? $node['selector'] ?? null) !== ($finding['controls'][$index]['selector'] ?? null)) {
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
