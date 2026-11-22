<?php
declare(strict_types=1);

// Exercises box-tree decisions, which are opt-in (#7352).
putenv('BLOCKS_ENGINE_BOX_TREE_INTAKE=1');

require __DIR__ . '/../../vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\ThemeBootstrap;

$fail = static function (string $message, string $detail = ''): never {
    fwrite(STDERR, "FAIL: {$message}\n{$detail}\n");
    exit(1);
};
$compile = static function (bool $unequal = false, string $gap = 'grid-column-gap:1.4vw'): string {
    $children = array();
    $markup = '';
    for ($i = 0; $i < 10; ++$i) {
        $children[] = 'tile' . $i;
        $markup .= '<div data-blocks-engine-node-id="tile' . $i . '">Tile ' . $i . '</div>';
    }
    $html = '<!doctype html><html data-blocks-engine-node-id="root"><head data-blocks-engine-node-id="head">'
        . '<style>body{margin:0}</style></head><body data-blocks-engine-node-id="body">'
        . '<div class="tiles" data-blocks-engine-node-id="tiles" style="display:grid;grid-template-columns:repeat(5, 1fr);' . $gap . '">'
        . $markup . '</div></body></html>';
    $template = json_decode((string) file_get_contents(__DIR__ . '/../fixtures/box-tree/index.json'), true, 32, JSON_THROW_ON_ERROR)['nodes']['n7']['viewports']['desktop']['used_styles'];
    $viewports = array('desktop' => array('width' => 1440, 'height' => 900), 'mobile' => array('width' => 402, 'height' => 681));
    $hierarchy = array('root' => array('head', 'body'), 'head' => array(), 'body' => array('tiles'), 'tiles' => $children);
    foreach ($children as $id) $hierarchy[$id] = array();
    $nodes = array();
    foreach ($hierarchy as $id => $descendants) {
        $nodes[$id] = array('children' => $descendants, 'viewports' => array());
        foreach ($viewports as $name => $viewport) {
            $mobile = 'mobile' === $name;
            $tile = str_starts_with($id, 'tile') && 'tiles' !== $id;
            $width = $tile ? ($mobile ? 192.562 : 263.812) : $viewport['width'];
            $height = 'head' === $id ? 0 : ($tile ? 40 : 200);
            $styles = array('width' => $width . 'px', 'height' => $height . 'px');
            if ('head' === $id) $styles['display'] = 'none';
            if ('tiles' === $id) {
                $styles['display'] = 'grid';
                $styles['grid-template-columns'] = $mobile
                    ? ($unequal ? '100px 284px' : '192.562px 192.562px')
                    : '263.812px 263.812px 263.812px 263.812px 263.812px';
                $styles['column-gap'] = $mobile ? '5.628px' : '20.16px';
                $styles['row-gap'] = str_contains($gap, 'grid-gap:') || str_contains($gap, 'grid-row-gap:')
                    ? $styles['column-gap'] : 'normal';
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
    $assets = array_column($plan['assets'], null, 'target_path');
    $css = '';
    foreach (ThemeBootstrap::documentStylesheets($plan, 'index.html') as $row) $css .= ($assets[$row['target_path']]['content'] ?? '') . "\n";
    return $css;
};

$css = $compile();
if (!preg_match('/@media\s*\(max-width:\s*([\d.]+)px\)[^{}]*\{[^{}]*\{[^{}]*grid-template-columns:\s*repeat\(2,\s*1fr\)/', $css, $match) || (float) $match[1] < 402) {
    $fail('the mobile band containing 402 restates the observed two equal tracks', $css);
}
// Remove media blocks so the five-track assertion must match a base rule.
$base = preg_replace('/@media[^{}]*\{(?:[^{}]|\{[^{}]*\})*\}/', '', $css);
if (!preg_match('/grid-template-columns:\s*repeat\(5,\s*1fr\)/', $base)) $fail('the base retains five flexible tracks', $css);
// The emitter may combine the normal row gap with the fluid column gap.
if (!preg_match('/(?:column-gap:\s*|gap:\s*(?:normal\s+)?)1\.4vw(?:;|})/', $css) || str_contains($css, '20.16px')) $fail('the legacy gap alias retains viewport-relative intent', $css);
foreach (array(
    'grid-gap:1.4vw',
    'grid-row-gap:1.4vw;grid-column-gap:1.4vw',
    'gap:20px;grid-gap:1.4vw',
    'grid-gap:20px;gap:1.4vw',
    'grid-gap:1.4vw 1.4vw',
) as $gap) {
    $aliases = $compile(false, $gap);
    if (!preg_match('/(?:column-gap|gap):\\s*1\\.4vw(?:;|})/', $aliases) || str_contains($aliases, '20.16px')) {
        $fail('legacy gap aliases follow the last declaration: ' . $gap, $aliases);
    }
}
$control = $compile(true);
if (preg_match('/repeat\(2\s*,/', $control)) $fail('unequal mobile tracks cannot prove a rewritten repeat count', $control);

echo "PASS: runtime grid repeat counts and legacy gap aliases retain fluid intent\n";
