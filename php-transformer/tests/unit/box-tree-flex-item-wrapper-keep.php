<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\BoxTreeClassifier;

$base = json_decode(file_get_contents(__DIR__ . '/../fixtures/box-tree/index.json'), true, 32, JSON_THROW_ON_ERROR);
$cases = array(
    'sole row item fills line' => array('row', 'stretch', false, 100, '0', 'own-sizing'),
    'row item with sibling' => array('row', 'stretch', true, 100, '0', 'equal-child-geometry'),
    'row item short of line' => array('row', 'stretch', false, 80, '0', 'equal-child-geometry'),
    'growing row item' => array('row', 'stretch', false, 100, '1', 'equal-child-geometry'),
    'centered column item' => array('column', 'center', false, 100, '0', 'own-sizing'),
    'safe centered column item' => array('column', 'safe center', false, 100, '0', 'own-sizing'),
    'stretched column item' => array('column', 'stretch', false, 100, '0', 'equal-child-geometry'),
    'unknown column alignment' => array('column', 'unknown', false, 100, '0', 'equal-child-geometry'),
);
foreach ($cases as $name => [$direction, $align, $sibling, $width, $grow, $criterion]) {
    $html = '<section data-blocks-engine-node-id="parent"><div data-blocks-engine-node-id="wrapper"><img data-blocks-engine-node-id="child" src="image.png"></div>'
        . ($sibling ? '<img data-blocks-engine-node-id="sibling" src="image.png">' : '') . '</section>';
    $tree = $base;
    $tree['nodes'] = array();
    foreach (array('parent', 'wrapper', 'child', 'sibling') as $id) {
        if ('sibling' === $id && !$sibling) continue;
        $node = $base['nodes']['n1'];
        $node['children'] = 'parent' === $id ? ($sibling ? array('wrapper', 'sibling') : array('wrapper')) : ('wrapper' === $id ? array('child') : array());
        foreach ($node['viewports'] as &$observation) {
            $observation['border_box']['width'] = 'parent' === $id ? 100 : $width;
            $observation['used_styles'] = array_replace($observation['used_styles'], array(
                'display' => 'parent' === $id ? 'flex' : 'block',
                'flex-direction' => $direction,
                'flex-grow' => $grow,
                'align-items' => $align,
                'align-self' => 'auto',
                'mix-blend-mode' => 'normal',
            ));
        }
        unset($observation);
        $tree['nodes'][$id] = $node;
    }
    $result = BoxTreeClassifier::classify($html, $tree);
    $expected = array('disposition' => 'own-sizing' === $criterion ? 'load-bearing' : 'neutral', 'criterion' => $criterion);
    if ('own-sizing' === $criterion) $expected['fold_refusal'] = 'reparenting-shrinks';
    if ($expected !== $result['nodes']['wrapper']) throw new RuntimeException($name . ': ' . json_encode($result['nodes']['wrapper']));
    if ('own-sizing' === $criterion) {
        // Exercise fold's guard independently: paint takes precedence over
        // own-sizing, but must not make the wrapper eligible for collapse.
        foreach ($tree['nodes']['wrapper']['viewports'] as &$observation) $observation['used_styles']['background-color'] = 'rgb(255, 0, 0)';
        unset($observation);
        $painted = BoxTreeClassifier::classify($html, $tree);
        if (array('disposition' => 'load-bearing', 'criterion' => 'paints', 'fold_refusal' => 'reparenting-shrinks') !== $painted['nodes']['wrapper']) {
            throw new RuntimeException($name . ': painted wrapper must not fold');
        }
    }
}
echo "ok\n";
