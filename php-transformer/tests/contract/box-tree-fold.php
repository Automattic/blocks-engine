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
        'child_box' => $inset, 'expect' => array('padding-top' => '20px')),
    // An equal-box child can share the box's constraint; conflicting ones stay put.
    array('rule' => 'own-sizing', 'box' => array('max-width' => '99999px', 'margin-left' => '20px'),
        'child' => array('max-width' => '99999px', 'box-sizing' => 'border-box'), 'expect' => array('margin-left' => '20px')),
    array('rule' => 'own-sizing', 'refuse' => true, 'refusal' => 'child-own-max-width',
        'box' => array('max-width' => '99999px', 'margin-left' => '20px'), 'child' => array('max-width' => '800px', 'box-sizing' => 'border-box')),
    // Padding stays padding and cannot collapse in a block parent.
    array('rule' => 'own-sizing', 'parent' => array('display' => 'block'),
        'box' => array('padding-top' => '20px', 'padding-right' => '20px', 'padding-bottom' => '20px', 'padding-left' => '20px'),
        'child_box' => $inset),
    array('rule' => 'paint', 'box' => array('background-color' => 'rgb(255, 0, 0)', 'box-shadow' => 'rgb(0, 0, 0) 0px 2px 4px 0px'),
        'expect' => array('background-color' => 'rgb(255, 0, 0)', 'box-shadow' => 'rgb(0, 0, 0) 0px 2px 4px 0px')),
    array('rule' => 'paint', 'refuse' => true, 'box' => array('background-color' => 'rgb(255, 0, 0)'),
        'child' => array('background-color' => 'rgb(0, 0, 255)')),
    // A font size the box owns for its subtree moves with the fold.
    array('rule' => 'paint', 'box' => array('background-color' => 'rgb(255, 0, 0)', 'font-size' => '18px', 'line-height' => '27px'),
        'child' => array('font-size' => '18px', 'line-height' => '27px'),
        'expect' => array('background-color' => 'rgb(255, 0, 0)', 'font-size' => '18px')),
    // A child's own colour does not depend on the box's.
    array('rule' => 'paint', 'box' => array('background-color' => 'rgb(255, 0, 0)', 'color' => 'rgb(0, 128, 0)'),
        'child' => array('color' => 'rgb(0, 0, 255)'), 'expect' => array('background-color' => 'rgb(255, 0, 0)')),
    // A child font size that differs from the box's may be an em of it.
    array('rule' => 'paint', 'refuse' => true, 'box' => array('background-color' => 'rgb(255, 0, 0)', 'font-size' => '18px'),
        'child' => array('font-size' => '27px')),
    // The badge inside the child is placed against the box's padding box.
    array('rule' => 'containing-block', 'box' => array('position' => 'relative'), 'badge' => array('position' => 'absolute', 'top' => '0px', 'right' => '0px'),
        'expect' => array('position' => 'relative')),
    // An equal-box absolute child takes over its positioned box.
    array('rule' => 'containing-block', 'box' => array('position' => 'relative'), 'child' => array('position' => 'absolute'),
        'expect' => array('position' => 'relative')),
    array('rule' => 'own-placement', 'parent' => array('display' => 'grid', 'grid-template-columns' => '400px'),
        'box' => array('grid-row-start' => '1', 'grid-row-end' => '2', 'grid-column-start' => '1', 'grid-column-end' => '2', 'justify-self' => 'start'),
        'expect' => array('grid-row' => '1 / 2', 'justify-self' => 'start')),
    array('rule' => 'own-placement', 'refuse' => true, 'parent' => array('display' => 'grid', 'grid-template-columns' => '400px'),
        'box' => array('grid-row-start' => '1', 'justify-self' => 'start'), 'child' => array('justify-self' => 'end')),
);

// Absolute fills: each refusal changes one part of the equal-box proof.
$positioned = array('position' => 'relative', 'width' => '400px', 'height' => '100px',
    'grid-row-start' => '2', 'grid-column-start' => '1', 'align-self' => 'start', 'justify-self' => 'start');
$absoluteChild = array('position' => 'absolute', 'top' => '0px', 'right' => '0px', 'bottom' => '0px', 'left' => '0px');
foreach (array(
    array(),
    array('box' => array('position' => 'absolute', 'top' => '20px', 'left' => '30px', 'transform' => 'matrix(1, 0, 0, 1, 0, 0)')),
    array('box' => array('background-color' => 'rgb(255, 0, 0)')),
    array('child' => array('left' => '1px'), 'refuse' => true),
    array('child' => array('z-index' => '1'), 'refuse' => true),
    array('box' => array('transform' => 'matrix(1, 0, 0, 1, 1, 0)'), 'refuse' => true),
    array('box' => array('padding-left' => '1px'), 'refuse' => true),
    array('child_box' => $inset, 'refuse' => true),
    // With its only child out of flow, an empty grid box may take its size
    // from its own tracks (a decorative dot sized by a `minmax()` row); the
    // child lays out on tracks of its own and would lose that size.
    array('box' => array('display' => 'grid'), 'allow' => true),
    array('box' => array('display' => 'grid', 'grid-template-rows' => '100px'), 'refuse' => true),
    array('box' => array('display' => 'grid', 'grid-template-columns' => '400px'), 'refuse' => true),
) as $change) {
    $position = $change['box']['position'] ?? 'relative';
    $cases[] = array(
        'rule' => 'containing-block', 'refuse' => $change['refuse'] ?? false,
        'parent' => array('display' => 'grid'),
        'box' => array_replace($positioned, $change['box'] ?? array()),
        'box_style' => 'width:400px;height:100px' . ('absolute' === $position ? ';position:absolute;top:20px;left:30px;transform:matrix(1, 0, 0, 1, 0, 0)' : ''),
        'child' => array_replace($absoluteChild, $change['child'] ?? array()),
        'child_box' => $change['child_box'] ?? $box,
        'expect' => array('position' => $position, 'width' => '400px', 'height' => '100px')
            + ('relative' === $position ? array('grid-row-start' => '2', 'place-self' => 'start') : array('top' => '20px', 'left' => '30px')),
    );
}

// Equal-box background layers: every refusal changes just one piece of evidence.
$layer = array('display' => 'grid', 'position' => 'absolute', 'top' => '0px', 'right' => '0px', 'bottom' => '0px', 'left' => '0px',
    'overflow-x' => 'clip', 'overflow-y' => 'clip', 'grid-template-columns' => '400px', 'grid-template-rows' => '100px');
$underlay = array('position' => 'relative', 'top' => '0px', 'right' => '0px', 'bottom' => '0px', 'left' => '0px',
    'overflow-x' => 'clip', 'overflow-y' => 'clip', 'grid-column-start' => '1', 'grid-row-start' => '1', 'background-color' => 'rgb(255, 128, 0)');
foreach (array(
    array(),
    array('child' => array('align-self' => 'stretch', 'justify-self' => 'stretch'), 'allow' => true),
    array('box' => array('border-radius' => '4.5px'), 'allow' => true, 'expect' => array('border-radius' => '4.5px')),
    array('box' => array('border-radius' => '4.5px'), 'child' => array('overflow-x' => 'visible', 'overflow-y' => 'visible'),
        'allow' => true, 'expect' => array('overflow-x' => 'clip', 'overflow-y' => 'clip', 'border-radius' => '4.5px')),
    array('box' => array('border-radius' => '4.5px'), 'child' => array('border-radius' => '2px')),
    array('box' => array('border-radius' => '4.5px'), 'child' => array('border-top' => '1px solid rgb(0, 0, 0)')),
    array('box' => array('border-radius' => '4.5px', 'border-top' => '1px solid rgb(0, 0, 0)')),
    array('box' => array('top' => '1px')),
    array('child' => array('overflow-x' => 'visible'), 'allow' => true, 'expect' => array('overflow-x' => 'clip')),
    array('child' => array('box-shadow' => 'rgb(0, 0, 0) 0px 2px 4px 0px')),
    array('box' => array('z-index' => '1')),
    array('child' => array('outline' => '1px solid rgb(0, 0, 0)')),
    array('child' => array('transform' => 'matrix(1, 0, 0, 1, 1, 0)')),
    array('child' => array('left' => '1px')),
    array('child_box' => $inset),
    array('box' => array('z-index' => '1'), 'child' => array('z-index' => '1'), 'allow' => true),
) as $change) {
    $cases[] = array(
        'rule' => 'own-placement', 'refuse' => array() !== $change && empty($change['allow']),
        'box' => array_replace($layer, $change['box'] ?? array()),
        'child' => array_replace($underlay, $change['child'] ?? array()),
        'child_box' => $change['child_box'] ?? $box,
        'expect' => array_merge(array('position' => 'absolute', 'top' => '0px', 'right' => '0px', 'bottom' => '0px', 'left' => '0px'), $change['expect'] ?? array()),
    );
}

// Rounded image frames can hand their clip to an equal-box relative child.
$frame = array('position' => 'relative', 'overflow-x' => 'clip', 'overflow-y' => 'clip', 'border-radius' => '4.5px');
foreach (array(
    array(),
    array('child' => array('border-radius' => '4.5px')),
    array('child' => array('position' => 'static')),
    array('badge' => array('position' => 'absolute')),
    array('child' => array('box-shadow' => 'rgb(0, 0, 0) 0px 2px 4px 0px'), 'refuse' => true),
    array('child' => array('outline' => '1px solid rgb(0, 0, 0)'), 'refuse' => true),
    array('child' => array('transform' => 'matrix(1, 0, 0, 1, 1, 0)'), 'refuse' => true),
    array('child' => array('border-radius' => '2px'), 'refuse' => true),
    array('child' => array('position' => 'static'), 'badge' => array('position' => 'absolute'), 'refuse' => true),
    array('child' => array('position' => 'static'), 'badge' => array('position' => 'fixed'), 'refuse' => true),
    array('child_box' => $inset, 'refuse' => true),
    array('child' => array('border-top' => '1px solid rgb(0, 0, 0)'), 'refuse' => true),
) as $change) {
    $cases[] = array(
        'rule' => 'clip', 'box' => $frame,
        'child' => array_replace(array('position' => 'relative'), $change['child'] ?? array()),
        'badge' => $change['badge'] ?? array(),
        'badge_box' => array('x' => 380, 'y' => 0, 'width' => 40, 'height' => 20),
        'child_box' => $change['child_box'] ?? $box,
        'refuse' => $change['refuse'] ?? false,
        'expect' => array('overflow-x' => 'clip', 'overflow-y' => 'clip', 'border-radius' => '4.5px'),
    );
}

$assert = static function (bool $ok, string $message): void {
    if (!$ok) throw new RuntimeException($message);
    echo "PASS: $message\n";
};

foreach ($cases as $case) {
    $name = $case['rule'] . (empty($case['refuse']) ? ' folds' : ' refuses');
    $html = '<!doctype html><html data-blocks-engine-node-id="html"><head data-blocks-engine-node-id="head"><title data-blocks-engine-node-id="title">Fold</title></head>'
        . '<body data-blocks-engine-node-id="body"><main class="fold-parent" data-blocks-engine-node-id="parent">'
        . '<div class="fold-box" style="' . ($case['box_style'] ?? '') . '" data-blocks-engine-node-id="box"><p data-blocks-engine-node-id="child">Folded child <span data-blocks-engine-node-id="badge">!</span></p></div>'
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
                'badge' => $case['badge_box'] ?? array('x' => 380, 'y' => 0, 'width' => 20, 'height' => 20),
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
        if (isset($case['refusal'])) {
            $assert($case['refusal'] === ($node['fold_refusal'] ?? null), $name . ': names the ' . $case['refusal'] . ' refusal.');
        }
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
    if ('absolute' === ($case['box']['position'] ?? null) && 'stretch' === ($case['child']['align-self'] ?? null)) {
        $assert(!str_contains($css, 'align-self:stretch') && !str_contains($css, 'justify-self:stretch'), $name . ': the child drops its former self-alignment.');
    }
    $classes = array_filter(preg_split('/\s+/', (string) ($attrs['className'] ?? '')) ?: array());
    foreach ($case['expect'] as $property => $value) {
        $declared = false;
        foreach ($classes as $class) {
            $declaration = in_array($property, array('overflow-x', 'overflow-y'), true)
                ? '(?:' . preg_quote($property, '/') . '|overflow):' . preg_quote($value, '/')
                : preg_quote($property . ':' . $value, '/');
            if (preg_match('/\.' . preg_quote($class, '/') . '\.' . preg_quote($class, '/') . '\{[^}]*' . $declaration . '/', $css)) $declared = true;
        }
        // A viewport-invariant value the block supports serializes as a block attribute instead.
        $declared = $declared || str_contains($match[1], '"' . $value . '"');
        $assert($declared, $name . ': the child carries the moved ' . $property . '.');
    }
    $assert(str_contains($markup, 'Sibling'), $name . ': the sibling is untouched.');
}
