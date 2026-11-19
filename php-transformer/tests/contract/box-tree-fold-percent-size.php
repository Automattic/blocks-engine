<?php
declare(strict_types=1);

// A box folded into a child with the same border box hands the child its own
// `%` width, proven against the containing block the child now shares, not
// the pixels each capture froze. sdnunca's case-study cards: a `width:91.72%`
// grid item folded into its container kept the 402px capture's 368.703px,
// 11px too wide at 390px.
require __DIR__ . '/../../vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

$base = json_decode((string) file_get_contents(__DIR__ . '/../fixtures/box-tree/index.json'), true, 32, JSON_THROW_ON_ERROR);
$initial = array(
    'overflow-x' => 'visible', 'overflow-y' => 'visible', 'mix-blend-mode' => 'normal', 'position' => 'static', 'box-sizing' => 'content-box',
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
// Parent width at each capture; the box is 90% of it.
$parentWidths = array('desktop' => 1000.0, 'mobile' => 402.0);

$html = '<!doctype html><html data-blocks-engine-node-id="html"><head data-blocks-engine-node-id="head"><title data-blocks-engine-node-id="title">Fold</title>'
    . '<style>.fold-parent{display:grid}.fold-box{width:90%;justify-self:start}</style></head>'
    . '<body data-blocks-engine-node-id="body"><main class="fold-parent" data-blocks-engine-node-id="parent">'
    . '<div class="fold-box" data-blocks-engine-node-id="box"><p data-blocks-engine-node-id="child">Folded child</p></div>'
    . '<p data-blocks-engine-node-id="sibling">Sibling</p></main></body></html>';
$tree = $base;
$tree['source_hash'] = hash('sha256', $html);
$tree['viewports']['mobile']['width'] = 402;
$tree['nodes'] = array();
$layout = array('html' => array('head', 'body'), 'head' => array('title'), 'title' => array(), 'body' => array('parent'),
    'parent' => array('box', 'sibling'), 'box' => array('child'), 'child' => array(), 'sibling' => array());
foreach ($layout as $id => $children) {
    $node = array('children' => $children, 'viewports' => array());
    foreach ($base['nodes']['n1']['viewports'] as $viewport => $observation) {
        $parent = $parentWidths[$viewport];
        $width = in_array($id, array('box', 'child'), true) ? round($parent * 0.9, 3) : $parent;
        $observation['used_styles'] = array_replace($observation['used_styles'], $initial, array('width' => $width . 'px', 'height' => '100px'), match ($id) {
            'parent' => array('display' => 'grid', 'grid-template-columns' => $parent . 'px'),
            'box' => array('justify-self' => 'start'),
            default => array(),
        });
        $observation['border_box'] = array('x' => 0, 'y' => 'sibling' === $id ? 100 : 0, 'width' => $width, 'height' => 100);
        $node['viewports'][$viewport] = $observation;
    }
    $tree['nodes'][$id] = $node;
}

$assert = static function (bool $ok, string $message, string $output = ''): void {
    if (!$ok) throw new RuntimeException($message . "\n" . $output);
    echo "PASS: $message\n";
};

$result = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array('index.html' => $html, 'box-tree/index.json' => json_encode($tree))))->toArray();
$node = $result['source_reports']['box_tree']['index.html']['classification']['nodes']['box'];
$markup = $result['serialized_blocks'];
$assert('folded' === $node['disposition'], 'the box folds into its child.');
$assert(1 === preg_match('/<!-- wp:paragraph (\{[^\n]*?\}) -->\s*<p[^>]*>Folded child/', $markup, $match), 'the child takes the box\'s place.', $markup);
$classes = array_filter(preg_split('/\s+/', (string) (json_decode($match[1], true)['className'] ?? '')) ?: array(), static fn (string $class): bool => str_starts_with($class, 'be-inline-geometry-'));
$css = implode("\n", array_column($result['assets'] ?? array(), 'content'));
$rules = '';
foreach ($classes as $class) {
    preg_match_all('/(?:@media[^{]*\{)?\.' . preg_quote($class, '/') . '\.' . preg_quote($class, '/') . '\{[^}]*\}/', $css, $found);
    $rules .= implode("\n", $found[0]);
}
$assert(str_contains($rules, 'width:90%'), 'the child carries the box\'s 90% width.', $rules);
$assert(!str_contains($rules, 'width:900px') && !str_contains($rules, 'width:361.8px'), 'no capture\'s frozen width survives.', $rules);
