<?php
declare(strict_types=1);

require __DIR__ . '/../../vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

// A carrier must not freeze a size its container decided (#6448). Lovable
// pages restyled from box-tree evidence pinned <main> at 1440px, its
// `mx-auto max-w-7xl` sections at 1280px and their `md:grid-cols-2` tracks at
// 600px, so a 768px viewport scrolled sideways to 1440px. Each such capture is
// restated relative to the container, which reproduces the capture exactly.
// Evidence: n4 <body>, n5 <main> (centred grid), n6 <h1> and n7 <p> its items.
$html = file_get_contents(__DIR__ . '/../fixtures/box-tree/index.html');
$tree = json_decode(file_get_contents(__DIR__ . '/../fixtures/box-tree/index.json'), true, 32, JSON_THROW_ON_ERROR);

/** @param array<string, array<string, array{0: array<string, float>, 1: array<string, string>}>> $observed node => viewport => [border box, used styles] */
function flow_sizing_css(string $html, array $tree, array $observed): string
{
    $tree['source_hash'] = hash('sha256', $html);
    foreach ($observed as $node => $viewports) {
        foreach ($viewports as $viewport => [$box, $styles]) {
            $tree['nodes'][$node]['viewports'][$viewport]['border_box'] = $box + $tree['nodes'][$node]['viewports'][$viewport]['border_box'];
            $tree['nodes'][$node]['viewports'][$viewport]['used_styles'] = array_replace($tree['nodes'][$node]['viewports'][$viewport]['used_styles'], $styles);
        }
    }
    $result = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array('index.html' => $html, 'box-tree/index.json' => json_encode($tree))))->toArray();
    return implode("\n", array_column($result['assets'], 'content'));
}

function flow_sizing_assert(bool $condition, string $message, string $css): void
{
    if (!$condition) throw new RuntimeException($message . "\n" . $css);
    echo 'PASS: ' . $message . "\n";
}

$block = array(
    'display' => 'block', 'position' => 'static', 'float' => 'none', 'box-sizing' => 'border-box', 'max-width' => 'none',
    'padding-left' => '0px', 'padding-right' => '0px', 'margin-left' => '0px', 'margin-right' => '0px',
    'border-left' => '0px none rgb(0, 0, 0)', 'border-right' => '0px none rgb(0, 0, 0)',
    'justify-self' => 'auto', 'justify-items' => 'normal', 'aspect-ratio' => 'auto',
);
$item = static fn(float $x, float $width): array => array(array('x' => $x, 'width' => $width), $block + array('width' => $width . 'px', 'color' => 'rgb(1, 2, 3)'));
$observed = array(
    'n4' => array(
        'desktop' => array(array('x' => 0, 'width' => 1440), $block + array('width' => '1440px')),
        'mobile' => array(array('x' => 0, 'width' => 390), $block + array('width' => '390px')),
    ),
    'n5' => array(
        'desktop' => array(array('x' => 80, 'width' => 1280), array_replace($block, array('display' => 'grid', 'width' => '1280px', 'max-width' => '1280px', 'margin-left' => '80px', 'margin-right' => '80px', 'padding-left' => '20px', 'padding-right' => '20px', 'column-gap' => '40px', 'grid-template-columns' => '600px 600px'))),
        'mobile' => array(array('x' => 0, 'width' => 390), array_replace($block, array('display' => 'grid', 'width' => '390px', 'max-width' => '1280px', 'padding-left' => '20px', 'padding-right' => '20px', 'column-gap' => '40px', 'grid-template-columns' => '350px'))),
    ),
    'n6' => array('desktop' => $item(100, 600), 'mobile' => $item(20, 350)),
    'n7' => array('desktop' => $item(740, 600), 'mobile' => $item(20, 350)),
);

$css = flow_sizing_css($html, $tree, $observed);
foreach (array('width:1440px', 'width:1280px', 'width:600px', 'width:350px', 'margin-left:80px', '600px 600px') as $frozen) {
    flow_sizing_assert(!preg_match('/(?<![\w-])' . preg_quote($frozen, '/') . '/', $css), 'No carrier freezes the container-decided ' . $frozen, $css);
}
flow_sizing_assert(str_contains($css, 'margin-right:auto;margin-left:auto') && str_contains($css, 'max-width:1280px'), 'A box centred at its max-width keeps auto margins and its max-width on the carrier', $css);
flow_sizing_assert(str_contains($css, 'grid-template-columns:repeat(2, minmax(0, 1fr))') && str_contains($css, 'grid-template-columns:repeat(1, minmax(0, 1fr))'), 'Tracks filling the grid become fractions at every capture', $css);
flow_sizing_assert(str_contains($css, 'width:100%'), 'A grid item spanning its tracks fills its grid area', $css);

// A box narrower than its container did not take its width from it.
$observed['n7']['desktop'] = $item(740, 300);
$css = flow_sizing_css($html, $tree, $observed);
flow_sizing_assert((bool) preg_match('/(?<![\w-])width:300px/', $css), 'A width the container did not decide keeps its pixels', $css);

// Only what varies between captures can leak a wrong size between them. A
// fixed-width <main> whose <h1> fills it at the same 600px at every capture
// was never resized by its container: its width stays frozen, untouched.
$fixed = array_replace($block, array('width' => '600px'));
$fixedObserved = array(
    'n4' => $observed['n4'],
    'n5' => array('desktop' => array(array('x' => 0, 'width' => 600), $fixed), 'mobile' => array(array('x' => 0, 'width' => 600), $fixed)),
    'n6' => array('desktop' => array(array('x' => 0, 'width' => 600), $fixed + array('color' => 'rgb(1, 2, 3)')), 'mobile' => array(array('x' => 0, 'width' => 600), $fixed + array('color' => 'rgb(1, 2, 3)'))),
);
$css = flow_sizing_css($html, $tree, $fixedObserved);
flow_sizing_assert(!str_contains($css, 'width:auto') && (bool) preg_match('/(?<![\w-])width:600px/', $css), 'A fill observed identically at every capture keeps its frozen width', $css);

echo "Box-tree flow sizing contract passed\n";
