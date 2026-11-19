<?php
declare(strict_types=1);

// ADR 0005 slice 5: a folded box hands its placement to its sole child. When
// that child filled the box (`width:100%` of a box whose border box it was),
// the box's own fluid width (`width:91.72%` under a breakpoint, as a Wix
// container sets it) is the child's at every width. Freezing the child at the
// captured pixels instead made it, and the image it holds, wider than the
// source at any viewport narrower than the capture (#5870).
require __DIR__ . '/../../vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

$base = json_decode((string) file_get_contents(__DIR__ . '/../fixtures/box-tree/index.json'), true, 32, JSON_THROW_ON_ERROR);
$initial = array(
    'overflow-x' => 'visible', 'overflow-y' => 'visible', 'mix-blend-mode' => 'normal', 'position' => 'static',
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
    'box-sizing' => 'border-box', 'display' => 'block',
);

$assert = static function (bool $ok, string $message, string $detail = ''): void {
    if (!$ok) throw new RuntimeException($message . ('' === $detail ? '' : "\n" . $detail));
    echo "PASS: $message\n";
};

$compile = static function (string $childCss) use ($base, $initial): array {
    $css = '.fold-parent{display:grid;grid-template-columns:minmax(0,1fr)}'
        . '.fold-box{width:50%;justify-self:start;grid-area:1/1/2/2}'
        . '@media (max-width:600px){.fold-box{width:90%}}' . $childCss;
    $html = '<!doctype html><html data-blocks-engine-node-id="html"><head data-blocks-engine-node-id="head"><title data-blocks-engine-node-id="title">Fold</title>'
        . '<style>' . $css . '</style></head>'
        . '<body data-blocks-engine-node-id="body"><main class="fold-parent" data-blocks-engine-node-id="parent">'
        . '<div class="fold-box" data-blocks-engine-node-id="box">'
        . '<div class="fold-child" data-blocks-engine-node-id="child"><p data-blocks-engine-node-id="text">Card text</p><p data-blocks-engine-node-id="more">More text</p></div>'
        . '</div></main></body></html>';
    $tree = $base;
    $tree['source_hash'] = hash('sha256', $html);
    $tree['nodes'] = array();
    $layout = array('html' => array('head', 'body'), 'head' => array('title'), 'title' => array(), 'body' => array('parent'),
        'parent' => array('box'), 'box' => array('child'), 'child' => array('text', 'more'), 'text' => array(), 'more' => array());
    foreach ($layout as $id => $children) {
        $node = array('children' => $children, 'viewports' => array());
        foreach ($base['nodes']['n1']['viewports'] as $viewport => $observation) {
            $viewportWidth = (float) $base['viewports'][$viewport]['width'];
            $boxWidth = $viewportWidth * ($viewportWidth > 600 ? 0.5 : 0.9);
            $observation['used_styles'] = array_replace($observation['used_styles'], $initial, match ($id) {
                'parent' => array('display' => 'grid', 'grid-template-columns' => $viewportWidth . 'px', 'width' => $viewportWidth . 'px', 'height' => '100px'),
                'box' => array('grid-row-start' => '1', 'grid-row-end' => '2', 'grid-column-start' => '1', 'grid-column-end' => '2', 'justify-self' => 'start', 'width' => $boxWidth . 'px', 'height' => '100px'),
                'child' => array('width' => $boxWidth . 'px', 'height' => '100px'),
                'text', 'more' => array('width' => $boxWidth . 'px', 'height' => '50px'),
                default => array('width' => $viewportWidth . 'px'),
            });
            $observation['border_box'] = match ($id) {
                'box', 'child' => array('x' => 0, 'y' => 0, 'width' => $boxWidth, 'height' => 100),
                'text' => array('x' => 0, 'y' => 0, 'width' => $boxWidth, 'height' => 50),
                'more' => array('x' => 0, 'y' => 50, 'width' => $boxWidth, 'height' => 50),
                default => array('x' => 0, 'y' => 0, 'width' => $viewportWidth, 'height' => 100),
            };
            $node['viewports'][$viewport] = $observation;
        }
        $tree['nodes'][$id] = $node;
    }
    $result = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array('index.html' => $html, 'box-tree/index.json' => json_encode($tree))))->toArray();
    $css = implode("\n", array_map(static fn (array $asset): string => (string) ($asset['content'] ?? ''), $result['assets'] ?? array()));
    preg_match('/<div class="wp-block-group fold-child([^"]*)"/', (string) $result['serialized_blocks'], $match);
    $rules = '';
    foreach (array_filter(preg_split('/\s+/', $match[1] ?? '') ?: array()) as $class) {
        if (preg_match_all('/(?:@media[^{]*\{)?\.' . preg_quote($class, '/') . '\.' . preg_quote($class, '/') . '\{[^}]*\}/', $css, $found)) $rules .= implode("\n", $found[0]) . "\n";
    }
    return array($result['source_reports']['box_tree']['index.html']['classification']['nodes']['box']['disposition'] ?? '', $rules, (string) $result['serialized_blocks']);
};

[$disposition, $rules, $markup] = $compile('.fold-child{width:100%}');
$assert('folded' === $disposition, 'the box folds into the child that fills it.', $markup);
$assert(str_contains($rules, "width:50%") && str_contains($rules, "width:90%"), "the child takes the box's fluid width in each of the box's bands.", $rules . $markup);
$assert(!str_contains($rules, 'width:720px') && !str_contains($rules, 'width:351px'), 'the child is not frozen at the captured pixels.', $rules);

// A child that does not declare it fills the box keeps the captured pixels:
// nothing proves it follows the box between captures.
[$disposition, $rules] = $compile('.fold-child{width:calc(100% - 0px)}.fold-child{width:720px}@media (max-width:600px){.fold-child{width:351px}}');
$assert('folded' === $disposition, 'the box folds into a fixed-width child.');
$assert(!str_contains($rules, 'width:50%') && !str_contains($rules, 'width:90%'), "a fixed-width child does not take the box's fluid width.", $rules);
