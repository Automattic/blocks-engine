<?php
declare(strict_types=1);
require __DIR__ . '/../../vendor/autoload.php';
use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\ThemeBootstrap;
// Squarespace gates its site-width system behind a custom-property support
// test: `@supports (--test-custom-property: true){body{--sqs-site-max-width:…}}`.
// Every browser that evaluates `@supports` supports custom properties, so the
// definition applies unconditionally. Reading the gate as unknown resolved
// `var(--max, 1500px)` to its fallback, and the 800px content column of every
// fluid-engine grid widened to the full viewport.
$def = '@supports (--test-custom-property: true){body{--max:var(--page)}}';
$html = '<!doctype html><html data-blocks-engine-node-id="n1"><head data-blocks-engine-node-id="n2"><style>'
    . ':root{--page:800px}' . $def
    . 'body{margin:0}.grid{display:grid;--cell:calc((var(--max, 1500px) - 30px) / 4);column-gap:10px;grid-template-columns:minmax(0px,1fr) repeat(4,minmax(0,var(--cell))) minmax(0px,1fr)}'
    . '.item{grid-column:2 / 6}'
    . '</style></head><body data-blocks-engine-node-id="n4"><main data-blocks-engine-node-id="m"><div class="grid" data-blocks-engine-node-id="g">'
    . '<p class="item" data-blocks-engine-node-id="p">Centred column text</p>'
    . '</div></main></body></html>';
$template = json_decode((string) file_get_contents(__DIR__ . '/../fixtures/box-tree/index.json'), true, 32, JSON_THROW_ON_ERROR)['nodes']['n7']['viewports']['desktop']['used_styles'];
$node = static function (array $children, array $box, array $styles) use ($template): array {
    return array('children' => $children, 'viewports' => array('desktop' => array(
        'border_box' => array_combine(array('x', 'y', 'width', 'height'), $box),
        'used_styles' => array_replace($template, $styles),
    )));
};
// Rendered at 1440: --max = 800px, cell = 192.5px, column = 800px centred at x 320.
$tree = array(
    'schema' => 'blocks-engine/box-tree/v2', 'source_path' => 'index.html', 'source_hash' => hash('sha256', $html),
    'viewports' => array('desktop' => array('width' => 1440, 'height' => 900)),
    'nodes' => array(
        'n1' => $node(array('n2', 'n4'), array(0, 0, 1440, 900), array('width' => '1440px')),
        'n2' => $node(array(), array(0, 0, 0, 0), array('display' => 'none')),
        'n4' => $node(array('m'), array(0, 0, 1440, 40), array('width' => '1440px')),
        'm' => $node(array('g'), array(0, 0, 1440, 40), array('width' => '1440px')),
        'g' => $node(array('p'), array(0, 0, 1440, 40), array('width' => '1440px', 'display' => 'grid', 'column-gap' => '10px', 'grid-template-columns' => '310px 192.5px 192.5px 192.5px 192.5px 310px')),
        'p' => $node(array(), array(320, 0, 800, 40), array('width' => '800px', 'height' => '40px', 'grid-column-start' => '2', 'grid-column-end' => '6')),
    ),
);
$plan = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array('index.html' => $html, 'box-tree/index.json' => json_encode($tree))))->toWordPressSitePlanView()['wordpress_site_plan'];
$assets = array_column($plan['assets'], null, 'target_path');
$css = '';
foreach (ThemeBootstrap::documentStylesheets($plan, 'index.html') as $row) $css .= ($assets[$row['target_path']]['content'] ?? '') . "\n";
preg_match_all('/grid-template-columns:[^;}]+/', $css, $m);
$templates = implode("\n", array_unique($m[0]));
if (str_contains($templates, '1500px')) {
    fwrite(STDERR, "FAIL: the cell width resolves the @supports-gated definition, not the fallback\n{$templates}\n");
    exit(1);
}
if (!str_contains($templates, '800px')) {
    fwrite(STDERR, "FAIL: the cell width reads --max = 800px\n{$templates}\n");
    exit(1);
}
echo "PASS: custom-property @supports gates apply unconditionally\n";
