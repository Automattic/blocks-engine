<?php
declare(strict_types=1);

// Exercises box-tree decisions, which are opt-in (#7352).
putenv('BLOCKS_ENGINE_BOX_TREE_INTAKE=1');

// ADR 0005 slice 5: when a folded box keeps its block because its child's
// block cannot carry the box's declarations, the child no longer receives
// them. A captured dialog trigger (an icon link that opens a dialog) lowers to
// a runtime authored button whose own box-tree rule is projected from the
// observations it received from the fold. Once the box keeps its block, that
// rule must describe the trigger's own box again: otherwise the box's margins
// and grid placement render twice, once on the box and once on the trigger.
require __DIR__ . '/../../vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

$base = json_decode((string) file_get_contents(__DIR__ . '/../fixtures/box-tree/index.json'), true, 32, JSON_THROW_ON_ERROR);
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

$assert = static function (bool $ok, string $message): void {
    if (!$ok) throw new RuntimeException($message);
    echo "PASS: $message\n";
};

$html = '<!doctype html><html data-blocks-engine-node-id="html"><head data-blocks-engine-node-id="head"><title data-blocks-engine-node-id="title">Fold</title></head>'
    . '<body data-blocks-engine-node-id="body"><header class="fold-parent" data-blocks-engine-node-id="parent">'
    . '<div class="fold-box" data-blocks-engine-node-id="box">'
    . '<a class="icon-link" href="/cart/" role="button" aria-label="Cart with 0 items" aria-haspopup="dialog" aria-expanded="false" aria-controls="cart-panel" data-blocks-engine-capture-dialog-trigger="cart-panel" data-blocks-engine-node-id="child">'
    . '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="100%" height="100%" data-blocks-engine-node-id="icon"><path d="M2 2h20v20H2z"/></svg>'
    . '</a></div>'
    . '<p data-blocks-engine-node-id="sibling">Sibling</p></header>'
    . '<div class="cart-panel" role="dialog" hidden id="cart-panel" data-blocks-engine-capture-dialog-panel="cart-panel" data-blocks-engine-node-id="panel"><p data-blocks-engine-node-id="panel-text">Your cart is empty</p></div>'
    . '<script>document.querySelector("[data-blocks-engine-capture-dialog-trigger]").addEventListener("click",function(e){e.preventDefault();document.getElementById("cart-panel").hidden=false;});</script></body></html>';
// The box sits in the parent's grid cell, offset by its own margins; the
// trigger fills the box exactly and has no margins of its own.
$box = array('x' => 300, 'y' => 20, 'width' => 40, 'height' => 40);
$tree = $base;
$tree['source_hash'] = hash('sha256', $html);
$tree['nodes'] = array();
$layout = array('html' => array('head', 'body'), 'head' => array('title'), 'title' => array(), 'body' => array('parent', 'panel'),
    'panel' => array('panel-text'), 'panel-text' => array(),
    'parent' => array('box', 'sibling'), 'box' => array('child'), 'child' => array('icon'), 'icon' => array(), 'sibling' => array());
foreach ($layout as $id => $children) {
    $node = array('children' => $children, 'viewports' => array());
    foreach ($base['nodes']['n1']['viewports'] as $viewport => $observation) {
        $observation['used_styles'] = array_replace($observation['used_styles'], $initial, match ($id) {
            'parent' => array('display' => 'grid', 'grid-template-columns' => '400px'),
            'box' => array('position' => 'relative', 'grid-row-start' => '1', 'grid-row-end' => '2', 'grid-column-start' => '1', 'grid-column-end' => '2', 'align-self' => 'start', 'justify-self' => 'start', 'margin-top' => '20px', 'margin-bottom' => '20px', 'margin-left' => '300px', 'width' => '40px', 'height' => '40px'),
            'child' => array('width' => '40px', 'height' => '40px'),
            'icon' => array('display' => 'inline', 'width' => '40px', 'height' => '40px'),
            default => array(),
        });
        $observation['border_box'] = match ($id) {
            'parent' => array('x' => 0, 'y' => 0, 'width' => 400, 'height' => 80),
            'sibling' => array('x' => 0, 'y' => 80, 'width' => 400, 'height' => 20),
            default => $box,
        };
        $node['viewports'][$viewport] = $observation;
    }
    $tree['nodes'][$id] = $node;
}
$result = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array('index.html' => $html, 'box-tree/index.json' => json_encode($tree))))->toArray();
$classification = $result['source_reports']['box_tree']['index.html']['classification'];
$markup = $result['serialized_blocks'];
$css = implode("\n", array_map(static fn (array $asset): string => (string) ($asset['content'] ?? ''), $result['assets'] ?? array()));

// Every declaration the given block classes resolve to in the emitted CSS.
$declarations = static function (string $className) use ($css): string {
    $found = '';
    foreach (array_filter(preg_split('/\s+/', $className) ?: array()) as $class) {
        if (!str_starts_with($class, 'be-')) continue;
        preg_match_all('/\.' . preg_quote($class, '/') . '(?:\.' . preg_quote($class, '/') . ')?\{([^}]*)\}/', $css, $rules);
        $found .= implode(';', $rules[1]) . ';';
    }
    return $found;
};

$assert('folded' === $classification['nodes']['box']['disposition'], 'the box is classified folded into its icon link.');
$assert(1 === preg_match('/<!-- wp:group (\{(?:(?!-->).)*?"className":"fold-box[^"]*"(?:(?!-->).)*?\}) -->/', $markup, $boxMatch), 'the box keeps its block when the trigger cannot carry its declarations.');
$assert(1 === preg_match('/<!-- wp:[a-z-]+\/authored-button (\{"tagName":"a"(?:(?!-->).)*?\}) \/?-->/', $markup, $triggerMatch), 'the dialog trigger converts as an authored button.');
$boxRules = $declarations((string) (json_decode($boxMatch[1], true)['className'] ?? ''));
$triggerRules = $declarations((string) (json_decode($triggerMatch[1], true)['className'] ?? ''));
$assert(1 === preg_match('/margin-left:[^;]*300px/', $boxRules) && 1 === preg_match('/(?:place|justify)-self:start/', $boxRules), 'the kept box carries its own margins and placement.');
$assert(0 === preg_match('/margin-(?:top|bottom|left):[^;]*(?:20|300)px/', $triggerRules), 'the trigger does not repeat the box\'s margins.');
$assert(0 === preg_match('/(?:place|justify)-self:start/', $triggerRules) && !str_contains($triggerRules, 'grid-row:1 / 2') && !str_contains($triggerRules, 'grid-column:1 / 2'), 'the trigger does not repeat the box\'s grid placement.');
$assert(1 === preg_match('/fold-box.*authored-button \{"tagName":"a"/s', $markup), 'the dialog trigger stays inside the kept box.');
