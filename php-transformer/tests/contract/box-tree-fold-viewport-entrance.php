<?php
declare(strict_types=1);

// A box whose only load-bearing reason is a viewport-entrance capture marker
// hands the entrance to a sole child whose border box is the box's at every
// captured viewport: the child takes the marker, the box's id (so `#id`
// motion rules and the entrance runtime still find it) and its inline motion
// custom properties, and the box emits no block. An unequal child, a child
// with motion of its own, or any other capture marker keeps the group.
require __DIR__ . '/../../vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

$base = json_decode((string) file_get_contents(__DIR__ . '/../fixtures/box-tree/index.json'), true, 32, JSON_THROW_ON_ERROR);
$initial = array(
    'overflow-x' => 'visible', 'overflow-y' => 'visible', 'mix-blend-mode' => 'normal',
    'padding-top' => '0px', 'padding-right' => '0px', 'padding-bottom' => '0px', 'padding-left' => '0px',
    'margin-top' => '0px', 'margin-right' => '0px', 'margin-bottom' => '0px', 'margin-left' => '0px',
    'border-top' => '0px none rgb(0, 0, 0)', 'border-right' => '0px none rgb(0, 0, 0)', 'border-bottom' => '0px none rgb(0, 0, 0)',
    'border-left' => '0px none rgb(0, 0, 0)', 'border-radius' => '0px', 'outline' => 'rgb(0, 0, 0) none 0px',
);
$entrance = 'data-blocks-engine-capture-viewport-entrance="' . htmlspecialchars('{"attributes":{"data-motion-enter":{"before":null,"after":"done"}}}') . '"';
$cases = array(
    array('name' => 'equal-box entrance', 'marker' => $entrance, 'fold' => true),
    array('name' => 'unequal child box', 'marker' => $entrance, 'child_box' => array('height' => 80)),
    array('name' => 'child with its own animation', 'marker' => $entrance, 'child' => array('animation-name' => 'pulse')),
    array('name' => 'dialog marker', 'marker' => 'data-blocks-engine-capture-dialog-panel="panel"'),
);

$assert = static function (bool $ok, string $message): void {
    if (!$ok) throw new RuntimeException($message);
    echo "PASS: $message\n";
};

foreach ($cases as $case) {
    $name = $case['name'];
    $html = '<!doctype html><html data-blocks-engine-node-id="html"><head data-blocks-engine-node-id="head"><title data-blocks-engine-node-id="title">Entrance</title>'
        . '<style data-blocks-engine-node-id="style">@keyframes fade{from{opacity:0;transform:rotate(var(--motion-rotate))}to{opacity:1}}'
        . '#comp-entrance:not([data-motion-enter="done"]){opacity:0}#comp-entrance[data-motion-enter="done"]{animation:fade 1s}</style></head>'
        . '<body data-blocks-engine-node-id="body"><main data-blocks-engine-node-id="parent">'
        . '<div id="comp-entrance" class="entrance-box" style="--motion-rotate:12deg" ' . $case['marker'] . ' data-blocks-engine-node-id="box"><p data-blocks-engine-node-id="child">Entrance text</p></div>'
        . '<p data-blocks-engine-node-id="sibling">Sibling</p></main></body></html>';
    $tree = $base;
    $tree['source_hash'] = hash('sha256', $html);
    $tree['nodes'] = array();
    $layout = array('html' => array('head', 'body'), 'head' => array('title', 'style'), 'title' => array(), 'style' => array(), 'body' => array('parent'),
        'parent' => array('box', 'sibling'), 'box' => array('child'), 'child' => array(), 'sibling' => array());
    foreach ($layout as $id => $children) {
        $node = array('children' => $children, 'viewports' => array());
        foreach ($base['nodes']['n1']['viewports'] as $viewport => $observation) {
            $observation['used_styles'] = array_replace($observation['used_styles'], $initial, 'child' === $id ? ($case['child'] ?? array()) : array());
            $observation['border_box'] = array_replace(array('x' => 0, 'y' => 'sibling' === $id ? 100 : 0, 'width' => 400, 'height' => 100), 'child' === $id ? ($case['child_box'] ?? array()) : array());
            $node['viewports'][$viewport] = $observation;
        }
        $tree['nodes'][$id] = $node;
    }
    $result = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array('index.html' => $html, 'box-tree/index.json' => json_encode($tree))))->toArray();
    $classification = $result['source_reports']['box_tree']['index.html']['classification'];
    $node = $classification['nodes']['box'];
    $markup = $result['serialized_blocks'];
    $assert('capture-marker' === $node['criterion'], $name . ': the box is load-bearing through its capture marker.');
    if (empty($case['fold'])) {
        $assert('load-bearing' === $node['disposition'] && !isset($node['fold']), $name . ': the box stays load-bearing.');
        $assert(0 === $classification['fold_counts']['viewport-entrance'], $name . ': no entrance fold is counted.');
        $assert(1 === preg_match('/<!-- wp:group \{[^\n]*?"anchor":"comp-entrance"/', $markup), $name . ': the box keeps its group and id.');
        continue;
    }
    $assert('folded' === $node['disposition'] && array('viewport-entrance') === $node['fold'], $name . ': the box folds its entrance.');
    $assert(1 === $classification['fold_counts']['viewport-entrance'], $name . ': the entrance fold is counted.');
    $assert(!str_contains($markup, 'entrance-box') && 1 === substr_count($markup, '<!-- wp:group '), $name . ': only the parent remains a group.');
    $assert(1 === preg_match('/<!-- wp:paragraph (\{[^\n]*?\}) -->\s*<p id="comp-entrance"[^>]*>Entrance text/', $markup, $match), $name . ': the child paragraph takes the box\'s id.');
    $attrs = json_decode($match[1], true);
    $assert('comp-entrance' === ($attrs['anchor'] ?? null), $name . ': the paragraph anchors the id.');
    $evidence = json_decode((string) ($attrs['metadata']['blocksEngineRuntimeAttributes']['data-blocks-engine-capture-viewport-entrance'] ?? ''), true);
    $assert(is_array($evidence) && array_key_exists('data-motion-enter', $evidence['attributes'] ?? array()), $name . ': the paragraph carries the entrance marker.');
    $css = implode("\n", array_map(static fn (array $asset): string => (string) ($asset['content'] ?? ''), $result['assets'] ?? array()));
    $carried = false;
    foreach (array_filter(preg_split('/\s+/', (string) ($attrs['className'] ?? '')) ?: array()) as $class) {
        if (preg_match('/\.' . preg_quote($class, '/') . '\{[^}]*--motion-rotate:12deg/', $css)) $carried = true;
    }
    $assert($carried, $name . ': the paragraph carries the motion custom property.');
    $assert(str_contains($css, '#comp-entrance[data-motion-enter="done"]') && str_contains($css, '#comp-entrance:not([data-motion-enter="done"])'), $name . ': the entrance state rules still match the id on the paragraph.');
    $assert(str_contains($markup, 'Sibling'), $name . ': the sibling is untouched.');
}
