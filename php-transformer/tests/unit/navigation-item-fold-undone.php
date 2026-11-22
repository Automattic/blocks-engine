<?php
declare(strict_types=1);

// Exercises box-tree decisions, which are opt-in (#7352).
putenv('BLOCKS_ENGINE_BOX_TREE_INTAKE=1');

require __DIR__ . '/../../vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\Contract\BoxTree;

// A navigation item with only its own side margins folds into its link
// (equal border boxes). Core's item then renders the source item's box, so
// the fold is undone: the margins land once, on the item, not also on the link.
$assert = static function (bool $ok, string $message): void {
    if (!$ok) throw new RuntimeException($message);
};
$html = '<style>.list{display:flex}.item{margin:0 6px}</style>'
    . '<nav data-blocks-engine-node-id="nav"><div class="list" data-blocks-engine-node-id="list">'
    . '<div class="item" data-blocks-engine-node-id="i1"><a href="/" data-blocks-engine-node-id="a1">Home</a></div>'
    . '<div class="item" data-blocks-engine-node-id="i2"><a href="/b" data-blocks-engine-node-id="a2">Bee</a></div></div></nav>';
$base = array_replace(array_fill_keys(BoxTree::PROPERTIES, 'auto'), array(
    'display' => 'block', 'position' => 'static', 'z-index' => 'auto', 'visibility' => 'visible',
    'padding-top' => '0px', 'padding-right' => '0px', 'padding-bottom' => '0px', 'padding-left' => '0px',
    'margin-top' => '0px', 'margin-right' => '0px', 'margin-bottom' => '0px', 'margin-left' => '0px',
    'max-width' => 'none', 'max-height' => 'none', 'font-size' => '16px', 'line-height' => '24px', 'text-indent' => '0px',
    'background-color' => 'rgba(0, 0, 0, 0)', 'background-image' => 'none', 'mask-image' => 'none', 'box-shadow' => 'none',
    'border-top' => '0px none rgb(0, 0, 0)', 'border-right' => '0px none rgb(0, 0, 0)', 'border-bottom' => '0px none rgb(0, 0, 0)',
    'border-left' => '0px none rgb(0, 0, 0)', 'outline' => '0px none rgb(0, 0, 0)', 'border-radius' => '0px',
    'overflow-x' => 'visible', 'overflow-y' => 'visible', 'transform' => 'none', 'filter' => 'none', 'backdrop-filter' => 'none',
    'clip-path' => 'none', 'opacity' => '1', 'mix-blend-mode' => 'normal',
));
$boxes = array('nav' => array(0, 200), 'list' => array(0, 120), 'i1' => array(6, 40), 'a1' => array(6, 40), 'i2' => array(58, 30), 'a2' => array(58, 30));
$children = array('nav' => array('list'), 'list' => array('i1', 'i2'), 'i1' => array('a1'), 'a1' => array(), 'i2' => array('a2'), 'a2' => array());
$nodes = array();
foreach ($children as $id => $ids) {
    $styles = $base;
    if ('list' === $id) $styles['display'] = 'flex';
    if ('i1' === $id || 'i2' === $id) $styles = array_replace($styles, array('margin-right' => '6px', 'margin-left' => '6px'));
    $nodes[$id] = array('children' => $ids, 'viewports' => array('desktop' => array(
        'border_box' => array('x' => $boxes[$id][0], 'y' => 0, 'width' => $boxes[$id][1], 'height' => 24),
        'used_styles' => $styles,
    )));
}
$tree = array('schema' => BoxTree::SCHEMA, 'source_path' => 'index.html', 'source_hash' => hash('sha256', $html),
    'viewports' => array('desktop' => array('width' => 1440, 'height' => 900)), 'nodes' => $nodes);
$result = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array(
    'index.html' => $html, 'box-tree/index.json' => json_encode($tree, JSON_THROW_ON_ERROR),
)))->toArray();
$assert(!array_filter($result['diagnostics'], static fn(array $row): bool => str_starts_with($row['code'], 'box_tree_')), 'The box-tree sidecar is accepted.');
preg_match_all('/<!-- wp:navigation-link (\{.*?\}) \/-->/', $result['serialized_blocks'], $matches);
$assert(2 === count($matches[1]), 'Both items become navigation links.');
$output = stripslashes(json_encode($result, JSON_UNESCAPED_SLASHES));
$carrierCss = static function (string $classes) use ($output): string {
    preg_match_all('/\bbe-inline-geometry-[\w-]+/', $classes, $carriers);
    $css = '';
    foreach ($carriers[0] as $carrier) {
        preg_match_all('/[^{}]*\.' . preg_quote($carrier, '/') . '(?![\w-])[^{}]*\{([^{}]*)\}/', $output, $rules);
        $css .= implode(';', $rules[1]);
    }
    return $css;
};
foreach ($matches[1] as $json) {
    $attrs = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
    $itemCss = $carrierCss($attrs['className'] ?? '');
    $anchorCss = $carrierCss($attrs['metadata']['blocksEngineNavigationAnchor']['className'] ?? '');
    $assert(1 === preg_match('/margin:0(?:px)? 6px/', $itemCss), 'The item keeps its own side margins: ' . $itemCss);
    $assert(1 !== preg_match('/margin(?:-left|-right)?:[^;]*\\b6px/', $anchorCss), 'The link does not restate the margins of the item folded into it: ' . $anchorCss);
}
echo "Navigation item fold undone passed\n";
