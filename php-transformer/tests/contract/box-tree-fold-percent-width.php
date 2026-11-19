<?php
declare(strict_types=1);

// A folded box whose child fills it hands its author `%` width to the child:
// the child now sits in the box's containing block, so the captured pixels of
// the widest capture would overflow every narrower viewport (a Wix rich-text
// wrapper `width:54.9%` folded into its heading kept 790px at 768px).
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

$html = '<!doctype html><html data-blocks-engine-node-id="html"><head data-blocks-engine-node-id="head"><title data-blocks-engine-node-id="title">Fold</title>'
    . '<style>.fold-parent{display:block}.fold-box{width:50%;margin-left:5%}</style></head>'
    . '<body data-blocks-engine-node-id="body"><main class="fold-parent" data-blocks-engine-node-id="parent">'
    . '<div class="fold-box" data-blocks-engine-node-id="box"><h2 data-blocks-engine-node-id="child">Folded heading</h2></div>'
    . '<p data-blocks-engine-node-id="sibling">Sibling</p></main></body></html>';
$tree = $base;
$tree['source_hash'] = hash('sha256', $html);
$tree['nodes'] = array();
$layout = array('html' => array('head', 'body'), 'head' => array('title'), 'title' => array(), 'body' => array('parent'),
    'parent' => array('box', 'sibling'), 'box' => array('child'), 'child' => array(), 'sibling' => array());
foreach ($layout as $id => $children) {
    $node = array('children' => $children, 'viewports' => array());
    foreach ($base['nodes']['n1']['viewports'] as $viewport => $observation) {
        $width = (float) $base['viewports'][$viewport]['width'];
        $box = array('x' => 0.05 * $width, 'y' => 0, 'width' => 0.5 * $width, 'height' => 40);
        $styles = match ($id) {
            'box' => array('width' => (0.5 * $width) . 'px', 'height' => '40px', 'margin-left' => (0.05 * $width) . 'px'),
            'child' => array('width' => (0.5 * $width) . 'px', 'height' => '40px', 'font-size' => '24px'),
            default => array('width' => $width . 'px', 'height' => '40px'),
        };
        $observation['used_styles'] = array_replace($observation['used_styles'], $initial, $styles);
        $observation['border_box'] = in_array($id, array('box', 'child'), true) ? $box : array('x' => 0, 'y' => 0, 'width' => $width, 'height' => 40);
        $node['viewports'][$viewport] = $observation;
    }
    $tree['nodes'][$id] = $node;
}

$assert = static function (bool $ok, string $message): void {
    if (!$ok) throw new RuntimeException($message);
    echo "PASS: $message\n";
};

$result = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array('index.html' => $html, 'box-tree/index.json' => json_encode($tree))))->toArray();
$node = $result['source_reports']['box_tree']['index.html']['classification']['nodes']['box'];
$assert('folded' === $node['disposition'], 'the width-only wrapper folds into its heading.');
$markup = $result['serialized_blocks'];
$assert(1 === preg_match('/<!-- wp:heading (\{[^\n]*?\}) -->/', $markup, $match), 'the heading takes the box\'s place.');
$attrs = json_decode($match[1], true);
$classes = array_filter(preg_split('/\s+/', (string) ($attrs['className'] ?? '')) ?: array());
$css = implode("\n", array_map(static fn (array $asset): string => (string) ($asset['content'] ?? ''), $result['assets'] ?? array()));
$rules = '';
foreach ($classes as $class) {
    if (preg_match_all('/\.' . preg_quote($class, '/') . '\.' . preg_quote($class, '/') . '\{([^}]*)\}/', $css, $found)) $rules .= implode(';', $found[1]) . ';';
}
$assert(str_contains($rules, 'width:50%'), 'the heading carries the box\'s author width as a percentage.');
$assert(!str_contains($rules, 'width:720px') && !str_contains($rules, 'width:195px'), 'the heading does not keep a captured pixel width.');
$assert(str_contains($rules, 'margin') && str_contains($rules, '5%'), 'the heading carries the box\'s author margin.');
