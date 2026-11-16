<?php
declare(strict_types=1);
require __DIR__ . '/../../vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\Support\HtmlSerialization;

$base = json_decode(file_get_contents(__DIR__ . '/../fixtures/box-tree/index.json'), true, 32, JSON_THROW_ON_ERROR);
$cases = json_decode(file_get_contents(__DIR__ . '/../fixtures/box-tree/classification-cases.json'), true, 32, JSON_THROW_ON_ERROR);
$assert = static function (bool $ok, string $message): void {
    if (!$ok) throw new RuntimeException($message);
    echo "PASS: $message\n";
};
foreach ($cases as $case) {
    $html = '<html data-blocks-engine-node-id="html"><head data-blocks-engine-node-id="head"></head><body data-blocks-engine-node-id="body"><div data-blocks-engine-node-id="wrapper" ' . ($case['attributes'] ?? '') . '><img data-blocks-engine-node-id="image" src="image.png">' . (!empty($case['second_child']) ? '<img data-blocks-engine-node-id="second" src="image.png">' : '') . '</div></body></html>';
    if (!empty($case['chain'])) {
        $open = '';
        for ($i = 1; $i <= 11; ++$i) $open .= '<div data-blocks-engine-node-id="chain' . $i . '">';
        $html = str_replace('<img data-blocks-engine-node-id="image" src="image.png">', $open . '<img data-blocks-engine-node-id="image" src="image.png">' . str_repeat('</div>', 11), $html);
    }
    if (isset($case['reference'])) $html = str_replace('src="image.png"', 'src="image.png" ' . $case['reference'], $html);
    $tree = $base;
    if (!empty($case['desktop_only'])) unset($tree['viewports']['mobile']);
    $tree['source_hash'] = hash('sha256', $html);
    $tree['nodes'] = array();
    foreach (HtmlSerialization::parseDocument($html)->getElementsByTagName('*') as $element) {
        $id = $element->getAttribute('data-blocks-engine-node-id');
        $node = $base['nodes']['n1'];
        if (!empty($case['desktop_only'])) unset($node['viewports']['mobile']);
        $node['children'] = array();
        foreach ($element->childNodes as $child) {
            if ($child instanceof DOMElement) $node['children'][] = $child->getAttribute('data-blocks-engine-node-id');
        }
        foreach ($node['viewports'] as $viewport => &$observation) {
            if ('image' === $id) $observation['border_box'] = array_replace($observation['border_box'], $case['child_box'] ?? array());
            $observation['used_styles']['mix-blend-mode'] = 'normal';
            $observation['used_styles'] = array_replace($observation['used_styles'], 'wrapper' === $id ? ($case['styles'] ?? array()) : ('image' === $id ? ($case['child_styles'] ?? array()) : array()));
            if ('wrapper' === $id && 'mobile' === $viewport && isset($case['mobile_width'])) $observation['border_box']['width'] = $case['mobile_width'];
        }
        unset($observation);
        if ('wrapper' === $id && isset($case['states'])) $node['interaction_states'] = $case['states'];
        $tree['nodes'][$id] = $node;
    }
    $artifact = array('entrypoint' => 'index.html', 'files' => array('index.html' => $html, 'box-tree/index.json' => json_encode($tree)));
    $compiler = new ArtifactCompiler();
    $result = $compiler->compile($artifact)->toArray();
    $report = $result['source_reports']['box_tree']['index.html']['classification'];
    if ('equal-child-geometry' === $case['criterion']) $assert($report['counts']['neutral'] > 0, 'Capture-shaped fixture retains neutral boxes');
    $assert($case['criterion'] === $report['nodes']['wrapper']['criterion'], $case['name'] . ' has the named criterion');
    if (!empty($case['chain'])) $assert(1 === $report['projection']['depth'] && 0 === $report['projection']['groups'], 'Twelve neutral image wrappers project to one leaf');
    $assert(array_sum($report['counts']) === count($tree['nodes']), 'Every source node is counted');
    $assert($report['projection']['depth'] <= 10 && $report['projection']['groups_per_leaf'] <= 1.1, 'Fixture projection meets depth and wrapper budget');
    unset($artifact['files']['box-tree/index.json']);
    $baseline = $compiler->compile($artifact)->toArray();
    $assert(count($baseline['blocks']) === count($result['blocks']), 'Restyling retains the classified top-level boxes; collapse is a later slice');
}
