<?php
declare(strict_types=1);

// Exercises box-tree decisions, which are opt-in (#7352).
putenv('BLOCKS_ENGINE_BOX_TREE_INTAKE=1');

require __DIR__ . '/../../vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\Contract\BoxTree;

// The anchor keeps its own carrier inside Core's item. Its observed padding
// must not also be projected onto the navigation-link's outer <li>.
$assert = static function (bool $ok, string $message): void {
    if (!$ok) throw new RuntimeException($message);
};
$html = '<style>.list{display:flex}.item{display:flex;margin:0 6px}.item>a{display:flex;padding:2px 0;cursor:pointer;align-items:center}</style>'
    . '<nav data-blocks-engine-node-id="nav"><div class="list" data-blocks-engine-node-id="list">'
    . '<div class="item" style="" data-blocks-engine-node-id="home"><a href="/" data-blocks-engine-node-id="a">Home</a></div>'
    . '<div class="item" data-blocks-engine-node-id="b"><a href="/b" data-blocks-engine-node-id="ba">B</a></div></div></nav>';
$base = array_replace(array_fill_keys(BoxTree::PROPERTIES, 'auto'), array(
    'display' => 'flex', 'position' => 'static', 'width' => '100px', 'height' => '24px',
    'padding-top' => '0px', 'padding-right' => '0px', 'padding-bottom' => '0px', 'padding-left' => '0px',
    'margin-top' => '0px', 'margin-right' => '0px', 'margin-bottom' => '0px', 'margin-left' => '0px',
    'cursor' => 'auto', 'align-items' => 'normal', 'flex-direction' => 'row',
    'background-color' => 'rgba(0, 0, 0, 0)', 'background-image' => 'none',
    'transform' => 'none', 'opacity' => '1', 'visibility' => 'visible',
));
$children = array('nav' => array('list'), 'list' => array('home', 'b'), 'home' => array('a'), 'a' => array(), 'b' => array('ba'), 'ba' => array());
$nodes = array();
foreach ($children as $id => $ids) {
    $styles = $base;
    if (in_array($id, array('home', 'b'), true)) {
        $styles = array_replace($styles, array('margin-right' => '6px', 'margin-left' => '6px'));
    }
    if (in_array($id, array('a', 'ba'), true)) {
        $styles = array_replace($styles, array('padding-top' => '2px', 'padding-bottom' => '2px', 'cursor' => 'pointer', 'align-items' => 'center'));
    }
    $nodes[$id] = array('children' => $ids, 'viewports' => array('desktop' => array(
        'border_box' => array('x' => 0, 'y' => 0, 'width' => 100, 'height' => 24),
        'used_styles' => $styles,
    )));
}
$tree = array('schema' => BoxTree::SCHEMA, 'source_path' => 'index.html', 'source_hash' => hash('sha256', $html),
    'viewports' => array('desktop' => array('width' => 1440, 'height' => 900)), 'nodes' => $nodes);
$result = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array(
    'index.html' => $html, 'box-tree/index.json' => json_encode($tree, JSON_THROW_ON_ERROR),
)))->toArray();
$assert(!array_filter($result['diagnostics'], static fn(array $row): bool => str_starts_with($row['code'], 'box_tree_')), 'The box-tree sidecar is accepted.');
$serialized = $result['serialized_blocks'];
preg_match_all('/<!-- wp:navigation-link (\{.*?\}) \/-->/', $serialized, $matches);
$assert(2 === count($matches[1]), 'Both items become navigation links.');
// Read only rules for the carrier classes in each subject's own className,
// not author selectors or the nested anchor's independently generated rules.
$carrierCss = static function (string $classes) use ($result, $assert): string {
    preg_match_all('/\bbe-inline-geometry-[\w-]+/', $classes, $carriers);
    $assert(array() !== $carriers[0], 'The subject has an observed geometry carrier.');
    $css = '';
    $output = stripslashes(json_encode($result, JSON_UNESCAPED_SLASHES));
    foreach ($carriers[0] as $carrier) {
        preg_match_all('/[^{}]*\.' . preg_quote($carrier, '/') . '(?![\w-])[^{}]*\{([^{}]*)\}/', $output, $rules);
        $css .= implode(';', $rules[1]);
    }
    return $css;
};
foreach ($matches[1] as $json) {
    $attrs = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
    $itemCss = $carrierCss($attrs['className'] ?? '');
    $assert(str_contains($itemCss, 'padding:0px') && str_contains($itemCss, 'margin:0 6px'), 'The item observes its own padding and margin: ' . $itemCss);
    $assert(!str_contains($itemCss, 'padding:2px'), 'The item does not restate anchor padding: ' . $itemCss);
    $assert(!str_contains($itemCss, 'cursor:pointer'), 'The item does not restate the anchor cursor.');
    $assert(!str_contains($itemCss, 'align-items:center'), 'The item does not restate anchor alignment.');
    $anchorCss = $carrierCss($attrs['metadata']['blocksEngineNavigationAnchor']['className'] ?? '');
    $assert(str_contains($anchorCss, 'padding:2px 0'), 'The anchor keeps its own observed padding: ' . $anchorCss);
}
echo "Navigation item box-tree carrier passed\n";
