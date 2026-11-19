<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\ThemeBootstrap;

// A flow landmark and its flex list become one navigation host. The captured
// landmark display must not turn Core's transparent-list items into a stack.
$html = '<!doctype html><html><head><style>'
    . '.icons{display:block;width:300px}.icons ul{display:flex;gap:20px;margin:0;padding:0}'
    . '.icons li{width:27px;height:27px;list-style:none}'
    . '</style></head><body><main data-blocks-engine-node-id="main">'
    . '<div class="icons" data-blocks-engine-node-id="host"><nav data-blocks-engine-node-id="nav"><ul data-blocks-engine-node-id="list">'
    . '<li data-blocks-engine-node-id="one"><a href="/one">One</a></li>'
    . '<li data-blocks-engine-node-id="two"><a href="/two">Two</a></li>'
    . '<li data-blocks-engine-node-id="three"><a href="/three">Three</a></li>'
    . '</ul></nav></div></main></body></html>';
$template = json_decode(file_get_contents(__DIR__ . '/../fixtures/box-tree/index.json'), true, 32, JSON_THROW_ON_ERROR)['nodes']['n7']['viewports']['desktop']['used_styles'];
$node = static function (array $children, array $box, array $styles) use ($template): array {
    return array('children' => $children, 'viewports' => array('desktop' => array(
        'border_box' => array_combine(array('x', 'y', 'width', 'height'), $box),
        'used_styles' => array_replace($template, array('margin-top' => '0px', 'margin-bottom' => '0px'), $styles),
    )));
};
$tree = array(
    'schema' => 'blocks-engine/box-tree/v2', 'source_path' => 'index.html', 'source_hash' => hash('sha256', $html),
    'viewports' => array('desktop' => array('width' => 1440, 'height' => 900)),
    'nodes' => array(
        'main' => $node(array('host'), array(0, 0, 1440, 27), array('width' => '1440px')),
        'host' => $node(array('nav'), array(0, 0, 300, 27), array('display' => 'block', 'position' => 'relative', 'width' => '300px', 'height' => '27px')),
        'nav' => $node(array('list'), array(0, 0, 300, 27), array('display' => 'block', 'width' => '300px', 'height' => '27px')),
        'list' => $node(array('one', 'two', 'three'), array(0, 0, 300, 27), array('display' => 'flex', 'width' => '300px', 'height' => '27px', 'column-gap' => '20px')),
        'one' => $node(array(), array(0, 0, 27, 27), array('display' => 'list-item', 'width' => '27px', 'height' => '27px')),
        'two' => $node(array(), array(47, 0, 27, 27), array('display' => 'list-item', 'width' => '27px', 'height' => '27px')),
        'three' => $node(array(), array(94, 0, 27, 27), array('display' => 'list-item', 'width' => '27px', 'height' => '27px')),
    ),
);
$plan = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array(
    'index.html' => $html, 'box-tree/index.json' => json_encode($tree),
)))->toWordPressSitePlanView()['wordpress_site_plan'];
$markup = $plan['pages'][0]['canonical_block_markup'];
if (!preg_match('/<!-- wp:navigation (\{.*?\}) \/?-->/s', $markup, $match)) throw new RuntimeException('Expected native navigation.');
$attrs = json_decode($match[1], true, 32, JSON_THROW_ON_ERROR);
if (!preg_match('/be-inline-geometry-[a-f0-9]+/', $attrs['className'] ?? '', $carrier)) throw new RuntimeException('Expected observed navigation host carrier.');
$assets = array_column($plan['assets'], null, 'target_path');
$css = '';
foreach (ThemeBootstrap::documentStylesheets($plan, 'index.html') as $row) $css .= $assets[$row['target_path']]['content'] ?? '';
if (!preg_match('/\.' . $carrier[0] . '\.' . $carrier[0] . '\{[^}]*display:flex(?:;|\})/', $css)) {
    throw new RuntimeException('Observed flow host must preserve native flex item layout rather than stacking transparent-list children.');
}
echo "Box-tree navigation flow host: passed\n";
