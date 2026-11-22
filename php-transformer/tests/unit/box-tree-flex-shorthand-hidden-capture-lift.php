<?php
declare(strict_types=1);

// Exercises box-tree decisions, which are opt-in (#7352).
putenv('BLOCKS_ENGINE_BOX_TREE_INTAKE=1');

require __DIR__ . '/../../vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\BoxTreeFluidIntent;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\ThemeBootstrap;

$fail = static function (string $message, string $detail = ''): never {
    fwrite(STDERR, "FAIL: {$message}\n{$detail}\n");
    exit(1);
};
$html = '<!doctype html><html data-blocks-engine-node-id="root"><head data-blocks-engine-node-id="head"><style>'
    . 'body{margin:0;font-family:Arial,sans-serif}.bar{display:none}.bar .title{flex:0 0 50%}'
    . '@media (max-width:799px){.bar{display:flex}.bar .title{flex:1 0 calc(100% - 50px)}}'
    . '</style></head><body data-blocks-engine-node-id="body"><main data-blocks-engine-node-id="main">'
    . '<div class="bar" data-blocks-engine-node-id="bar"><p class="title" data-blocks-engine-node-id="title">A neutral title</p></div>'
    . '</main></body></html>';
$template = json_decode((string) file_get_contents(__DIR__ . '/../fixtures/box-tree/index.json'), true, 32, JSON_THROW_ON_ERROR)['nodes']['n7']['viewports']['desktop']['used_styles'];
$viewports = array('desktop' => array('width' => 1440, 'height' => 900), 'mobile' => array('width' => 402, 'height' => 681));
$nodes = array();
foreach (array('root' => array('head', 'body'), 'head' => array(), 'body' => array('main'), 'main' => array('bar'), 'bar' => array('title'), 'title' => array()) as $id => $children) {
    $nodes[$id] = array('children' => $children, 'viewports' => array());
    foreach ($viewports as $name => $viewport) {
        $hidden = 'desktop' === $name && in_array($id, array('bar', 'title'), true);
        $width = $hidden ? 0 : $viewport['width'];
        $height = 'root' === $id ? $viewport['height'] : ('head' === $id || $hidden ? 0 : 40);
        $styles = array(
            'width' => $width . 'px', 'height' => $height . 'px',
            'font-family' => 'Arial, sans-serif', 'font-size' => '16px',
            'color' => 'rgb(0, 0, 0)',
        );
        if ('head' === $id) $styles['display'] = 'none';
        if ('bar' === $id) $styles['display'] = 'desktop' === $name ? 'none' : 'flex';
        if ('title' === $id) {
            $styles['flex-grow'] = 'desktop' === $name ? '0' : '1';
            $styles['flex-shrink'] = '0';
            $styles['flex-basis'] = 'desktop' === $name ? '50%' : 'calc(100% - 50px)';
        }
        $nodes[$id]['viewports'][$name] = array(
            'border_box' => array('x' => 0, 'y' => 0, 'width' => $width, 'height' => $height),
            'used_styles' => array_replace($template, $styles),
        );
    }
}
$tree = array(
    'schema' => 'blocks-engine/box-tree/v2', 'source_path' => 'index.html',
    'source_hash' => hash('sha256', $html), 'viewports' => $viewports, 'nodes' => $nodes,
);
$compiled = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array(
    'index.html' => $html, 'box-tree/index.json' => json_encode($tree),
)));
$plan = $compiled->toWordPressSitePlanView()['wordpress_site_plan'];
if (empty($plan['pages'][0]['document_metadata']['engine_owned_styles'])) $fail('the route is engine-owned');
$assets = array_column($plan['assets'], null, 'target_path');
$css = '';
foreach (ThemeBootstrap::documentStylesheets($plan, 'index.html') as $row) $css .= ($assets[$row['target_path']]['content'] ?? '') . "\n";
foreach (array('flex-basis:calc(100% - 50px)', 'flex-grow:1') as $declaration) {
    if (!preg_match('/@media\s*\(max-width:\s*799px\)[^{}]*\{[^{}]*\{[^{}]*' . preg_quote($declaration, '/') . '(?:;|\})/', $css)) $fail('the title lifts ' . $declaration . ' to the author breakpoint', $css);
    if (preg_match('/@media\s*\(max-width:\s*402px\)[^{}]*\{[^{}]*\{[^{}]*' . preg_quote($declaration, '/') . '(?:;|\})/', $css)) $fail('the title is not frozen at the capture width', $css);
}

$expand = new ReflectionMethod(BoxTreeFluidIntent::class, 'flexLonghands');
foreach (array(
    '1 0 calc(100% - 50px)' => array('1', '0', 'calc(100% - 50px)'),
    'none' => array('0', '0', 'auto'), 'auto' => array('1', '1', 'auto'),
    'initial' => array('0', '1', 'auto'), '1' => array('1', '1', '0%'),
    '30px' => array('1', '1', '30px'), '2 3' => array('2', '3', '0%'),
    '30px 2 3' => array('2', '3', '30px'),
    'var(--x)' => array(), 'inherit' => array(), 'unset' => array(),
    'revert' => array(), 'revert-layer' => array(),
) as $value => $expected) {
    $expected = array() === $expected ? array() : array_combine(array('flex-grow', 'flex-shrink', 'flex-basis'), $expected);
    if ($expand->invoke(null, $value) !== $expected) $fail('flex expansion: ' . $value);
}
echo "PASS: flex shorthand lifts across an unrendered capture to the author breakpoint\n";
