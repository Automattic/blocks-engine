<?php
declare(strict_types=1);

require __DIR__ . '/../../vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

$html = '<!doctype html><html data-blocks-engine-node-id="n1"><head data-blocks-engine-node-id="n2"><title data-blocks-engine-node-id="n3">Contents</title><style>.preset{display:contents}.text{margin-left:20px}</style></head><body data-blocks-engine-node-id="n4"><main data-blocks-engine-node-id="n5"><div class="preset" data-blocks-engine-node-id="n6"><p class="text" data-blocks-engine-node-id="n7">Editable text</p></div></main></body></html>';
$tree = json_decode(file_get_contents(__DIR__ . '/../fixtures/box-tree/index.json'), true, 32, JSON_THROW_ON_ERROR);
$tree['source_hash'] = hash('sha256', $html);
$tree['nodes']['n5']['children'] = array('n6');
$tree['nodes']['n6']['children'] = array('n7');
foreach ($tree['nodes']['n6']['viewports'] as &$observation) {
    $observation['used_styles']['display'] = 'contents';
    $observation['border_box'] = array('x' => 0, 'y' => 0, 'width' => 0, 'height' => 0);
}
unset($observation);
foreach ($tree['nodes']['n7']['viewports'] as &$observation) {
    $observation['used_styles']['margin-left'] = '20px';
}
unset($observation);
$compile = static function (string $source, array $evidence): array {
    return (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array('index.html' => $source, 'box-tree/index.json' => json_encode($evidence))))->toArray();
};
$result = $compile($html, $tree);
if (str_contains($result['serialized_blocks'], '"className":"preset') || !str_contains($result['serialized_blocks'], '<!-- wp:paragraph ')) {
    throw new RuntimeException('A boxless wrapper must not introduce an editor Group around its editable child.');
}
// Preset wrappers often disable hit testing while their sole child restores
// it. The contents wrapper has no hit-test box and adds no runtime boundary.
$pointerHtml = str_replace('.preset{display:contents}.text{margin-left:20px}', '.preset{display:contents;pointer-events:none}.text{margin-left:20px;pointer-events:auto}', $html);
$pointerTree = $tree;
$pointerTree['source_hash'] = hash('sha256', $pointerHtml);
foreach ($pointerTree['nodes']['n6']['viewports'] as &$observation) {
    $observation['used_styles']['pointer-events'] = 'none';
}
unset($observation);
foreach ($pointerTree['nodes']['n7']['viewports'] as &$observation) {
    $observation['used_styles']['pointer-events'] = 'auto';
}
unset($observation);
$pointerResult = $compile($pointerHtml, $pointerTree);
$pointerNode = $pointerResult['source_reports']['box_tree']['index.html']['classification']['nodes']['n6'];
if ('neutral' !== $pointerNode['disposition'] || str_contains($pointerResult['serialized_blocks'], '<!-- wp:group {"className":"preset')) {
    throw new RuntimeException('A boxless disabled pointer ancestor must not add a Group around its hit-testable child.');
}
if (!str_contains($pointerResult['serialized_blocks'], 'Editable text') || !str_contains($pointerResult['serialized_blocks'], '<!-- wp:paragraph ')) {
    throw new RuntimeException('Pointer restoration must remain on editable native text.');
}
// Removing the editor Group must not rebase the child's style projection
// onto the outer flex container. Compare its public serialized block with the same
// contents boundary retained for interaction.
foreach ($tree['nodes']['n5']['viewports'] as &$observation) {
    $observation['used_styles']['display'] = 'flex';
}
unset($observation);
foreach ($tree['nodes']['n7']['viewports'] as &$observation) {
    $observation['used_styles']['flex-grow'] = '2';
}
unset($observation);
$reduced = $compile($html, $tree);
$tree['nodes']['n6']['interaction_states'] = array('hover');
$result = $compile($html, $tree);
if (!str_contains($result['serialized_blocks'], 'preset')) {
    throw new RuntimeException('A stateful contents wrapper must retain its boundary.');
}
preg_match('/<!-- wp:paragraph .*?<!-- \/wp:paragraph -->/s', $reduced['serialized_blocks'], $reducedChild);
preg_match('/<!-- wp:paragraph .*?<!-- \/wp:paragraph -->/s', $result['serialized_blocks'], $retainedChild);
if (empty($reducedChild[0]) || $reducedChild[0] !== ($retainedChild[0] ?? null)) {
    throw new RuntimeException('Removing a contents Group must not restyle its child relative to the outer flex container.');
}
// Custom hosts use the transparent-hook path, not ordinary box removal.
// In particular their child must keep the same styling context it had before
// the generic contents-wrapper reduction was introduced.
$customHtml = str_replace(array('<div class="preset"', '</div>'), array('<layout-host class="preset"', '</layout-host>'), $html);
$tree['nodes']['n6']['interaction_states'] = array();
$tree['source_hash'] = hash('sha256', $customHtml);
$result = $compile($customHtml, $tree);
$node = $result['source_reports']['box_tree']['index.html']['classification']['nodes']['n6'];
if ('neutral' === $node['disposition']) {
    throw new RuntimeException('A custom contents host must retain its dedicated lowering and descendant styling context.');
}
echo "display:contents wrapper contract passed\n";
