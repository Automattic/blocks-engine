<?php
declare(strict_types=1);

// Site builders emit `<ul><li><p>text</p></li></ul>` (Wix rich text). When the
// capture proves the paragraph renders as its list item's own text (no box of
// its own, the item's geometry and text styles), the list is a native
// core/list, not a Group per item wrapping a Paragraph. A paragraph the capture
// shows with its own margins keeps the structural form.
require __DIR__ . '/../../vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\Contract\BoxTree;

$assert = static function (bool $ok, string $message): void {
    if (!$ok) throw new RuntimeException($message);
    echo "PASS: $message\n";
};

$styles = static function (string $display, array $overrides = array()): array {
    $used = array_fill_keys(BoxTree::PROPERTIES, 'none');
    foreach (array('padding-top', 'padding-right', 'padding-bottom', 'padding-left', 'margin-top', 'margin-right', 'margin-bottom', 'margin-left') as $box) $used[$box] = '0px';
    foreach (array('border-top', 'border-right', 'border-bottom', 'border-left') as $border) $used[$border] = '0px none rgb(0, 0, 0)';
    $used = array_merge($used, array(
        'display' => $display, 'position' => 'static', 'overflow-x' => 'visible', 'overflow-y' => 'visible',
        'background-color' => 'rgba(0, 0, 0, 0)', 'opacity' => '1', 'mix-blend-mode' => 'normal',
        'color' => 'rgb(105, 79, 35)', 'font-family' => 'Arial', 'font-size' => '16px', 'line-height' => '20px',
        'list-style-type' => 'disc', 'list-style-position' => 'outside',
    ));
    return array_merge($used, $overrides);
};

$compile = static function (string $paragraphMargin) use ($styles): array {
    $html = '<!doctype html><html data-blocks-engine-node-id="n1"><head data-blocks-engine-node-id="n2"><title data-blocks-engine-node-id="n3">List</title></head>'
        . '<body data-blocks-engine-node-id="n4"><main data-blocks-engine-node-id="n5"><ul data-blocks-engine-node-id="list">'
        . '<li data-blocks-engine-node-id="li1"><p data-blocks-engine-node-id="p1">Alpha item</p></li>'
        . '<li data-blocks-engine-node-id="li2"><p data-blocks-engine-node-id="p2">Beta <em>item</em></p></li>'
        . '</ul></main></body></html>';
    $observe = static fn (array $box, array $used): array => array(
        'desktop' => array('border_box' => $box, 'used_styles' => $used),
        'mobile' => array('border_box' => $box, 'used_styles' => $used),
    );
    $nodes = array(
        'n1' => array('children' => array('n4'), 'viewports' => $observe(array('x' => 0, 'y' => 0, 'width' => 400, 'height' => 40), $styles('block'))),
        'n4' => array('children' => array('n5'), 'viewports' => $observe(array('x' => 0, 'y' => 0, 'width' => 400, 'height' => 40), $styles('block'))),
        'n5' => array('children' => array('list'), 'viewports' => $observe(array('x' => 0, 'y' => 0, 'width' => 400, 'height' => 40), $styles('block'))),
        'list' => array('children' => array('li1', 'li2'), 'viewports' => $observe(array('x' => 0, 'y' => 0, 'width' => 400, 'height' => 40), $styles('block', array('padding-left' => '20px')))),
    );
    foreach (array(1, 2) as $i) {
        $box = array('x' => 20, 'y' => 20 * ($i - 1), 'width' => 380, 'height' => 20);
        $nodes["li$i"] = array('children' => array("p$i"), 'viewports' => $observe($box, $styles('list-item')));
        $nodes["p$i"] = array('children' => array(), 'viewports' => $observe($box, $styles('block', array('margin-top' => $paragraphMargin, 'margin-bottom' => $paragraphMargin))));
    }
    $tree = array('schema' => BoxTree::SCHEMA, 'viewports' => array('desktop' => array('width' => 1440, 'height' => 900), 'mobile' => array('width' => 390, 'height' => 844)), 'nodes' => $nodes, 'source_path' => 'index.html', 'source_hash' => hash('sha256', $html));
    return (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array('index.html' => $html, 'box-tree/index.json' => json_encode($tree))))->toArray();
};

$markup = (string) $compile('0px')['serialized_blocks'];
$assert(str_contains($markup, '<!-- wp:list ') || str_contains($markup, '<!-- wp:list -->'), 'A list of render-neutral item paragraphs lowers to core/list.');
$assert(2 === substr_count($markup, '<!-- wp:list-item'), 'Each item is a native list item.');
$assert(!str_contains($markup, '"tagName":"li"') && !str_contains($markup, '"tagName":"ul"'), 'No Group stands in for the list or its items.');
$assert(0 === preg_match('/<li[^>]*>\s*<p[\s>]/', $markup), 'The neutral paragraph is the item\'s own text, not nested markup.');
$assert(1 === preg_match('/<li[^>]*>Beta <em>item<\/em><\/li>/', $markup), 'Item inline formatting survives.');

$kept = (string) $compile('16px')['serialized_blocks'];
$assert(str_contains($kept, '"tagName":"li"') && 2 === preg_match_all('/<!-- wp:paragraph/', $kept), 'A paragraph with captured margins keeps the structural item form.');
