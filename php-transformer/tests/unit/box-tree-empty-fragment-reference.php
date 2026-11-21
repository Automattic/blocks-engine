<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\BoxTreeClassifier;

// A bare `href="#"` targets the document top, not an element. It must not
// register the empty id and mark every id-less wrapper as referenced.
$base = json_decode(file_get_contents(__DIR__ . '/../fixtures/box-tree/index.json'), true, 32, JSON_THROW_ON_ERROR);
$tree = $base;
$tree['nodes'] = array();
foreach (array('parent', 'wrapper', 'child') as $id) {
    $node = $base['nodes']['n1'];
    $node['children'] = 'parent' === $id ? array('wrapper') : ('wrapper' === $id ? array('child') : array());
    foreach ($node['viewports'] as &$observation) {
        $observation['used_styles'] = array_replace($observation['used_styles'], array('display' => 'block', 'mix-blend-mode' => 'normal'));
    }
    unset($observation);
    $tree['nodes'][$id] = $node;
}
$foldable = array('disposition' => 'neutral', 'criterion' => 'equal-child-geometry');
$anchored = array('disposition' => 'load-bearing', 'criterion' => 'semantics/id/anchor', 'fold_refusal' => 'semantics/id/anchor');
$cases = array(
    'no link, id-less wrapper' => array('', '', $foldable),
    'bare # link, id-less wrapper' => array('<a href="#">Top</a>', '', $foldable),
    'bare # link, unreferenced id' => array('<a href="#">Top</a>', ' id="x"', $foldable),
    'real fragment, id-less wrapper' => array('<a href="#x">X</a>', '', $foldable),
    'real fragment keeps its target' => array('<a href="#x">X</a>', ' id="x"', $anchored),
    'bare # beside real fragment' => array('<a href="#">Top</a><a href="#x">X</a>', ' id="x"', $anchored),
);
foreach ($cases as $name => [$link, $id, $expected]) {
    $html = '<section data-blocks-engine-node-id="parent"><div data-blocks-engine-node-id="wrapper"' . $id . '>'
        . '<p data-blocks-engine-node-id="child">Text</p></div></section>' . $link;
    $actual = BoxTreeClassifier::classify($html, $tree)['nodes']['wrapper'];
    if ($expected !== $actual) throw new RuntimeException($name . ': ' . json_encode($actual));
}
echo "ok\n";
