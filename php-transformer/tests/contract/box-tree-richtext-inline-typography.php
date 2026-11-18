<?php
declare(strict_types=1);

require __DIR__ . '/../../vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

// With box-tree evidence the author stylesheet is not shipped (ADR 0005), so
// an inline inside RichText keeps its observed typography only through an
// engine carrier. imb-culinary-craft footer: `<li><a class="text-sm">` links
// rendered at the list's 16px instead of the captured 14px.
$html = file_get_contents(__DIR__ . '/../fixtures/box-tree/index.html');
$tree = json_decode(file_get_contents(__DIR__ . '/../fixtures/box-tree/index.json'), true, 32, JSON_THROW_ON_ERROR);
$html = str_replace(
    '<p data-blocks-engine-node-id="n7">Evidence only.</p>',
    '<ul data-blocks-engine-node-id="n7"><li data-blocks-engine-node-id="n8"><a class="text-sm" href="/about" data-blocks-engine-node-id="n9">About Us</a></li>'
        . '<li data-blocks-engine-node-id="n10"><a href="/menu" data-blocks-engine-node-id="n11">Menu</a></li></ul>',
    $html
);
$html = str_replace('</head>', '<style>body{font-size:16px;line-height:1.5}.text-sm{font-size:.875rem;line-height:1.25rem}</style></head>', $html);
$tree['source_hash'] = hash('sha256', $html);
$tree['nodes']['n7']['children'] = array('n8', 'n10');
$observed = static function (array $node, array $styles): array {
    foreach ($node['viewports'] as $key => $_) $node['viewports'][$key]['used_styles'] = array_replace($node['viewports'][$key]['used_styles'], $styles);
    return $node;
};
$block = array('font-size' => '16px', 'line-height' => '24px');
$tree['nodes']['n7'] = $observed($tree['nodes']['n7'], $block);
$tree['nodes']['n8'] = $observed($tree['nodes']['n7'], $block + array('display' => 'list-item'));
$tree['nodes']['n8']['children'] = array('n9');
$tree['nodes']['n9'] = $observed($tree['nodes']['n7'], array('display' => 'inline', 'font-size' => '14px', 'line-height' => '20px'));
$tree['nodes']['n10'] = $tree['nodes']['n8'];
$tree['nodes']['n10']['children'] = array('n11');
$tree['nodes']['n11'] = $observed($tree['nodes']['n7'], $block + array('display' => 'inline'));
$tree['nodes']['n9']['children'] = $tree['nodes']['n11']['children'] = array();

$result = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array('index.html' => $html, 'box-tree/index.json' => json_encode($tree))))->toArray();
$markup = $result['serialized_blocks'];
$css = implode("\n", array_column(array_filter($result['assets'], static fn(array $asset): bool => 'css' === ($asset['kind'] ?? null)), 'content'));
$assert = static function (bool $ok, string $message) use ($markup, $css): void {
    if (!$ok) throw new RuntimeException($message . "\n" . $markup . "\n" . $css);
    echo "PASS: $message\n";
};

$assert(1 === preg_match('/<a class="text-sm (be-inline-geometry-[0-9a-f]+)" href="\/about">About Us<\/a>/', $markup, $carrier), 'A RichText link whose observed size differs from its list item gets an engine carrier.');
$assert(1 === preg_match('/\.' . $carrier[1] . '\.' . $carrier[1] . '\{([^}]*)\}/', $css, $rule), 'The inline carrier rule is delivered.');
$assert(str_contains($rule[1], 'font-size:14px') && str_contains($rule[1], 'line-height:20px'), 'The carrier restates the captured inline typography.');
$assert(!str_contains($rule[1], 'display') && !str_contains($rule[1], 'width'), 'The inline carrier holds typography only, never box geometry.');
$assert(str_contains($markup, '<a href="/menu">Menu</a>'), 'A link inheriting every observed value from its block stays bare.');
