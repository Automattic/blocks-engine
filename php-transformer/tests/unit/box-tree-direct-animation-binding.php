<?php
declare(strict_types=1);
require __DIR__ . '/../../vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\ThemeBootstrap;

$html = '<!doctype html><html data-blocks-engine-node-id="n1"><head data-blocks-engine-node-id="n2"><style>'
    . '@keyframes arrive{from{opacity:0}to{opacity:1}}.entrance{animation:arrive 1s ease forwards}'
    . '</style></head><body data-blocks-engine-node-id="n4"><main data-blocks-engine-node-id="m">'
    . '<h1 class="entrance" data-blocks-engine-node-id="p">Animated heading</h1></main></body></html>';
$template = json_decode((string) file_get_contents(__DIR__ . '/../fixtures/box-tree/index.json'), true, 32, JSON_THROW_ON_ERROR)['nodes']['n7']['viewports']['desktop']['used_styles'];
$node = static function (array $children, array $styles = array()) use ($template): array {
    return array('children' => $children, 'viewports' => array('desktop' => array(
        'border_box' => array('x' => 0, 'y' => 0, 'width' => 800, 'height' => 40),
        'used_styles' => array_replace($template, $styles),
    )));
};
$tree = array(
    'schema' => 'blocks-engine/box-tree/v2', 'source_path' => 'index.html', 'source_hash' => hash('sha256', $html),
    'viewports' => array('desktop' => array('width' => 1440, 'height' => 900)),
    'nodes' => array(
        'n1' => $node(array('n2', 'n4')), 'n2' => $node(array(), array('display' => 'none')),
        'n4' => $node(array('m')), 'm' => $node(array('p')),
        'p' => $node(array(), array('opacity' => '0', 'animation-name' => 'arrive')),
    ),
);
$plan = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array(
    'index.html' => $html, 'box-tree/index.json' => json_encode($tree),
)))->toWordPressSitePlanView()['wordpress_site_plan'];
$assets = array_column($plan['assets'], null, 'target_path');
$css = '';
foreach (ThemeBootstrap::documentStylesheets($plan, 'index.html') as $row) $css .= ($assets[$row['target_path']]['content'] ?? '') . "\n";
if (!str_contains($css, 'animation:arrive 1s ease forwards') || !str_contains($css, '@keyframes arrive')) {
    throw new RuntimeException('The observed animation name must retain its author timing and fill binding, not freeze the hidden first frame');
}
echo "PASS: observed animations retain their direct author binding\n";
