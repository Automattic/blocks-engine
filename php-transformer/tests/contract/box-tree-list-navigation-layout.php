<?php
declare(strict_types=1);

require __DIR__ . '/../../vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

// Issue #5859 (sdnunca footer social bar): a block `<nav>` around a flex `<ul>`
// lowers to one core/navigation whose generated list renders
// `display:contents`, so the items lay out in the navigation host. The host's
// carrier must restate the list's flex row and gap, not the `<nav>`'s own block
// display: that stacked the icons and pushed the footer down at every width.
$assert = static function (bool $ok, string $message, string $detail = ''): void {
    if (!$ok) throw new RuntimeException($message . "\n" . $detail);
    echo 'PASS: ' . $message . "\n";
};
$base = json_decode(file_get_contents(__DIR__ . '/../fixtures/box-tree/index.json'), true, 32, JSON_THROW_ON_ERROR);
$template = $base['nodes']['n6']['viewports']['desktop'];
// The host is 316px wide on desktop and 342px on mobile; its items keep their size.
$node = static function (array $box, array $styles, array $children = array(), bool $host = false) use ($template): array {
    $viewports = array();
    foreach (array('desktop' => 316, 'mobile' => $box[2]) as $name => $width) {
        $width = $host ? $width : $box[2];
        $viewport = $template;
        $viewport['border_box'] = array('x' => $box[0], 'y' => $box[1], 'width' => $width, 'height' => $box[3]);
        $viewport['used_styles'] = array_merge($viewport['used_styles'], array('width' => $width . 'px', 'height' => $box[3] . 'px'), $styles);
        $viewports[$name] = $viewport;
    }
    return array('children' => $children, 'viewports' => $viewports);
};
$row = array('display' => 'flex', 'flex-direction' => 'row', 'flex-wrap' => 'wrap', 'justify-content' => 'center', 'row-gap' => '12px', 'column-gap' => '20px');
$tree = $base;
$tree['nodes'] = array(
    'n1' => $base['nodes']['n1'], 'n2' => $base['nodes']['n2'], 'n3' => $base['nodes']['n3'], 'n4' => $base['nodes']['n4'], 'n5' => array_merge($base['nodes']['n5'], array('children' => array('n6'))),
    'n6' => $node(array(24, 0, 342, 24), array('display' => 'block'), array('n7'), true),
    'n7' => $node(array(24, 0, 342, 24), $row, array('n8', 'n10', 'n12'), true),
);
$items = '';
foreach (array(8 => 'One', 10 => 'Two', 12 => 'Three') as $id => $label) {
    $x = 139 + ($id - 8) * 22;
    $tree['nodes']['n' . $id] = $node(array($x, 0, 24, 24), array('display' => 'list-item'), array('n' . ($id + 1)));
    $tree['nodes']['n' . ($id + 1)] = $node(array($x, 0, 24, 24), array('display' => 'block'));
    $items .= '<li data-blocks-engine-node-id="n' . $id . '"><a href="/' . strtolower($label) . '" data-blocks-engine-node-id="n' . ($id + 1) . '">' . $label . '</a></li>';
}
$source = '<!doctype html><html data-blocks-engine-node-id="n1"><head data-blocks-engine-node-id="n2"><title data-blocks-engine-node-id="n3">Box tree</title><link rel="stylesheet" href="site.css"></head><body data-blocks-engine-node-id="n4"><main data-blocks-engine-node-id="n5">'
    . '<nav class="social" aria-label="Social" data-blocks-engine-node-id="n6"><ul class="social-list" data-blocks-engine-node-id="n7">' . $items . '</ul></nav></main></body></html>';
$tree['source_hash'] = hash('sha256', $source);
$result = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array(
    'index.html' => $source,
    'site.css' => '.social-list{display:flex;flex-wrap:wrap;justify-content:center;gap:12px 20px;list-style:none;margin:0;padding:0}',
    'box-tree/index.json' => json_encode($tree),
)))->toWordPressSitePlanView()['wordpress_site_plan'];

$markup = implode('', array_column($result['pages'], 'canonical_block_markup'));
$css = implode("\n", array_column(array_filter($result['assets'], static fn(array $asset): bool => 'css' === ($asset['kind'] ?? '')), 'content'));
$assert(1 === preg_match('/<!-- wp:navigation (\{.*?\}) \/-->/s', $markup, $navigation) && str_contains($navigation[1], 'blocks-engine-list-navigation'), 'The menu lowers to a list navigation.', $markup);
$assert(1 === preg_match('/be-inline-geometry-[0-9a-f]+/', $navigation[1], $carrier), 'The navigation host carries its box-tree geometry.', $navigation[1]);
$assert(1 === preg_match('/\.' . $carrier[0] . '(?:\.' . $carrier[0] . ')?\{([^}]*)\}/', $css, $rule), 'The host carrier rule is emitted.', $css);
$assert(str_contains($rule[1], 'display:flex') && !str_contains($rule[1], 'display:block'), 'The host takes the list\'s flex display.', $rule[1]);
$assert(1 === preg_match('/(?<![a-z-])gap:12px 20px|column-gap:20px/', $rule[1]), 'The host takes the list\'s item gap.', $rule[1]);
$assert(str_contains($rule[1], 'justify-content:center'), 'The host takes the list\'s item alignment.', $rule[1]);

echo "Box-tree list navigation layout passed\n";
