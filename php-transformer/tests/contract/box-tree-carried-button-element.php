<?php
declare(strict_types=1);

// Exercises box-tree decisions, which are opt-in (#7352).
putenv('BLOCKS_ENGINE_BOX_TREE_INTAKE=1');

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

// #6777: on a box-tree route each core/button's look moves from block
// attributes to a carrier rule on its link (#6708). That carrier still owns
// the look, so the buttons every route shares project to
// `styles.elements.button` exactly as when the look sat in block attributes.
$assert = static function (bool $ok, string $message): void {
    if (!$ok) throw new RuntimeException($message);
    echo "PASS: $message\n";
};
$css = 'body{margin:0;font-family:Arial,sans-serif}.btn{display:inline-block;padding:12px 24px;background-color:#111111;color:#ffffff;border-radius:3px;font-size:12px;font-weight:800;text-decoration:none}';
$page = static function (string $title, bool $evidence) use ($css): string {
    $id = static fn(string $node): string => $evidence ? ' data-blocks-engine-node-id="' . $node . '"' : '';
    $buttons = '';
    foreach (array('One', 'Two', 'Three') as $index => $label) {
        $buttons .= '<p' . $id('p' . $index) . '>' . $title . ' copy ' . $label . '.</p><a class="btn" href="/' . strtolower($label) . '"' . $id('b' . $index) . '>' . $label . '</a>';
    }
    return '<!doctype html><html' . $id('n1') . '><head' . $id('n2') . '><title>' . $title . '</title><style>' . $css . '</style></head>'
        . '<body' . $id('n4') . '><main' . $id('m') . '><h1' . $id('h') . '>' . $title . '</h1>' . $buttons . '</main></body></html>';
};
$evidenceHtml = $page('Evidence', true);
$template = json_decode((string) file_get_contents(dirname(__DIR__) . '/fixtures/box-tree/index.json'), true, 32, JSON_THROW_ON_ERROR)['nodes']['n7']['viewports']['desktop']['used_styles'];
foreach ($template as $property => $value) {
    if (str_starts_with($property, 'margin-') || str_starts_with($property, 'padding-')) $template[$property] = '0px';
}
$template['font-family'] = 'Arial, sans-serif';
$widths = array('desktop' => 1440, 'mobile' => 390);
$node = static function (array $children, array $styles, float $height) use ($template, $widths): array {
    $viewports = array();
    foreach ($widths as $key => $width) {
        $viewports[$key] = array('border_box' => array('x' => 0, 'y' => 0, 'width' => $width, 'height' => $height), 'used_styles' => array_replace($template, array('width' => $width . 'px', 'height' => $height . 'px'), $styles));
    }
    return array('children' => $children, 'viewports' => $viewports);
};
$nodes = array(
    'n1' => $node(array('n2', 'n4'), array(), 400),
    'n2' => $node(array(), array('display' => 'none'), 0),
    'n4' => $node(array('m'), array(), 400),
    'm' => $node(array('h', 'p0', 'b0', 'p1', 'b1', 'p2', 'b2'), array(), 400),
    'h' => $node(array(), array('font-size' => '32px', 'font-weight' => '700', 'margin-top' => '21.44px', 'margin-bottom' => '21.44px'), 37),
);
$button = array(
    'display' => 'inline-block', 'padding-top' => '12px', 'padding-right' => '24px', 'padding-bottom' => '12px', 'padding-left' => '24px',
    'background-color' => 'rgb(17, 17, 17)', 'color' => 'rgb(255, 255, 255)', 'border-radius' => '3px', 'font-size' => '12px', 'font-weight' => '800',
);
foreach (array(0, 1, 2) as $index) {
    $nodes['p' . $index] = $node(array(), array('margin-top' => '16px', 'margin-bottom' => '16px'), 18);
    $nodes['b' . $index] = $node(array(), $button, 38);
    foreach ($widths as $key => $width) $nodes['b' . $index]['viewports'][$key]['border_box']['width'] = 90;
    foreach ($widths as $key => $width) $nodes['b' . $index]['viewports'][$key]['used_styles']['width'] = '42px';
}
$tree = array(
    'schema' => 'blocks-engine/box-tree/v2',
    'source_path' => 'evidence.html',
    'source_hash' => hash('sha256', $evidenceHtml),
    'viewports' => array('desktop' => array('width' => 1440, 'height' => 900), 'mobile' => array('width' => 390, 'height' => 844)),
    'nodes' => $nodes,
);
$compiled = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array(
    'index.html' => $page('Home', false),
    'evidence.html' => $evidenceHtml,
    'box-tree/evidence.json' => json_encode($tree, JSON_THROW_ON_ERROR),
)));
$assert(!in_array('box_tree_styles_invalid', array_column($compiled->toArray()['diagnostics'], 'code'), true), 'The box-tree sidecar is accepted.');
$plan = $compiled->toWordPressSitePlanView()['wordpress_site_plan'];
$pages = array_column($plan['pages'], 'canonical_block_markup', 'source_path');
$assert(3 === preg_match_all('/<!-- wp:button \{"className":"[^"]*be-inline-geometry-[0-9a-f]+[^"]*"\} -->/', (string) ($pages['evidence.html'] ?? ''), $matches), 'The evidence route keeps each button look in a carrier on its link, not in block attributes.');
$theme = json_decode(array_column($plan['writes'], null, 'target_path')['theme.json']['payload']['data'], true, 512, JSON_THROW_ON_ERROR);
$element = $theme['styles']['elements']['button'] ?? array();
$assert(isset($element['color']['background'], $element['color']['text']), 'The shared button colours project to the button element.');
$assert('3px' === ($element['border']['radius'] ?? null), 'The shared button radius projects to the button element.');
$assert('800' === ($element['typography']['fontWeight'] ?? null), 'The shared button weight projects to the button element.');
$assert(isset($element['spacing']['padding']), 'The shared button padding projects to the button element.');
echo "Box-tree carried button element contract passed.\n";
