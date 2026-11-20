<?php
declare(strict_types=1);

// A raw SVG cannot render block attributes carrying a folded box's geometry.
require __DIR__ . '/../../vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

$base = json_decode((string) file_get_contents(__DIR__ . '/../fixtures/box-tree/index.json'), true, 32, JSON_THROW_ON_ERROR);
$html = '<!doctype html><html><head><title>Icon</title><style>.icons{display:grid}.icon-box{position:relative;width:41px;height:48px;grid-area:1/1/2/2}.icon-root,.icon-root svg{position:absolute;inset:0;width:100%;height:100%}</style></head><body>'
    . '<main class="icons" data-blocks-engine-node-id="parent"><div class="icon-box" data-blocks-engine-node-id="box"><div class="icon-root" data-blocks-engine-node-id="root">'
    . '<svg data-blocks-engine-node-id="icon" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 41 48" width="100%" height="100%"><path d="M0 0h41v48H0z"><animate attributeName="opacity" values="0;1" dur="1s" repeatCount="indefinite"/></path></svg></div></div><p data-blocks-engine-node-id="sibling">Sibling</p></main></body></html>';
$tree = $base;
$tree['source_hash'] = hash('sha256', $html);
$tree['nodes'] = array();
foreach (array('parent' => array('box', 'sibling'), 'box' => array('root'), 'root' => array('icon'), 'icon' => array(), 'sibling' => array()) as $id => $children) {
    $node = array('children' => $children, 'viewports' => array());
    foreach ($base['nodes']['n1']['viewports'] as $viewport => $observation) {
        $used = $observation['used_styles'];
        foreach (array('padding', 'margin') as $property) {
            foreach (array('top', 'right', 'bottom', 'left') as $side) $used[$property . '-' . $side] = '0px';
        }
        $used = array_replace($used, array('display' => 'block', 'position' => 'static', 'width' => '41px', 'height' => '48px'), match ($id) {
            'parent' => array('display' => 'grid', 'grid-template-columns' => '696px', 'width' => '696px'),
            'box' => array('position' => 'relative', 'grid-column-start' => '1', 'grid-column-end' => '2', 'grid-row-start' => '1', 'grid-row-end' => '2'),
            'root', 'icon' => array('position' => 'absolute', 'top' => '0px', 'right' => '0px', 'bottom' => '0px', 'left' => '0px'),
            default => array(),
        });
        $observation['used_styles'] = $used;
        $observation['border_box'] = array('x' => 0, 'y' => 0, 'width' => 'parent' === $id ? 696 : 41, 'height' => 48);
        $node['viewports'][$viewport] = $observation;
    }
    $tree['nodes'][$id] = $node;
}
$result = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array('index.html' => $html, 'box-tree/index.json' => json_encode($tree))))->toArray();
$markup = $result['serialized_blocks'];
if (!preg_match('/<!-- wp:group .*?--><div[^>]*class="[^"]*(?:icon-box|icon-root)[^"]*"[^>]*>.*?<svg/s', $markup)) {
    throw new RuntimeException('The raw SVG must retain a geometry-carrying Group: ' . $markup);
}
$css = implode("\n", array_map(static fn (array $asset): string => (string) ($asset['content'] ?? ''), $result['assets'] ?? array()));
foreach (array('width:41px', 'height:48px', 'position:relative') as $declaration) {
    if (!str_contains($css, $declaration) && !str_contains($markup, $declaration)) {
        throw new RuntimeException('The retained box must carry ' . $declaration);
    }
}
if (!str_contains($css, 'grid-area:1/1/2/2') && !str_contains($css, 'grid-row:1/2')) {
    throw new RuntimeException('The retained box must keep its grid placement: ' . $css);
}
echo "PASS: SVG fill chain retains its size, position and grid carrier\n";
