<?php
declare(strict_types=1);

// ADR 0005 slice 5: a folded box's declarations move onto its sole child, so
// they survive only when that child's block is restyled from box-tree
// evidence. A child a pattern converts on its own (an icon link becomes an
// accessible link) carries no box-tree rule: the box must keep its block
// rather than drop the placement and size the child depended on.
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
    . '<body data-blocks-engine-node-id="body"><main class="fold-parent" data-blocks-engine-node-id="parent">'
    . '<div class="fold-box" data-blocks-engine-node-id="box">'
    . '<a class="icon-link" href="/cart/" role="button" aria-label="Cart with 0 items" data-blocks-engine-node-id="child">'
    . '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="100%" height="100%" data-blocks-engine-node-id="icon"><path d="M2 2h20v20H2z"/></svg>'
    . '</a></div>'
    . '<p data-blocks-engine-node-id="sibling">Sibling</p></main></body></html>';
$box = array('x' => 0, 'y' => 0, 'width' => 40, 'height' => 40);
$tree = $base;
$tree['source_hash'] = hash('sha256', $html);
$tree['nodes'] = array();
$layout = array('html' => array('head', 'body'), 'head' => array('title'), 'title' => array(), 'body' => array('parent'),
    'parent' => array('box', 'sibling'), 'box' => array('child'), 'child' => array('icon'), 'icon' => array(), 'sibling' => array());
foreach ($layout as $id => $children) {
    $node = array('children' => $children, 'viewports' => array());
    foreach ($base['nodes']['n1']['viewports'] as $viewport => $observation) {
        $observation['used_styles'] = array_replace($observation['used_styles'], $initial, match ($id) {
            'parent' => array('display' => 'grid', 'grid-template-columns' => '400px'),
            'box' => array('grid-row-start' => '1', 'grid-row-end' => '2', 'grid-column-start' => '1', 'grid-column-end' => '2', 'justify-self' => 'start', 'width' => '40px', 'height' => '40px'),
            'child' => array('width' => '40px', 'height' => '40px'),
            'icon' => array('display' => 'inline', 'width' => '40px', 'height' => '40px'),
            default => array(),
        });
        $observation['border_box'] = match ($id) {
            'parent' => array('x' => 0, 'y' => 0, 'width' => 400, 'height' => 100),
            'sibling' => array('x' => 0, 'y' => 40, 'width' => 400, 'height' => 20),
            default => $box,
        };
        $node['viewports'][$viewport] = $observation;
    }
    $tree['nodes'][$id] = $node;
}
$result = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array('index.html' => $html, 'box-tree/index.json' => json_encode($tree))))->toArray();
$classification = $result['source_reports']['box_tree']['index.html']['classification'];
$markup = $result['serialized_blocks'];

$assert('folded' === $classification['nodes']['box']['disposition'], 'the box is classified folded into its icon link.');
$assert(1 === preg_match('/<!-- wp:[a-z-]+\/accessible-link /', $markup), 'the icon link converts as an accessible link.');
$assert(1 === preg_match('/<!-- wp:group (\{(?:(?!-->).)*?"className":"fold-box[^"]*"(?:(?!-->).)*?\}) -->/', $markup, $match), 'the box keeps its block when the link cannot carry its declarations.');
$attrs = json_decode($match[1], true);
$css = implode("\n", array_map(static fn (array $asset): string => (string) ($asset['content'] ?? ''), $result['assets'] ?? array()));
$declared = false;
foreach (array_filter(preg_split('/\s+/', (string) ($attrs['className'] ?? '')) ?: array()) as $class) {
    if (preg_match('/\.' . preg_quote($class, '/') . '\.' . preg_quote($class, '/') . '\{[^}]*justify-self:start/', $css)) $declared = true;
}
$assert($declared || str_contains($match[1], '"start"'), 'the kept box carries its own placement.');
$assert(1 === preg_match('/fold-box.*accessible-link/s', $markup), 'the accessible link stays inside the kept box.');
$assert(str_contains($markup, 'Sibling'), 'the sibling is untouched.');
