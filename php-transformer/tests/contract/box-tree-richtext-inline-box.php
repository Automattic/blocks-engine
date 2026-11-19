<?php
declare(strict_types=1);

require __DIR__ . '/../../vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

// With box-tree evidence the author stylesheet is not shipped (ADR 0005), so
// an inline inside RichText that the source laid out as a decorated box keeps
// that box only through an engine carrier. imb-culinary-craft header and
// footer: the `flex h-11 w-11 rounded-full border` ring around the "IMB" logo
// text collapsed to its text on every route, moving "Catering" 15px left.
$html = file_get_contents(__DIR__ . '/../fixtures/box-tree/index.html');
$tree = json_decode(file_get_contents(__DIR__ . '/../fixtures/box-tree/index.json'), true, 32, JSON_THROW_ON_ERROR);
$html = str_replace(
    '<p data-blocks-engine-node-id="n7">Evidence only.</p>',
    '<div data-blocks-engine-node-id="n7"><a class="logo" href="/" data-blocks-engine-node-id="n10"><span class="ring" data-blocks-engine-node-id="n8">IMB</span><span class="name" data-blocks-engine-node-id="n9">Catering</span></a></div>',
    $html
);
$html = str_replace('</head>', '<style>body{font-size:16px;line-height:1.5}.ring{display:flex;width:2.75rem;height:2.75rem;padding:2px;border:1px solid #c9a227;border-radius:9999px}.name{font-weight:800}.logo{display:flex;align-items:center;gap:10px}</style></head>', $html);
$tree['source_hash'] = hash('sha256', $html);
$tree['nodes']['n7']['children'] = array('n10');
$observed = static function (array $node, array $styles): array {
    foreach ($node['viewports'] as $key => $_) $node['viewports'][$key]['used_styles'] = array_replace($node['viewports'][$key]['used_styles'], $styles);
    return $node;
};
$block = array('font-size' => '16px', 'line-height' => '24px', 'font-weight' => '400', 'background-color' => 'rgba(0, 0, 0, 0)', 'background-image' => 'none', 'box-shadow' => 'none');
foreach (array('top', 'right', 'bottom', 'left') as $side) $block += array('padding-' . $side => '0px', 'border-' . $side => '0px none rgb(0, 0, 0)');
$tree['nodes']['n7'] = $observed($tree['nodes']['n7'], $block);
$ring = array('display' => 'flex', 'width' => '44px', 'height' => '44px', 'border-radius' => '9999px');
foreach (array('top', 'right', 'bottom', 'left') as $side) $ring += array('padding-' . $side => '2px', 'border-' . $side => '1px solid rgb(201, 162, 39)');
$tree['nodes']['n8'] = $observed($tree['nodes']['n7'], $ring + $block);
$tree['nodes']['n9'] = $observed($tree['nodes']['n7'], array('display' => 'block', 'font-weight' => '800') + $block);
$tree['nodes']['n10'] = $observed($tree['nodes']['n7'], array('display' => 'flex', 'align-items' => 'center', 'column-gap' => '10px') + $block);
$tree['nodes']['n10']['children'] = array('n8', 'n9');
$tree['nodes']['n8']['children'] = $tree['nodes']['n9']['children'] = array();

$result = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array('index.html' => $html, 'box-tree/index.json' => json_encode($tree))))->toArray();
$markup = $result['serialized_blocks'];
$css = implode("\n", array_column(array_filter($result['assets'], static fn(array $asset): bool => 'css' === ($asset['kind'] ?? null)), 'content'));
$assert = static function (bool $ok, string $message) use ($markup, $css): void {
    if (!$ok) throw new RuntimeException($message . "\n" . $markup . "\n" . $css);
    echo "PASS: $message\n";
};

$assert(1 === preg_match('/<span class="ring (be-inline-geometry-[0-9a-f]+)[^"]*"[^>]*>IMB<\/span>/', $markup, $carrier), 'A RichText inline laid out as a bordered box gets an engine carrier.');
$assert(1 === preg_match('/\.' . $carrier[1] . '\.' . $carrier[1] . '\{([^}]*)\}/', $css, $rule), 'The inline box carrier rule is delivered.');
foreach (array('display:flex', 'width:', 'height:', 'border-radius:9999px', 'padding:2px') as $declaration) {
    $assert(str_contains($rule[1], $declaration), "The carrier restates the captured box ($declaration).");
}
$assert(1 === preg_match('/border(?:-top)?:1px solid|border-width:1px;border-style:solid/', $rule[1]), 'The carrier restates the captured border.');
$assert(1 === preg_match('/<span class="name (be-inline-geometry-[0-9a-f]+)[^"]*"[^>]*>Catering<\/span>/', $markup, $text), 'A text inline still gets its typography carrier.');
$assert(1 === preg_match('/\.' . $text[1] . '\.' . $text[1] . '\{([^}]*)\}/', $css, $textRule) && !str_contains($textRule[1], 'display') && !str_contains($textRule[1], 'width'), 'A text inline that paints no box keeps a typography-only carrier.');
