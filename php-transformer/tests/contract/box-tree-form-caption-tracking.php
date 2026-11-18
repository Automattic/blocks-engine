<?php
declare(strict_types=1);

require __DIR__ . '/../../vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

// The provider replaces a button and flattens its sole caption carrier.
// Tracking inherited by that carrier must survive without author stylesheets.
$html = file_get_contents(__DIR__ . '/../fixtures/box-tree/index.html');
$tree = json_decode(file_get_contents(__DIR__ . '/../fixtures/box-tree/index.json'), true, 32, JSON_THROW_ON_ERROR);
$html = str_replace('<p data-blocks-engine-node-id="n7">Evidence only.</p>',
    '<form action="/subscribe" method="post" data-blocks-engine-node-id="n7"><input type="email" name="email" data-blocks-engine-node-id="n8"><button type="submit" data-blocks-engine-node-id="n9"><span data-blocks-engine-node-id="n10">Sign Up</span></button></form>', $html);
$tree['source_hash'] = hash('sha256', $html);
foreach (array('n8', 'n9', 'n10') as $id) {
    $tree['nodes'][$id] = $tree['nodes']['n7'];
    $tree['nodes'][$id]['children'] = array();
}
$tree['nodes']['n7']['children'] = array('n8', 'n9');
$tree['nodes']['n9']['children'] = array('n10');
foreach ($tree['nodes']['n10']['viewports'] as &$viewport) $viewport['used_styles']['letter-spacing'] = '2px';
unset($viewport);
$result = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array('index.html' => $html, 'box-tree/index.json' => json_encode($tree))))->toArray();
$controls = array();
array_walk_recursive($result, static function ($value, $key) use (&$controls): void {
    if ('letterSpacing' === $key) $controls[] = $value;
});
if (!in_array('2px', $controls, true)) throw new RuntimeException('The flattened form caption lost its captured tracking.');
echo "PASS: Flattened form captions carry captured tracking.\n";
