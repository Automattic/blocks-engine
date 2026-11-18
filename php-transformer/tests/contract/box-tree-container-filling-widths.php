<?php
declare(strict_types=1);

require __DIR__ . '/../../vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

// A carrier must not freeze a width its container decided (#6448). On a Wix
// export a divider line draws its rule as a block `::before` stretched across
// a column flexbox, and an image link layer is `inline-size:100%`. Frozen at
// the 1440px capture, both kept 1388px and 706px in a 768px viewport, so the
// page scrolled sideways to 1436px.
// Evidence: n4 <body>, n5 <main>, n8 the divider inside it, n9 the link layer.
$html = file_get_contents(__DIR__ . '/../fixtures/box-tree/index.html');
$html = str_replace('</main>', '<div class="line" data-blocks-engine-node-id="n8"></div><div class="layer" data-blocks-engine-node-id="n9"><span>Link</span></div></main>', $html);
$html = str_replace('</head>', '<style>.line{display:flex;flex-direction:column;align-items:stretch}.line:before{content:"";display:block;width:var(--primaryLineWidth);height:1px;background:#f2f2f2}.layer{display:block;inline-size:100%;block-size:100%}</style></head>', $html);
$tree = json_decode(file_get_contents(__DIR__ . '/../fixtures/box-tree/index.json'), true, 32, JSON_THROW_ON_ERROR);
$tree['source_hash'] = hash('sha256', $html);
foreach (array('n8', 'n9') as $node) $tree['nodes'][$node] = array('children' => array(), 'viewports' => $tree['nodes']['n7']['viewports']);
$tree['nodes']['n5']['children'] = array('n6', 'n7', 'n8', 'n9');

$block = array(
    'display' => 'block', 'position' => 'static', 'float' => 'none', 'box-sizing' => 'content-box', 'max-width' => 'none', 'min-width' => 'auto',
    'padding-top' => '0px', 'padding-right' => '0px', 'padding-bottom' => '0px', 'padding-left' => '0px',
    'margin-top' => '0px', 'margin-right' => '0px', 'margin-bottom' => '0px', 'margin-left' => '0px',
    'border-top' => '0px none rgb(0, 0, 0)', 'border-right' => '0px none rgb(0, 0, 0)', 'border-bottom' => '0px none rgb(0, 0, 0)', 'border-left' => '0px none rgb(0, 0, 0)',
);
$line = array_replace($block, array('display' => 'flex', 'flex-direction' => 'column', 'align-items' => 'stretch', 'height' => '1px'));
$base = $tree['nodes']['n7']['viewports']['desktop']['used_styles'];
$rule = static fn(float $width): array => array(
    'content' => '""',
    'border_box' => array('x' => 0, 'y' => 0, 'width' => $width, 'height' => 1),
    'used_styles' => array_replace($base, $block, array('align-self' => 'auto', 'width' => $width . 'px', 'height' => '1px', 'background-color' => 'rgb(242, 242, 242)')),
);
$observed = array(
    'n4' => array('desktop' => array(1440, $block + array('width' => '1440px')), 'mobile' => array(390, $block + array('width' => '390px'))),
    'n5' => array('desktop' => array(1440, $block + array('width' => '1440px')), 'mobile' => array(390, $block + array('width' => '390px'))),
    'n8' => array('desktop' => array(1388.3, $line + array('width' => '1388.3px'), $rule(1388.3)), 'mobile' => array(368.7, $line + array('width' => '368.7px'), $rule(368.7))),
    'n9' => array('desktop' => array(1440, $block + array('width' => '1440px', 'height' => '20px')), 'mobile' => array(390, $block + array('width' => '390px', 'height' => '20px'))),
);
foreach ($observed as $node => $viewports) {
    foreach ($viewports as $viewport => $evidence) {
        [$width, $styles] = $evidence;
        $tree['nodes'][$node]['viewports'][$viewport]['border_box'] = array('x' => 0, 'width' => $width) + $tree['nodes'][$node]['viewports'][$viewport]['border_box'];
        $tree['nodes'][$node]['viewports'][$viewport]['used_styles'] = array_replace($tree['nodes'][$node]['viewports'][$viewport]['used_styles'], $styles);
        if (isset($evidence[2])) $tree['nodes'][$node]['viewports'][$viewport]['pseudo'] = array('before' => $evidence[2]);
    }
}
$result = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array('index.html' => $html, 'box-tree/index.json' => json_encode($tree))))->toArray();
$css = implode("\n", array_column($result['assets'], 'content'));

function container_filling_assert(bool $condition, string $message, string $css): void
{
    if (!$condition) throw new RuntimeException($message . "\n" . $css);
    echo 'PASS: ' . $message . "\n";
}

foreach (array('1388.3px', '368.7px', 'width:1440px', 'width:390px') as $frozen) {
    container_filling_assert(!str_contains($css, $frozen), 'No carrier freezes the container-decided ' . $frozen, $css);
}
container_filling_assert(1 === preg_match('/::before\{[^}]*(?<![\w-])width:auto/', $css), 'A pseudo-element stretched across its originator restates width:auto', $css);
container_filling_assert(1 === preg_match('/(?<![\w-])width:100%/', $css), 'A logical inline-size:100% is lifted as the width it sets', $css);

echo "Box-tree container-filling widths contract passed\n";
