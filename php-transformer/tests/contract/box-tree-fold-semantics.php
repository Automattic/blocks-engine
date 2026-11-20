<?php
declare(strict_types=1);

// Semantic section roots can share their sole equal-box Group boundary.
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

$compile = static function (string $childId = '', string $boxStyle = '', string $childStyle = '', string $tag = 'section', string $extra = '', string $childExtra = '') use ($base, $initial): array {
    $html = '<!doctype html><html data-blocks-engine-node-id="html"><head data-blocks-engine-node-id="head"><title data-blocks-engine-node-id="title">Entrance</title>'
        . $childStyle . '</head>'
        . '<body data-blocks-engine-node-id="body"><main class="fold-parent" data-blocks-engine-node-id="parent">'
        . '<' . $tag . ' id="s" tabindex="-1" data-testid="section" ' . $extra . ' data-blocks-engine-node-id="box">'
        . '<div id="' . $childId . '" ' . $childExtra . ' data-blocks-engine-node-id="child"><p data-blocks-engine-node-id="one">One</p><p data-blocks-engine-node-id="two">Two</p></div></' . $tag . '>'
        . '<p data-blocks-engine-node-id="sibling">Sibling</p></main></body></html>';
    $tree = $base;
    $tree['source_hash'] = hash('sha256', $html);
    $tree['nodes'] = array();
    $layout = array('html' => array('head', 'body'), 'head' => array('title'), 'title' => array(), 'body' => array('parent'),
        'parent' => array('box', 'sibling'), 'box' => array('child'), 'child' => array('one', 'two'), 'one' => array(), 'two' => array(), 'sibling' => array());
    foreach ($layout as $id => $children) {
        $node = array('children' => $children, 'viewports' => array());
        foreach ($base['nodes']['n1']['viewports'] as $viewport => $observation) {
            $observation['used_styles'] = array_replace($observation['used_styles'], $initial, match ($id) {
                'parent', 'child' => array('display' => 'grid', 'grid-template-columns' => '400px'),
                'box' => array('grid-row-start' => '1', 'grid-row-end' => '2', 'grid-column-start' => '1', 'grid-column-end' => '2', 'justify-self' => 'start'),
                default => array(),
            });
            $observation['border_box'] = 'sibling' === $id
                ? array('x' => 0, 'y' => 100, 'width' => 400, 'height' => 20)
                : array('x' => 0, 'y' => 0, 'width' => 400, 'height' => 100);
            $node['viewports'][$viewport] = $observation;
        }
        $tree['nodes'][$id] = $node;
    }
    return (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array('index.html' => $html, 'box-tree/index.json' => json_encode($tree))))->toArray();
};

foreach (array('section', 'header', 'footer', 'main', 'article', 'aside', 'nav') as $tag) {
    $result = $compile('unused', '', '', $tag);
    $node = $result['source_reports']['box_tree']['index.html']['classification']['nodes']['box'];
    $markup = $result['serialized_blocks'];
    $assert(('nav' === $tag ? 'load-bearing' : 'folded') === $node['disposition'], "$tag: disposition");
    if ('nav' === $tag) continue;
    $assert(str_contains($markup, '"tagName":"' . $tag . '"') && str_contains($markup, '"anchor":"s"'), "$tag: carries tag and anchor");
    $assert(str_contains($markup, '"tabindex":"-1"') && !str_contains($markup, 'id="unused"'), "$tag: carries tabindex and drops unused id");
}
foreach (array('role="region"', 'aria-label="Region"', 'name="region"', 'onclick="alert(1)"') as $extra) {
    $result = $compile('', '', '', 'section', $extra);
    $assert('load-bearing' === $result['source_reports']['box_tree']['index.html']['classification']['nodes']['box']['disposition'], "$extra: keeps box");
}
// Builder test hooks are resolved during conversion, not live root attributes.
$result = $compile('unused', '', '', 'section', 'data-block-level-container="section"', 'data-testid="internal-container-root" data-block-level-container="child"');
$node = $result['source_reports']['box_tree']['index.html']['classification']['nodes']['box'];
$assert('folded' === $node['disposition'], 'conflicting builder data attributes still fold');
$assert(!isset($node['fold_refusal']), 'dropped builder attributes do not produce a refusal');
$assert(!str_contains($result['serialized_blocks'], 'data-testid') && !str_contains($result['serialized_blocks'], 'data-block-level-container'), 'dropped builder attributes are not promoted to runtime metadata');

foreach (array('tabindex', 'data-interact-enter', 'data-blocks-engine-runtime-hook') as $attribute) {
    $extra = 'tabindex' === $attribute ? '' : $attribute . '="section"';
    $result = $compile('', '', '', 'section', $extra, $attribute . '="child"');
    $node = $result['source_reports']['box_tree']['index.html']['classification']['nodes']['box'];
    $assert('load-bearing' === $node['disposition'], "$attribute: conflicting runtime hooks keep both boxes");
    $assert('hook-conflict:' . $attribute === $node['fold_refusal'], "$attribute: names the carried hook conflict");
}
$result = $compile('', '', '', 'section', 'data-interact-enter="fade" data-blocks-engine-runtime-hook="section"');
$assert('folded' === $result['source_reports']['box_tree']['index.html']['classification']['nodes']['box']['disposition'], 'nonconflicting runtime hooks fold');
$assert(str_contains($result['serialized_blocks'], '"data-interact-enter":"fade"') && str_contains($result['serialized_blocks'], '"data-blocks-engine-runtime-hook":"section"'), 'folded runtime hooks survive in metadata');

$result = $compile('target', '', '<style>#target { color: red }</style>');
$assert('load-bearing' === $result['source_reports']['box_tree']['index.html']['classification']['nodes']['box']['disposition'], 'a stylesheet-referenced child id keeps both boxes');
$assert('referenced-id' === $result['source_reports']['box_tree']['index.html']['classification']['nodes']['box']['fold_refusal'], 'names the referenced child id refusal');
$result = $compile('target', '', '<link href="#target">');
$assert('load-bearing' === $result['source_reports']['box_tree']['index.html']['classification']['nodes']['box']['disposition'], 'a fragment-referenced child id keeps both boxes');
