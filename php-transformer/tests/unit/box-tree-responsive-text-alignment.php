<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

$html = '<style>.title{text-align:center}@media(max-width:1023px){.title{text-align:start}}</style><main data-blocks-engine-node-id="parent"><div class="title" data-blocks-engine-node-id="title"><h1>Responsive title</h1></div></main>';
$fixture = json_decode(file_get_contents(dirname(__DIR__) . '/fixtures/box-tree/index.json'), true);
$defaults = $fixture['nodes']['n1']['viewports']['desktop']['used_styles'];
$tree = array('viewports' => array('desktop' => array('width' => 1440, 'height' => 900), 'mobile' => array('width' => 402, 'height' => 844)), 'nodes' => array());
foreach (array('parent', 'title') as $id) {
    $tree['nodes'][$id] = array('children' => 'parent' === $id ? array('title') : array(), 'viewports' => array());
    foreach (array('desktop', 'mobile') as $viewport) {
        $tree['nodes'][$id]['viewports'][$viewport] = array(
            'border_box' => array('x' => 0, 'y' => 0, 'width' => 300, 'height' => 100),
            'used_styles' => array_replace($defaults, array('display' => 'block', 'text-align' => 'title' === $id && 'desktop' === $viewport ? 'center' : 'start')),
        );
    }
}
$result = (new HtmlTransformer())->transform($html, array('box_tree' => $tree))->toArray();
$css = implode("\n", array_column($result['assets'], 'content'));
if (!preg_match('/@media \(max-width:1023px\)\{\.be-inline-geometry-[^{]+\{[^}]*text-align:start/', $css)) {
    throw new RuntimeException('Captured alignment must follow the author breakpoint, not the mobile capture width: ' . $css);
}
echo "Responsive captured text alignment passed\n";
