<?php
declare(strict_types=1);

// ADR 0005 slice 5: a single-child box that is load-bearing only through its
// own declarations folds them into its child, proved by geometry at every
// captured viewport. One fold and one refusal per rule.
require __DIR__ . '/../../vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

$base = json_decode((string) file_get_contents(__DIR__ . '/../fixtures/box-tree/index.json'), true, 32, JSON_THROW_ON_ERROR);
// Computed-style serializations a browser reports for an unstyled block box.
$initial = array(
    'overflow-x' => 'visible', 'overflow-y' => 'visible', 'mix-blend-mode' => 'normal',
    'padding-top' => '0px', 'padding-right' => '0px', 'padding-bottom' => '0px', 'padding-left' => '0px',
    'margin-top' => '0px', 'margin-right' => '0px', 'margin-bottom' => '0px', 'margin-left' => '0px',
    'row-gap' => 'normal', 'column-gap' => 'normal', 'flex-direction' => 'row', 'flex-wrap' => 'nowrap',
    'justify-content' => 'normal', 'align-items' => 'normal', 'align-content' => 'normal',
    'flex-grow' => '0', 'flex-shrink' => '1', 'flex-basis' => 'auto', 'align-self' => 'auto', 'order' => '0',
    'grid-template-columns' => 'none', 'grid-template-rows' => 'none', 'grid-auto-columns' => 'auto', 'grid-auto-rows' => 'auto',
    'grid-auto-flow' => 'row', 'grid-column-start' => 'auto', 'grid-column-end' => 'auto', 'grid-row-start' => 'auto',
    'grid-row-end' => 'auto', 'justify-items' => 'normal', 'justify-self' => 'auto',
    'border-top' => '0px none rgb(0, 0, 0)', 'border-right' => '0px none rgb(0, 0, 0)', 'border-bottom' => '0px none rgb(0, 0, 0)',
    'border-left' => '0px none rgb(0, 0, 0)', 'border-radius' => '0px', 'outline' => 'rgb(0, 0, 0) none 0px',
    'min-width' => 'auto', 'min-height' => 'auto', 'max-width' => 'none', 'max-height' => 'none',
);
$box = array('x' => 0, 'y' => 0, 'width' => 400, 'height' => 100);
$inset = array('x' => 20, 'y' => 20, 'width' => 360, 'height' => 60);
$cases = array(
    array('rule' => 'own-sizing', 'parent' => array('display' => 'flex', 'flex-direction' => 'column'),
        'box' => array('padding-top' => '20px', 'padding-right' => '20px', 'padding-bottom' => '20px', 'padding-left' => '20px'),
        'child_box' => $inset, 'expect' => array('margin-top' => '20px')),
    // A vertical margin could collapse in a block parent where padding could not.
    array('rule' => 'own-sizing', 'refuse' => true, 'parent' => array('display' => 'block'),
        'box' => array('padding-top' => '20px', 'padding-right' => '20px', 'padding-bottom' => '20px', 'padding-left' => '20px'),
        'child_box' => $inset),
    array('rule' => 'paint', 'box' => array('background-color' => 'rgb(255, 0, 0)', 'box-shadow' => 'rgb(0, 0, 0) 0px 2px 4px 0px'),
        'expect' => array('background-color' => 'rgb(255, 0, 0)', 'box-shadow' => 'rgb(0, 0, 0) 0px 2px 4px 0px')),
    array('rule' => 'paint', 'refuse' => true, 'box' => array('background-color' => 'rgb(255, 0, 0)'),
        'child' => array('background-color' => 'rgb(0, 0, 255)')),
    // The badge inside the child is placed against the box's padding box.
    array('rule' => 'containing-block', 'box' => array('position' => 'relative'), 'badge' => array('position' => 'absolute', 'top' => '0px', 'right' => '0px'),
        'expect' => array('position' => 'relative')),
    // An absolute child is placed against the box itself.
    array('rule' => 'containing-block', 'refuse' => true, 'box' => array('position' => 'relative'), 'child' => array('position' => 'absolute')),
    array('rule' => 'own-placement', 'parent' => array('display' => 'grid', 'grid-template-columns' => '400px'),
        'box' => array('grid-row-start' => '1', 'grid-row-end' => '2', 'grid-column-start' => '1', 'grid-column-end' => '2', 'justify-self' => 'start'),
        'expect' => array('grid-row' => '1 / 2', 'justify-self' => 'start')),
    array('rule' => 'own-placement', 'refuse' => true, 'parent' => array('display' => 'grid', 'grid-template-columns' => '400px'),
        'box' => array('grid-row-start' => '1', 'justify-self' => 'start'), 'child' => array('justify-self' => 'end')),
);

$assert = static function (bool $ok, string $message): void {
    if (!$ok) throw new RuntimeException($message);
    echo "PASS: $message\n";
};

foreach ($cases as $case) {
    $name = $case['rule'] . (empty($case['refuse']) ? ' folds' : ' refuses');
    $html = '<!doctype html><html data-blocks-engine-node-id="html"><head data-blocks-engine-node-id="head"><title data-blocks-engine-node-id="title">Fold</title></head>'
        . '<body data-blocks-engine-node-id="body"><main class="fold-parent" data-blocks-engine-node-id="parent">'
        . '<div class="fold-box" data-blocks-engine-node-id="box"><p data-blocks-engine-node-id="child">Folded child <span data-blocks-engine-node-id="badge">!</span></p></div>'
        . '<p data-blocks-engine-node-id="sibling">Sibling</p></main></body></html>';
    $tree = $base;
    $tree['source_hash'] = hash('sha256', $html);
    $tree['nodes'] = array();
    $layout = array('html' => array('head', 'body'), 'head' => array('title'), 'title' => array(), 'body' => array('parent'),
        'parent' => array('box', 'sibling'), 'box' => array('child'), 'child' => array('badge'), 'badge' => array(), 'sibling' => array());
    foreach ($layout as $id => $children) {
        $node = array('children' => $children, 'viewports' => array());
        foreach ($base['nodes']['n1']['viewports'] as $viewport => $observation) {
            $observation['used_styles'] = array_replace($observation['used_styles'], $initial, match ($id) {
                'parent' => $case['parent'] ?? array(),
                'box' => $case['box'],
                'child' => $case['child'] ?? array(),
                'badge' => array_replace(array('display' => 'inline'), $case['badge'] ?? array()),
                default => array(),
            });
            $observation['border_box'] = match ($id) {
                'child' => $case['child_box'] ?? $box,
                'badge' => array('x' => 380, 'y' => 0, 'width' => 20, 'height' => 20),
                default => $box,
            };
            $node['viewports'][$viewport] = $observation;
        }
        $tree['nodes'][$id] = $node;
    }
    $result = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array('index.html' => $html, 'box-tree/index.json' => json_encode($tree))))->toArray();
    $classification = $result['source_reports']['box_tree']['index.html']['classification'];
    $node = $classification['nodes']['box'];
    $markup = $result['serialized_blocks'];
    if (!empty($case['refuse'])) {
        $assert('load-bearing' === $node['disposition'] && !isset($node['fold']), $name . ': the box stays load-bearing.');
        $assert(0 === $classification['fold_counts'][$case['rule']], $name . ': no fold is counted.');
        $assert(str_contains($markup, 'fold-box'), $name . ': the box still emits its block.');
        continue;
    }
    $assert('folded' === $node['disposition'] && in_array($case['rule'], $node['fold'], true), $name . ': the box is classified folded.');
    $assert(1 === $classification['fold_counts'][$case['rule']] && 1 === $classification['counts']['folded'], $name . ': the fold is counted per rule.');
    $assert(!str_contains($markup, 'fold-box'), $name . ': the box emits no block.');
    $assert(1 === substr_count($markup, '<!-- wp:group '), $name . ': only the parent remains a group.');
    $assert(1 === preg_match('/<!-- wp:paragraph (\{[^\n]*?\}) -->\s*<p[^>]*>Folded child/', $markup, $match), $name . ': the child takes the box\'s place.');
    $attrs = json_decode($match[1], true);
    $css = implode("\n", array_map(static fn (array $asset): string => (string) ($asset['content'] ?? ''), $result['assets'] ?? array()));
    $classes = array_filter(preg_split('/\s+/', (string) ($attrs['className'] ?? '')) ?: array());
    foreach ($case['expect'] as $property => $value) {
        $declared = false;
        foreach ($classes as $class) {
            if (preg_match('/\.' . preg_quote($class, '/') . '\.' . preg_quote($class, '/') . '\{[^}]*' . preg_quote($property . ':' . $value, '/') . '/', $css)) $declared = true;
        }
        // A viewport-invariant value the block supports serializes as a block attribute instead.
        $declared = $declared || str_contains($match[1], '"' . $value . '"');
        $assert($declared, $name . ': the child carries the moved ' . $property . '.');
    }
    $assert(str_contains($markup, 'Sibling'), $name . ': the sibling is untouched.');
}
