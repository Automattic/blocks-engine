<?php
declare(strict_types=1);

// A menu item whose anchor carries its own subject (Wix: `li` flex row holding
// an item wrapper and a divider) renders as core/navigation-link's `<li>`. That
// `<li>` stands for the source item, so its box-tree carrier is the item's
// captured box (flex, 7px gap, centered), not the anchor's, and it outranks the
// list-navigation `display:list-item` baseline emitted after it.
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
        'color' => 'rgb(0, 0, 0)', 'font-family' => 'Arial', 'font-size' => '16px', 'line-height' => '20px',
        'list-style-type' => 'none', 'list-style-position' => 'outside', 'row-gap' => 'normal', 'column-gap' => 'normal',
        'align-items' => 'normal', 'flex-direction' => 'row', 'flex-wrap' => 'nowrap', 'justify-content' => 'normal', 'cursor' => 'auto',
    ));
    return array_merge($used, $overrides);
};
$observe = static fn (array $box, array $used): array => array(
    'desktop' => array('border_box' => $box, 'used_styles' => $used),
    'mobile' => array('border_box' => $box, 'used_styles' => $used),
);
$page = array('x' => 0, 'y' => 0, 'width' => 1440, 'height' => 40);
$nodes = array(
    'n1' => array('children' => array('n4'), 'viewports' => $observe($page, $styles('block'))),
    'n4' => array('children' => array('n5'), 'viewports' => $observe($page, $styles('block'))),
    'n5' => array('children' => array('nav'), 'viewports' => $observe($page, $styles('block'))),
    'nav' => array('children' => array('list'), 'viewports' => $observe($page, $styles('block'))),
);
$css = '.menu{display:flex;gap:7px;list-style:none;margin:0;padding:0}.item{display:flex;gap:7px;align-items:center}.link{display:block;color:#222;cursor:pointer}.divider{width:1px;height:10px;background:#000}';
$html = '<!doctype html><html data-blocks-engine-node-id="n1"><head data-blocks-engine-node-id="n2"><title data-blocks-engine-node-id="n3">Nav</title><style>' . $css . '</style></head>'
    . '<body data-blocks-engine-node-id="n4"><header data-blocks-engine-node-id="n5"><nav aria-label="Site" data-blocks-engine-node-id="nav"><ul class="menu" data-blocks-engine-node-id="list">';
$items = array();
foreach (array('Home' => '/', 'About' => '/about/') as $label => $href) {
    $i = count($items) + 1;
    $x = 100 * ($i - 1);
    $html .= '<li class="item" data-blocks-engine-node-id="li' . $i . '"><div class="wrap" data-blocks-engine-node-id="w' . $i . '"><a class="link" href="' . $href . '" data-blocks-engine-node-id="a' . $i . '">' . $label . '</a></div>'
        . '<span class="divider" data-blocks-engine-node-id="d' . $i . '"></span></li>';
    $items[] = 'li' . $i;
    $nodes['li' . $i] = array('children' => array('w' . $i, 'd' . $i), 'viewports' => $observe(array('x' => $x, 'y' => 0, 'width' => 60, 'height' => 26), $styles('flex', array('column-gap' => '7px', 'row-gap' => '7px', 'align-items' => 'center'))));
    $nodes['w' . $i] = array('children' => array('a' . $i), 'viewports' => $observe(array('x' => $x, 'y' => 3, 'width' => 52, 'height' => 20), $styles('block')));
    $nodes['a' . $i] = array('children' => array(), 'viewports' => $observe(array('x' => $x, 'y' => 3, 'width' => 52, 'height' => 20), $styles('block', array('color' => 'rgb(34, 34, 34)', 'cursor' => 'pointer'))));
    $nodes['d' . $i] = array('children' => array(), 'viewports' => $observe(array('x' => $x + 59, 'y' => 8, 'width' => 1, 'height' => 10), $styles('block', array('width' => '1px', 'height' => '10px', 'background-color' => 'rgb(0, 0, 0)'))));
}
$nodes['list'] = array('children' => $items, 'viewports' => $observe(array('x' => 0, 'y' => 0, 'width' => 1440, 'height' => 26), $styles('flex', array('column-gap' => '7px', 'row-gap' => '7px'))));
$html .= '</ul></nav></header></body></html>';
$tree = array('schema' => BoxTree::SCHEMA, 'viewports' => array('desktop' => array('width' => 1440, 'height' => 900), 'mobile' => array('width' => 390, 'height' => 844)), 'nodes' => $nodes, 'source_path' => 'index.html', 'source_hash' => hash('sha256', $html));
$result = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array('index.html' => $html, 'box-tree/index.json' => json_encode($tree))))->toArray();

$markup = (string) $result['serialized_blocks'];
$assert(1 === preg_match('/<!-- wp:navigation-link (\{.*?\}) \/-->/', $markup, $link), 'The menu item lowers to core/navigation-link.');
$attrs = json_decode($link[1], true);
$assert(isset($attrs['metadata']['blocksEngineNavigationAnchor']), 'The anchor carries its own subject.');
$assert(1 === preg_match('/(?:^|\s)(be-inline-geometry-[a-f0-9]{16})(?:\s|$)/', (string) $attrs['className'], $carrier), 'The rendered item has a box-tree carrier.');
$assert(!str_contains((string) $attrs['metadata']['blocksEngineNavigationAnchor']['className'], $carrier[1]), 'The item carrier is not the anchor carrier.');

$stylesheets = implode("\n", array_map(static fn (array $asset): string => (string) ($asset['content'] ?? ''), array_filter($result['assets'], static fn (array $asset): bool => 'css' === ($asset['kind'] ?? null))));
$class = preg_quote($carrier[1], '/');
$assert(1 === preg_match('/([a-z]*(?:\.' . $class . ')+)\{([^}]*)\}/', $stylesheets, $rule), 'The item carrier rule is emitted.');
$assert(str_contains($rule[2], 'display:flex') && str_contains($rule[2], 'gap:7px') && str_contains($rule[2], 'align-items:center'), 'The item carrier restates the source item\'s flex row, gap and alignment.');
$assert(!str_contains($rule[2], 'cursor:pointer'), 'The item carrier does not restate the anchor\'s box.');
// The baseline `.wp-block-navigation:where(…) .wp-block-navigation-item` weighs (0,2,0).
$assert(str_starts_with($rule[1], 'li.') && 2 === substr_count($rule[1], '.'), 'The item carrier outranks the list-navigation display:list-item baseline.');
