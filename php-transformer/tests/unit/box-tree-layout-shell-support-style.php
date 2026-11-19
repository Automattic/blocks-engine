<?php
declare(strict_types=1);

require __DIR__ . '/../../vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\ThemeBootstrap;

// #6445: an evidence route retires the author stylesheet, so a card link kept
// as a layout shell (`<a class="flex ... border p-5 rounded-2xl bg-card">`)
// keeps its box only through the box-tree carrier. The carrier maps the
// invariant padding, background and radius to block style attributes, but a
// layout shell saves its wrapper list only and has no block supports: those
// attributes rendered nowhere, and the card's text sat 20px off its source.
$html = '<!doctype html><html data-blocks-engine-node-id="n1"><head data-blocks-engine-node-id="n2"><style>'
    . 'body{margin:0}a{color:inherit;text-decoration:none}.flex{display:flex}.items-center{align-items:center}.gap-4{gap:1rem}.p-5{padding:1.25rem}'
    . '.border{border:1px solid #ddd}.rounded-2xl{border-radius:16px}.bg-card{background:#fff}.h-12{height:3rem}.w-12{width:3rem}.bg-soft{background:#eef}.grid{display:grid}'
    . '</style></head><body data-blocks-engine-node-id="n4"><main data-blocks-engine-node-id="m"><div class="grid" data-blocks-engine-node-id="g">'
    . '<a class="flex items-center gap-4 p-5 border rounded-2xl bg-card" href="/menu/" data-blocks-engine-node-id="a">'
    . '<div class="flex h-12 w-12 bg-soft" data-blocks-engine-node-id="i"></div>'
    . '<div data-blocks-engine-node-id="t"><h3 data-blocks-engine-node-id="h">Food / Menu Option</h3><p data-blocks-engine-node-id="p">Browse the dishes we can serve.</p></div>'
    . '<span data-blocks-engine-node-id="r">→</span></a></div></main></body></html>';
$template = json_decode((string) file_get_contents(__DIR__ . '/../fixtures/box-tree/index.json'), true, 32, JSON_THROW_ON_ERROR)['nodes']['n7']['viewports']['desktop']['used_styles'];
$body = array('display' => 'block', 'box-sizing' => 'border-box', 'font-family' => 'Inter, sans-serif', 'font-weight' => '400', 'font-size' => '16px', 'line-height' => '24px', 'color' => 'rgb(0, 0, 0)');
$node = static function (array $children, array $box, array $styles) use ($template, $body): array {
    return array('children' => $children, 'viewports' => array('desktop' => array(
        'border_box' => array_combine(array('x', 'y', 'width', 'height'), $box),
        'used_styles' => array_replace($template, $body, $styles),
    )));
};
$edge = '1px solid rgb(221, 221, 221)';
$card = array(
    'display' => 'flex', 'align-items' => 'center', 'column-gap' => '16px', 'row-gap' => '16px', 'width' => '1440px', 'height' => '90px',
    'padding-top' => '20px', 'padding-right' => '20px', 'padding-bottom' => '20px', 'padding-left' => '20px',
    'border-top' => $edge, 'border-right' => $edge, 'border-bottom' => $edge, 'border-left' => $edge,
    'border-radius' => '16px',
    'background-color' => 'rgb(255, 255, 255)',
);
$tree = array(
    'schema' => 'blocks-engine/box-tree/v2',
    'source_path' => 'index.html',
    'source_hash' => hash('sha256', $html),
    'viewports' => array('desktop' => array('width' => 1440, 'height' => 900)),
    'nodes' => array(
        'n1' => $node(array('n2', 'n4'), array(0, 0, 1440, 900), array('width' => '1440px', 'height' => '900px')),
        'n2' => $node(array(), array(0, 0, 0, 0), array('display' => 'none')),
        'n4' => $node(array('m'), array(0, 0, 1440, 90), array('width' => '1440px')),
        'm' => $node(array('g'), array(0, 0, 1440, 90), array('width' => '1440px')),
        'g' => $node(array('a'), array(0, 0, 1440, 90), array('display' => 'grid', 'width' => '1440px')),
        'a' => $node(array('i', 't', 'r'), array(0, 0, 1440, 90), $card),
        'i' => $node(array(), array(21, 21, 48, 48), array('display' => 'flex', 'background-color' => 'rgb(238, 238, 255)', 'width' => '48px', 'height' => '48px')),
        't' => $node(array('h', 'p'), array(85, 21, 1303, 48), array('width' => '1303px', 'height' => '48px')),
        'h' => $node(array(), array(85, 21, 1334, 24), array('font-weight' => '700', 'width' => '1334px', 'height' => '24px')),
        'p' => $node(array(), array(85, 45, 1334, 24), array('width' => '1334px', 'height' => '24px')),
        'r' => $node(array(), array(1404, 33, 16, 24), array('width' => '16px', 'height' => '24px')),
    ),
);

$compiled = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array('index.html' => $html, 'box-tree/index.json' => json_encode($tree))));
$markup = (string) $compiled->toArray()['serialized_blocks'];
$fail = static function (string $message) use ($markup): never {
    fwrite(STDERR, "FAIL: {$message}\n{$markup}\n");
    exit(1);
};
$plan = $compiled->toWordPressSitePlanView()['wordpress_site_plan'];
$assets = array_column($plan['assets'], null, 'target_path');
$css = '';
foreach (ThemeBootstrap::documentStylesheets($plan, 'index.html') as $row) $css .= ($assets[$row['target_path']]['content'] ?? '') . "\n";

if (!preg_match('/<!-- wp:[a-z0-9-]+\/layout-shell (\{.*?\}) -->(<a\b[^>]*>)/', $markup, $shell)) $fail('the card link stays a layout shell around its editable content');
$attrs = json_decode($shell[1], true, 32, JSON_THROW_ON_ERROR);
if (isset($attrs['style'])) $fail('the shell keeps no style attribute it cannot render: ' . $shell[1]);
$saved = $shell[2];
if ((string) ($attrs['wrappers'][0]['attributes']['style'] ?? '') !== html_entity_decode((string) (preg_match('/\bstyle="([^"]*)"/', $saved, $m) ? $m[1] : ''), ENT_QUOTES | ENT_HTML5, 'UTF-8')) $fail('the saved wrapper and its attributes agree: ' . $saved);

// Each property the card paints renders on the saved link, inline or through
// its carrier class.
preg_match('/\bclass="([^"]*)"/', $saved, $classes);
$declarations = (string) ($attrs['wrappers'][0]['attributes']['style'] ?? '');
foreach (preg_split('/\s+/', $classes[1] ?? '') ?: array() as $class) {
    if (str_starts_with($class, 'be-inline-geometry-') && preg_match_all('/\.' . preg_quote($class, '/') . '(?:\.' . preg_quote($class, '/') . ')?\{([^}]*)\}/', $css, $rules)) $declarations .= ';' . implode(';', $rules[1]);
}
$declarations = str_replace(' ', '', $declarations);
foreach (array('padding-left:20px|padding:20px', 'padding-top:20px|padding:20px', 'background-color:rgb\(255,255,255\)|background-color:var\([^;]*rgb\(255,255,255\)\)', 'border-radius:16px') as $expected) {
    if (!preg_match('/(?:^|;)(?:' . $expected . ')(?:;|$)/', $declarations)) $fail("the card renders {$expected}: {$declarations}");
}

fwrite(STDOUT, "box-tree layout shell support style: passed\n");
