<?php
declare(strict_types=1);

// A builder root (Wix's `_root_1w52m_1`) is a one-cell grid placed in its
// section, holding one container that fills it: the container's grid lines
// name the root's only cell and the root's `max-width: 99999px` bounds the
// border box they share. A site-wide focus ring rule
// (`:not(.has-custom-focus):focus`) names no box, so it is not a reason to
// keep every box on the page. The root folds into its container instead of
// emitting a Group per section column.
require __DIR__ . '/../../vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

$base = json_decode((string) file_get_contents(__DIR__ . '/../fixtures/box-tree/index.json'), true, 32, JSON_THROW_ON_ERROR);
$initial = array(
    'overflow-x' => 'visible', 'overflow-y' => 'visible', 'mix-blend-mode' => 'normal', 'box-sizing' => 'border-box',
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

$assert = static function (bool $ok, string $message): void {
    if (!$ok) throw new RuntimeException($message);
    echo "PASS: $message\n";
};

$compile = static function (string $focusRule) use ($base, $initial, $box): array {
    $html = '<!doctype html><html data-blocks-engine-node-id="html"><head data-blocks-engine-node-id="head"><title data-blocks-engine-node-id="title">Root</title>'
        . '<style data-blocks-engine-node-id="style">' . $focusRule . '{box-shadow:0 0 0 2px #116dff}</style></head>'
        . '<body data-blocks-engine-node-id="body"><main class="section" data-blocks-engine-node-id="parent">'
        . '<div class="grid-root" data-blocks-engine-node-id="box"><div class="grid-container" data-blocks-engine-node-id="child"><p data-blocks-engine-node-id="text">Column text</p></div></div>'
        . '<p data-blocks-engine-node-id="sibling">Sibling</p></main></body></html>';
    $styles = array(
        'parent' => array('display' => 'grid', 'grid-template-columns' => '400px', 'grid-template-rows' => '100px 20px'),
        'box' => array('display' => 'grid', 'position' => 'relative', 'grid-template-columns' => '400px', 'grid-template-rows' => '100px',
            'grid-row-start' => '1', 'grid-row-end' => '2', 'min-width' => '0px', 'min-height' => '0px', 'max-width' => '99999px', 'max-height' => '99999px'),
        'child' => array('display' => 'grid', 'position' => 'relative', 'grid-template-columns' => '400px', 'grid-template-rows' => '80px',
            'grid-row-start' => '1', 'grid-row-end' => '2', 'grid-column-start' => '1', 'grid-column-end' => '2', 'padding-bottom' => '20px'),
    );
    $tree = $base;
    $tree['source_hash'] = hash('sha256', $html);
    $tree['nodes'] = array();
    $layout = array('html' => array('head', 'body'), 'head' => array('title', 'style'), 'title' => array(), 'style' => array(), 'body' => array('parent'),
        'parent' => array('box', 'sibling'), 'box' => array('child'), 'child' => array('text'), 'text' => array(), 'sibling' => array());
    foreach ($layout as $id => $children) {
        $node = array('children' => $children, 'viewports' => array());
        foreach ($base['nodes']['n1']['viewports'] as $viewport => $observation) {
            $observation['used_styles'] = array_replace($observation['used_styles'], $initial, $styles[$id] ?? array());
            $observation['border_box'] = match ($id) {
                'text' => array('x' => 0, 'y' => 0, 'width' => 400, 'height' => 80),
                'sibling' => array('x' => 0, 'y' => 100, 'width' => 400, 'height' => 20),
                default => $box,
            };
            $node['viewports'][$viewport] = $observation;
        }
        $tree['nodes'][$id] = $node;
    }
    return (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array('index.html' => $html, 'box-tree/index.json' => json_encode($tree))))->toArray();
};

// The focus ring Wix ships on every page: it names whichever element is focused.
$result = $compile('#SITE_CONTAINER.focus-ring-active :not(.has-custom-focus):not(.ignore-focus):not([tabindex="-1"]):focus');
$classification = $result['source_reports']['box_tree']['index.html']['classification'];
$node = $classification['nodes']['box'];
$markup = $result['serialized_blocks'];
$assert('folded' === $node['disposition'] && in_array('own-placement', $node['fold'] ?? array(), true), 'the one-cell grid root folds into the container that fills it.');
$assert(!str_contains($markup, 'grid-root'), 'the root emits no block.');
$assert(2 === substr_count($markup, '<!-- wp:group '), 'only the section and the container remain groups.');
$assert(str_contains($markup, 'grid-container') && str_contains($markup, 'Column text'), 'the container takes the root\'s place with its content.');
$assert(str_contains($markup, 'Sibling'), 'the sibling is untouched.');

// A focus rule that names the root still keeps it: its state styling needs its box.
$result = $compile('.grid-root:focus-within');
$node = $result['source_reports']['box_tree']['index.html']['classification']['nodes']['box'];
$assert('load-bearing' === $node['disposition'] && !isset($node['fold']), 'a state rule naming the root keeps it load-bearing.');
