<?php
declare(strict_types=1);

// Exercises box-tree decisions, which are opt-in (#7352).
putenv('BLOCKS_ENGINE_BOX_TREE_INTAKE=1');

require __DIR__ . '/../../vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\CssCascade;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\ThemeBootstrap;

$fail = static function (string $message, string $detail = ''): never {
    fwrite(STDERR, "FAIL: {$message}\n{$detail}\n");
    exit(1);
};
$html = '<!doctype html><html data-blocks-engine-node-id="root"><head data-blocks-engine-node-id="head"><style>'
    . 'body{margin:0;font-family:Arial,sans-serif}'
    . '@media screen and (max-width:767px) and (orientation:portrait){.lead{font-size:calc(.02 * min(100vh,900px) + 10px)}}'
    . '@media screen and (min-width:768px), screen and (orientation:landscape){.lead{font-size:20px}}'
    . '</style></head><body data-blocks-engine-node-id="body"><main data-blocks-engine-node-id="main">'
    . '<p class="lead" data-blocks-engine-node-id="lead">A neutral introduction</p>'
    . '</main></body></html>';
$template = json_decode((string) file_get_contents(__DIR__ . '/../fixtures/box-tree/index.json'), true, 32, JSON_THROW_ON_ERROR)['nodes']['n7']['viewports']['desktop']['used_styles'];
$viewports = array('desktop' => array('width' => 1440, 'height' => 900), 'mobile' => array('width' => 402, 'height' => 681));
$nodes = array();
foreach (array('root' => array('head', 'body'), 'head' => array(), 'body' => array('main'), 'main' => array('lead'), 'lead' => array()) as $id => $children) {
    $nodes[$id] = array('children' => $children, 'viewports' => array());
    foreach ($viewports as $name => $viewport) {
        $width = $viewport['width'];
        $height = 'root' === $id ? $viewport['height'] : ('head' === $id ? 0 : 40);
        $styles = array(
            'width' => $width . 'px', 'height' => $height . 'px',
            'font-family' => 'Arial, sans-serif', 'font-size' => '16px',
            'color' => 'rgb(0, 0, 0)',
        );
        if ('head' === $id) $styles['display'] = 'none';
        if ('lead' === $id) $styles['font-size'] = 'mobile' === $name ? '23.62px' : '20px';
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
if (!preg_match('/@media\s*\(max-width:\s*767px\)[^{}]*\{[^{}]*\{[^{}]*font-size:[^;}]*min\(100vh/', $css)) $fail('the narrow band retains the author viewport-height expression', $css);
if (str_contains($css, '23.62px')) $fail('the captured mobile font size is not frozen', $css);

if (!CssCascade::mediaConditionApplies('(orientation: portrait)', 402, 16.0, 681.0)) $fail('the phone capture is portrait');
if (CssCascade::mediaConditionApplies('(orientation: portrait)', 1440, 16.0, 900.0)) $fail('the desktop capture is not portrait');
if (CssCascade::mediaConditionApplies('(orientation: portrait)', 402)) $fail('orientation is unknown without height');
foreach (array('px' => 681, 'em' => 42.5625, 'rem' => 42.5625) as $unit => $value) {
    $condition = '(min-height: ' . $value . $unit . ') and (max-height: ' . $value . $unit . ')';
    if (!CssCascade::mediaConditionApplies($condition, 402, 16.0, 681.0)) $fail('height bounds include equality in ' . $unit);
    if (CssCascade::mediaConditionApplies($condition, 402, 16.0, 680.0)) $fail('height is included in the media memo key');
    if (CssCascade::mediaConditionApplies($condition, 402)) $fail('height bounds are unknown without height');
}
echo "PASS: orientation-gated typography retains author viewport-height intent\n";
