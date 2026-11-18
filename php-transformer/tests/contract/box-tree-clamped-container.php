<?php
declare(strict_types=1);

require __DIR__ . '/../../vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

// A carrier must not freeze a size the author's fluid CSS decided (#6448).
// A Tailwind `container` (`width:100%;max-width:80rem;margin:0 auto`) is
// 1280px in a 1440px capture: its max-width clamps the 100%. Its grid is
// `sm:grid-cols-2 lg:grid-cols-4`, a breakpoint between the 390px and 1440px
// captures. Frozen, both pinned every width in between to 1280px, so a 768px
// viewport scrolled sideways, and the 390px capture's pixels did the same in
// every viewport narrower than it.
// Evidence: n4 <body>, n5 <main>, n8 the container <div> inside it, n6 <h1>
// and n7 <p> its items.
$html = file_get_contents(__DIR__ . '/../fixtures/box-tree/index.html');
$html = str_replace(array('<h1 ', 'Evidence only.</p>'), array('<div class="container" data-blocks-engine-node-id="n8"><h1 ', 'Evidence only.</p></div>'), $html);
$html = str_replace('</head>', '<style>.container{display:grid;gap:40px;box-sizing:border-box;width:100%;max-width:1280px;margin-left:auto;margin-right:auto;padding-left:20px;padding-right:20px}@media (min-width:640px){.container{grid-template-columns:repeat(2,minmax(0,1fr))}}@media (min-width:1024px){.container{grid-template-columns:repeat(4,minmax(0,1fr))}}</style></head>', $html);
$tree = json_decode(file_get_contents(__DIR__ . '/../fixtures/box-tree/index.json'), true, 32, JSON_THROW_ON_ERROR);
$tree['source_hash'] = hash('sha256', $html);
$tree['nodes']['n8'] = array('children' => $tree['nodes']['n5']['children'], 'viewports' => $tree['nodes']['n5']['viewports']);
$tree['nodes']['n5']['children'] = array('n8');

$block = array(
    'display' => 'block', 'position' => 'static', 'float' => 'none', 'box-sizing' => 'border-box', 'max-width' => 'none',
    'padding-top' => '0px', 'padding-right' => '0px', 'padding-bottom' => '0px', 'padding-left' => '0px', 'margin-left' => '0px', 'margin-right' => '0px',
    'border-top' => '0px none rgb(0, 0, 0)', 'border-right' => '0px none rgb(0, 0, 0)', 'border-bottom' => '0px none rgb(0, 0, 0)', 'border-left' => '0px none rgb(0, 0, 0)', 'height' => '100px',
);
$container = array_replace($block, array('display' => 'grid', 'max-width' => '1280px', 'padding-left' => '20px', 'padding-right' => '20px', 'column-gap' => '40px', 'row-gap' => '40px'));
$observed = array(
    'n4' => array(
        'desktop' => array(array('x' => 0, 'width' => 1440), $block + array('width' => '1440px')),
        'mobile' => array(array('x' => 0, 'width' => 390), $block + array('width' => '390px')),
    ),
    'n5' => array(
        'desktop' => array(array('x' => 0, 'width' => 1440), $block + array('width' => '1440px')),
        'mobile' => array(array('x' => 0, 'width' => 390), $block + array('width' => '390px')),
    ),
    'n8' => array(
        'desktop' => array(array('x' => 80, 'width' => 1280), array_replace($container, array('width' => '1280px', 'margin-left' => '80px', 'margin-right' => '80px', 'grid-template-columns' => '280px 280px 280px 280px'))),
        'mobile' => array(array('x' => 0, 'width' => 390), array_replace($container, array('width' => '390px', 'grid-template-columns' => '350px'))),
    ),
);
foreach ($observed as $node => $viewports) {
    foreach ($viewports as $viewport => [$box, $styles]) {
        $tree['nodes'][$node]['viewports'][$viewport]['border_box'] = $box + $tree['nodes'][$node]['viewports'][$viewport]['border_box'];
        $tree['nodes'][$node]['viewports'][$viewport]['used_styles'] = array_replace($tree['nodes'][$node]['viewports'][$viewport]['used_styles'], $styles);
    }
}
$result = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array('index.html' => $html, 'box-tree/index.json' => json_encode($tree))))->toArray();
$css = implode("\n", array_column($result['assets'], 'content'));

function clamped_container_assert(bool $condition, string $message, string $css): void
{
    if (!$condition) throw new RuntimeException($message . "\n" . $css);
    echo 'PASS: ' . $message . "\n";
}

foreach (array('width:1280px', 'width:390px', '280px 280px', 'grid-template-columns:350px') as $frozen) {
    clamped_container_assert(!preg_match('/(?<![\w-])' . preg_quote($frozen, '/') . '/', $css), 'No carrier freezes the author-decided ' . $frozen, $css);
}
clamped_container_assert((bool) preg_match('/(?<![\w-])width:100%/', $css), 'A percentage width its max-width clamped at a capture is lifted as written', $css);
clamped_container_assert(str_contains($css, 'grid-template-columns:repeat(4,minmax(0,1fr))') && str_contains($css, 'grid-template-columns:repeat(2,minmax(0,1fr))'), 'A flexible track list switched between captures follows the author at every breakpoint', $css);

// `sm:` and `lg:` are min-width queries: each narrower list holds just
// below the author's breakpoint, so a 768px viewport gets two columns.
clamped_container_assert(1 === preg_match('/@media \(max-width:1023px\)\{[^}]*grid-template-columns:repeat\(2,minmax\(0,1fr\)\)/', $css) && 1 === preg_match('/@media \(max-width:639px\)\{[^}]*grid-template-columns:none/', $css), 'Track bands switch at the author breakpoints', $css);

echo "Box-tree clamped container contract passed\n";
