<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\FormLayoutGraphBuilder;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\FormPresentationGraphBuilder;

$build = static function (string $children, string $css = ''): array {
    $document = new DOMDocument();
    $document->loadHTML('<form><div class="consent" style="display:flex;gap:8px">' . $children . '</div></form>', LIBXML_NOERROR | LIBXML_NOWARNING);
    $form = $document->getElementsByTagName('form')->item(0);
    return (new FormLayoutGraphBuilder())->build($form, array(), $css);
};
$assert = static function (bool $ok, string $message): void {
    if (! $ok) throw new RuntimeException($message);
};

$control = '<input type="checkbox" id="consent">';
$label = '<label for="consent">Yes, I want news</label>';
foreach (array(false, true) as $before) {
    // The label-after case is the exact issue fixture.
    $graph = $build($before ? $label . $control : $control . $label);
    $nodes = array_column($graph['nodes'], null, 'id');
    $wrapper = $nodes['wrapper-0'];
    $children = array_values(array_filter($graph['nodes'], static fn (array $node): bool => ($node['parent'] ?? null) === 'wrapper-0'));
    $assert(array_column($children, 'kind') === ($before ? array('label', 'control') : array('control', 'label')), 'Both flex items must be identified in source order');
    $assert(array_column($children, 'order') === array(0, 1), 'Sibling order must match the source');
    $assert(($nodes['label-0']['for_control'] ?? null) === 'control-0', 'Label must link to its control');
    $assert($wrapper['in_flow_children'] === 2 && $wrapper['layout']['display'] === 'flex' && $wrapper['layout']['gap'] === '8px', 'Wrapper layout must survive');
    $assert(isset($nodes['label-0']['presentation']), 'Label must carry resolved presentation even without own layout');
    FormLayoutGraphBuilder::assertValid($graph);
}

$graph = $build($control . $label, 'label { color: red; order: 2; }');
$nodes = array_column($graph['nodes'], null, 'id');
$assert(($nodes['label-0']['layout']['order'] ?? null) === '2', 'Label layout facts must be resolved');
FormPresentationGraphBuilder::assertElement($nodes['label-0']['presentation']);

// Text-field labels retain the same association and intra-field spacing facts.
$graph = $build('<label for="email">Email</label><input id="email" type="email">');
$nodes = array_column($graph['nodes'], null, 'id');
$assert(($nodes['label-0']['for_control'] ?? null) === 'control-0', 'Text-field association must survive');
$assert(($nodes['control-0']['layout']['margin_block_start'] ?? null) === '8px', 'Text-field label/control spacing must survive');

foreach (array('control-99', 'wrapper-0', null) as $invalid) {
    $bad = $graph;
    foreach ($bad['nodes'] as &$node) {
        if ($node['kind'] === 'label') $node['for_control'] = $invalid;
    }
    unset($node);
    try {
        FormLayoutGraphBuilder::assertValid($bad);
        throw new RuntimeException('Invalid label link accepted');
    } catch (InvalidArgumentException $expected) {
        // Label references must resolve to a sibling control, including forward references.
    }
}
echo "form layout wrapper label passed\n";
