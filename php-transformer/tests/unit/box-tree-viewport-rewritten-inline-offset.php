<?php
declare(strict_types=1);
require __DIR__ . '/../../vendor/autoload.php';
use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\ThemeBootstrap;
// A script positions an absolute decoration under centred text by writing its
// inline `left` per viewport (42px wide, 125px narrow). The capture document
// holds only the wide value. Box-tree evidence observed both, so the projection
// restates each at its own viewport; an important inline carrier must not
// freeze the wide offset over the narrow viewport rule.
$html = '<!doctype html><html data-blocks-engine-node-id="n1"><head data-blocks-engine-node-id="n2"><style>'
    . 'body{margin:0}.wrap{position:relative}.mark{position:absolute;top:4px;height:2px;background:#000}'
    . '</style></head><body data-blocks-engine-node-id="n4"><main data-blocks-engine-node-id="m"><div class="wrap" data-blocks-engine-node-id="w">'
    . '<p data-blocks-engine-node-id="p">Centred heading text</p>'
    . '<div class="mark" style="left: 42px; width: 100px" data-blocks-engine-node-id="d"><span data-blocks-engine-node-id="s">x</span></div>'
    . '</div></main></body></html>';
$template = json_decode((string) file_get_contents(__DIR__ . '/../fixtures/box-tree/index.json'), true, 32, JSON_THROW_ON_ERROR)['nodes']['n7']['viewports']['desktop']['used_styles'];
$node = static function (array $children, array $wide, array $narrow, array $styles, array $narrowStyles = array()) use ($template): array {
    $box = static fn (array $b): array => array_combine(array('x', 'y', 'width', 'height'), $b);
    return array('children' => $children, 'viewports' => array(
        'desktop' => array('border_box' => $box($wide), 'used_styles' => array_replace($template, $styles)),
        'mobile' => array('border_box' => $box($narrow), 'used_styles' => array_replace($template, $styles, $narrowStyles)),
    ));
};
$tree = array(
    'schema' => 'blocks-engine/box-tree/v2', 'source_path' => 'index.html', 'source_hash' => hash('sha256', $html),
    'viewports' => array('desktop' => array('width' => 1440, 'height' => 900), 'mobile' => array('width' => 402, 'height' => 844)),
    'nodes' => array(
        'n1' => $node(array('n2', 'n4'), array(0, 0, 1440, 900), array(0, 0, 402, 844), array('width' => '1440px'), array('width' => '402px')),
        'n2' => $node(array(), array(0, 0, 0, 0), array(0, 0, 0, 0), array('display' => 'none')),
        'n4' => $node(array('m'), array(0, 0, 1440, 40), array(0, 0, 402, 40), array('width' => '1440px'), array('width' => '402px')),
        'm' => $node(array('w'), array(0, 0, 1440, 40), array(0, 0, 402, 40), array('width' => '1440px'), array('width' => '402px')),
        'w' => $node(array('p', 'd'), array(0, 0, 1440, 40), array(0, 0, 402, 40), array('width' => '1440px', 'position' => 'relative'), array('width' => '402px')),
        'p' => $node(array(), array(0, 0, 1440, 20), array(0, 0, 402, 20), array('width' => '1440px', 'height' => '20px'), array('width' => '402px')),
        'd' => $node(array('s'), array(42, 4, 100, 2), array(125, 4, 100, 2), array('position' => 'absolute', 'top' => '4px', 'left' => '42px', 'width' => '100px', 'height' => '2px', 'background-color' => 'rgb(0, 0, 0)'), array('left' => '125px')),
        's' => $node(array(), array(42, 4, 10, 2), array(125, 4, 10, 2), array('display' => 'inline', 'width' => 'auto', 'height' => 'auto')),
    ),
);
$plan = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array('index.html' => $html, 'box-tree/index.json' => json_encode($tree))))->toWordPressSitePlanView()['wordpress_site_plan'];
$assets = array_column($plan['assets'], null, 'target_path');
$css = '';
foreach (ThemeBootstrap::documentStylesheets($plan, 'index.html') as $row) $css .= ($assets[$row['target_path']]['content'] ?? '') . "\n";
if (preg_match('/left:\s*42px\s*!important/', $css)) {
    fwrite(STDERR, "FAIL: the viewport-rewritten inline offset is frozen as an important carrier\n{$css}\n");
    exit(1);
}
if (!preg_match('/@media \(max-width:\s*402px\)\{[^@]*left:\s*125px/', $css)) {
    fwrite(STDERR, "FAIL: the narrow viewport restates its observed offset\n{$css}\n");
    exit(1);
}
if (!preg_match('/width:\s*100px\s*!important/', $css)) {
    fwrite(STDERR, "FAIL: a constant inline width still rides the inline carrier\n{$css}\n");
    exit(1);
}
echo "PASS: viewport-rewritten inline offsets yield to box-tree viewport rules\n";
