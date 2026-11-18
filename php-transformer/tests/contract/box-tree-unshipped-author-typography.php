<?php
declare(strict_types=1);

require __DIR__ . '/../../vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

// With box-tree evidence the author stylesheet is not shipped (ADR 0005), so
// an inline inside RichText keeps its captured typography only through what
// the engine writes for it. imb-culinary-craft: FAQ questions
// (`<span class="font-heading">`, family `var(--font-heading)`) rendered in
// the inherited Inter, and the operation-hours day names (`<span class="flex
// ... font-medium">` saved whole as an inline layout leaf) at weight 400.
$html = file_get_contents(__DIR__ . '/../fixtures/box-tree/index.html');
$tree = json_decode(file_get_contents(__DIR__ . '/../fixtures/box-tree/index.json'), true, 32, JSON_THROW_ON_ERROR);
$html = str_replace(
    '<p data-blocks-engine-node-id="n7">Evidence only.</p>',
    '<div class="flex items-center justify-between gap-4" data-blocks-engine-node-id="n7">'
        . '<span class="flex items-center gap-3 font-medium" data-blocks-engine-node-id="n8"><svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" class="h-4 w-4" data-blocks-engine-node-id="n9"><circle cx="12" cy="12" r="10"></circle></svg>Monday</span>'
        . '<span class="text-sm font-semibold" data-blocks-engine-node-id="n10">6:00 AM – 6:00 PM</span></div>'
        . '<p data-blocks-engine-node-id="n11"><span class="font-heading font-bold" data-blocks-engine-node-id="n12">What types of events do you cater for?</span></p>',
    $html
);
$html = str_replace('</head>', '<style>:root{--font-heading:"Plus Jakarta Sans", ui-sans-serif, system-ui, sans-serif}body{font-family:Inter, sans-serif;font-size:16px;line-height:1.5}.font-heading{font-family:var(--font-heading)}.font-bold{font-weight:700}.flex{display:flex}.items-center{align-items:center}.justify-between{justify-content:space-between}.gap-4{gap:1rem}.gap-3{gap:.75rem}.font-medium{font-weight:500}.font-semibold{font-weight:600}.text-sm{font-size:.875rem;line-height:1.25rem}.h-4{height:1rem}.w-4{width:1rem}</style></head>', $html);
$tree['source_hash'] = hash('sha256', $html);
$observed = static function (array $node, array $styles): array {
    foreach ($node['viewports'] as $key => $_) $node['viewports'][$key]['used_styles'] = array_replace($node['viewports'][$key]['used_styles'], $styles);
    return $node;
};
$base = $tree['nodes']['n7'];
$row = array('display' => 'flex', 'font-family' => 'Inter, sans-serif', 'font-size' => '16px', 'line-height' => '24px', 'font-weight' => '400');
$tree['nodes']['n5']['children'] = array('n6', 'n7', 'n11');
$tree['nodes']['n7'] = $observed($base, $row + array('justify-content' => 'space-between', 'align-items' => 'center', 'column-gap' => '16px'));
$tree['nodes']['n7']['children'] = array('n8', 'n10');
$tree['nodes']['n8'] = $observed($tree['nodes']['n7'], array('display' => 'flex', 'align-items' => 'center', 'column-gap' => '12px', 'justify-content' => 'normal', 'font-weight' => '500'));
$tree['nodes']['n8']['children'] = array('n9');
$tree['nodes']['n9'] = $observed($tree['nodes']['n7'], array('display' => 'block', 'width' => '16px', 'height' => '16px', 'font-weight' => '500'));
$tree['nodes']['n9']['children'] = array();
$tree['nodes']['n10'] = $observed($tree['nodes']['n7'], array('display' => 'block', 'font-size' => '14px', 'line-height' => '20px', 'font-weight' => '600'));
$tree['nodes']['n10']['children'] = array();
$tree['nodes']['n11'] = $observed($base, array('display' => 'block') + $row);
$tree['nodes']['n11']['children'] = array('n12');
$tree['nodes']['n12'] = $observed($base, array('display' => 'inline', 'font-family' => '"Plus Jakarta Sans", ui-sans-serif, system-ui, sans-serif', 'font-weight' => '700') + $row);
$tree['nodes']['n12']['children'] = array();

$result = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array('index.html' => $html, 'box-tree/index.json' => json_encode($tree))))->toArray();
$markup = $result['serialized_blocks'];
$css = implode("\n", array_column(array_filter($result['assets'], static fn(array $asset): bool => 'css' === ($asset['kind'] ?? null)), 'content'));
$assert = static function (bool $ok, string $message) use ($markup, $css): void {
    if (!$ok) throw new RuntimeException($message . "\n" . $markup . "\n" . $css);
    echo "PASS: $message\n";
};

$assert(1 === preg_match('/<mark\b[^>]*style="([^"]*)"[^>]*>What types of events/', $markup, $question), 'The question span is a RichText carrier.');
$assert(!str_contains($question[1], 'var(--font-heading)'), 'The carrier does not name a family token only the unshipped author stylesheet defines: ' . $question[1]);
$assert(str_contains(html_entity_decode($question[1]), 'font-family:"Plus Jakarta Sans"'), 'The carrier states the family the token stands for: ' . $question[1]);

$assert(str_contains($markup, 'blocks-engine-inline-layout-carrier'), 'The icon-and-label span is saved whole as an inline layout leaf.');
$assert(1 === preg_match('/<span class="flex items-center gap-3 font-medium [^"]*?(be-inline-geometry-[0-9a-f]+)[^"]*"[^>]*>.*?Monday<\/span>/s', $markup, $carrier), 'The leaf whose observed weight differs from its row gets an engine carrier.');
$assert(1 === preg_match('/\.' . $carrier[1] . '\.' . $carrier[1] . '\{([^}]*)\}/', $css, $rule), 'The leaf carrier rule is delivered.');
$assert(str_contains($rule[1], 'font-weight:500'), 'The carrier restates the captured leaf weight: ' . $rule[1]);
$assert(!str_contains($rule[1], 'font-size') && !str_contains($rule[1], 'line-height'), 'Typography the leaf inherits from its row stays off the carrier: ' . $rule[1]);
