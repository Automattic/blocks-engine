<?php
declare(strict_types=1);

// Exercises box-tree decisions, which are opt-in (#7352).
putenv('BLOCKS_ENGINE_BOX_TREE_INTAKE=1');

require __DIR__ . '/../../vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\ThemeBootstrap;

// An evidence route retires the author stylesheet (ADR 0005), and with it the
// author's custom-property definitions. The responsive-typography rescue
// class restates author font-size rules (`calc(var(--large) * 1rem)`) after
// the box-tree carrier and outranks it; with `--large` undefined the font
// size is invalid at computed-value time and falls back to the inherited
// size. On an evidence route the box-tree carrier owns font-size, so no
// delivered rule may read an author custom property the route does not define.
$html = '<!doctype html><html data-blocks-engine-node-id="n1"><head data-blocks-engine-node-id="n2"><style>'
    . ':root{--bg:#ece4da;--fg:#36302a;--large:1.2}.dark{--bg:#111111;--fg:#ffffff}'
    . 'body{margin:0;background-color:var(--bg);color:var(--fg);font-family:Georgia,serif}'
    . '.large{font-size:calc(var(--large) * 1rem)}'
    . '@media (min-width:768px){.large{font-size:calc((var(--large) - 1) * 1.2vw + 1rem)}}'
    . '</style></head><body data-blocks-engine-node-id="n4"><main data-blocks-engine-node-id="m">'
    . '<p class="large" data-blocks-engine-node-id="p">Hair Color</p>'
    . '<section class="dark" data-blocks-engine-node-id="d"><p data-blocks-engine-node-id="q">Dark band</p></section>'
    . '</main></body></html>';
$template = json_decode((string) file_get_contents(__DIR__ . '/../fixtures/box-tree/index.json'), true, 32, JSON_THROW_ON_ERROR)['nodes']['n7']['viewports']['desktop']['used_styles'];
$base = array('font-family' => 'Georgia, serif', 'color' => 'rgb(54, 48, 42)');
$node = static function (array $children, array $box, array $styles) use ($template, $base): array {
    return array('children' => $children, 'viewports' => array('desktop' => array(
        'border_box' => array_combine(array('x', 'y', 'width', 'height'), $box),
        'used_styles' => array_replace($template, $base, $styles),
    )));
};
$tree = array(
    'schema' => 'blocks-engine/box-tree/v2',
    'source_path' => 'index.html',
    'source_hash' => hash('sha256', $html),
    'viewports' => array('desktop' => array('width' => 1440, 'height' => 900)),
    'nodes' => array(
        'n1' => $node(array('n2', 'n4'), array(0, 0, 1440, 900), array('width' => '1440px', 'height' => '900px', 'color' => 'rgb(0, 0, 0)', 'font-family' => 'Times')),
        'n2' => $node(array(), array(0, 0, 0, 0), array('display' => 'none')),
        'n4' => $node(array('m'), array(0, 0, 1440, 120), array('width' => '1440px', 'background-color' => 'rgb(236, 228, 218)')),
        'm' => $node(array('p', 'd'), array(0, 0, 1440, 120), array('width' => '1440px')),
        'p' => $node(array(), array(0, 0, 1440, 29), array('width' => '1440px', 'height' => '29px', 'font-size' => '19.456px')),
        'd' => $node(array('q'), array(0, 29, 1440, 60), array('width' => '1440px', 'color' => 'rgb(255, 255, 255)', 'background-color' => 'rgb(17, 17, 17)')),
        'q' => $node(array(), array(0, 29, 1440, 24), array('width' => '1440px', 'height' => '24px', 'color' => 'rgb(255, 255, 255)')),
    ),
);
$compiled = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array('index.html' => $html, 'box-tree/index.json' => json_encode($tree))));
$plan = $compiled->toWordPressSitePlanView()['wordpress_site_plan'];
$fail = static function (string $message, string $detail): never {
    fwrite(STDERR, "FAIL: {$message}\n{$detail}\n");
    exit(1);
};
if (empty($plan['pages'][0]['document_metadata']['engine_owned_styles'])) $fail('the route is engine-owned', '');
$assets = array_column($plan['assets'], null, 'target_path');
$css = '';
foreach (ThemeBootstrap::documentStylesheets($plan, 'index.html') as $row) $css .= ($assets[$row['target_path']]['content'] ?? '') . "\n";
foreach (array('--large', '--bg', '--fg') as $name) {
    if (preg_match('/var\\(' . $name . '\\b/', $css) && !preg_match('/' . $name . '\\s*:/', $css)) $fail("delivered CSS reads no undefined author property {$name}", $css);
}
if (!preg_match('/\\.be-inline-geometry-[a-f0-9]{16}[^{}]*\\{[^{}]*font-size:(?![^;}]*var\\()[^;}]+/', $css)) $fail('the box-tree carrier keeps the captured font size', $css);
echo "PASS: evidence-route typography reads no undefined author custom property\n";
