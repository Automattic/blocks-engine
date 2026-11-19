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
$tree['nodes']['n6']['interaction_states'] = array('hover');
$result = $compile($html, $tree);
if (!str_contains($result['serialized_blocks'], 'preset')) {
    throw new RuntimeException('A stateful contents wrapper must retain its boundary.');
}
echo "display:contents wrapper contract passed\n";
