<?php
declare(strict_types=1);

require __DIR__ . '/../../vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

// With box-tree evidence the author stylesheet is not shipped (ADR 0005). An
// inline-level element inside RichText that generated its own box keeps its
// box geometry only through an engine carrier, and a flex anchor saved as
// core/button keeps laying out its children. imb-culinary-craft logo
// (Automattic/blocks-engine#5861): `<a class="flex items-center gap-2.5">`
// around a 44x44 `<span class="flex h-11 w-11 rounded-full border p-0.5">`
// badge rendered as a 29x20 inline run, shortening every evidence route.
$html = file_get_contents(__DIR__ . '/../fixtures/box-tree/index.html');
$tree = json_decode(file_get_contents(__DIR__ . '/../fixtures/box-tree/index.json'), true, 32, JSON_THROW_ON_ERROR);
$html = str_replace(
    '<p data-blocks-engine-node-id="n7">Evidence only.</p>',
    '<div data-blocks-engine-node-id="n7"><a class="flex items-center gap-2.5" href="/" data-blocks-engine-node-id="n8">'
        . '<span class="flex h-11 w-11 rounded-full border p-0.5" data-blocks-engine-node-id="n9"><span class="font-logo" data-blocks-engine-node-id="n10">IMB</span></span>'
        . '<span class="font-heading" data-blocks-engine-node-id="n11">Catering</span></a></div>',
    $html
);
$html = str_replace('</head>', '<style>.flex{display:flex}.items-center{align-items:center}.gap-2\.5{gap:.625rem}.h-11{height:2.75rem}.w-11{width:2.75rem}'
    . '.rounded-full{border-radius:9999px}.border{border:1px solid #c9a227}.p-0\.5{padding:.125rem}.font-logo{font-size:13px}.font-heading{font-size:18px;line-height:28px}</style></head>', $html);
$tree['source_hash'] = hash('sha256', $html);
$observed = static function (array $node, array $styles, array $children): array {
    foreach ($node['viewports'] as $key => $_) $node['viewports'][$key]['used_styles'] = array_replace($node['viewports'][$key]['used_styles'], $styles);
    $node['children'] = $children;
    return $node;
};
$template = $tree['nodes']['n7'];
$text = array('font-size' => '16px', 'line-height' => '24px');
$tree['nodes']['n7'] = $observed($template, $text, array('n8'));
$tree['nodes']['n8'] = $observed($template, $text + array('display' => 'flex', 'align-items' => 'center', 'column-gap' => '10px', 'width' => '208px', 'height' => '44px'), array('n9', 'n11'));
$border = '1px solid rgb(201, 162, 39)';
$tree['nodes']['n9'] = $observed($template, $text + array('display' => 'flex', 'width' => '44px', 'height' => '44px', 'box-sizing' => 'border-box', 'border-radius' => '9999px',
    'border-top' => $border, 'border-right' => $border, 'border-bottom' => $border, 'border-left' => $border,
    'padding-top' => '2px', 'padding-right' => '2px', 'padding-bottom' => '2px', 'padding-left' => '2px'), array('n10'));
$tree['nodes']['n10'] = $observed($template, array('display' => 'block', 'font-size' => '13px', 'line-height' => '19.5px', 'width' => '29px', 'height' => '19.5px'), array());
$tree['nodes']['n11'] = $observed($template, array('display' => 'block', 'font-size' => '18px', 'line-height' => '28px', 'width' => '75px', 'height' => '28px'), array());

$result = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array('index.html' => $html, 'box-tree/index.json' => json_encode($tree))))->toArray();
$markup = $result['serialized_blocks'];
$css = implode("\n", array_column(array_filter($result['assets'], static fn(array $asset): bool => 'css' === ($asset['kind'] ?? null)), 'content'));
$assert = static function (bool $ok, string $message) use ($markup, $css): void {
    if (!$ok) throw new RuntimeException($message . "\n" . $markup . "\n" . $css);
    echo "PASS: $message\n";
};
$rule = static function (string $class) use ($css): string {
    return 1 === preg_match('/\.' . $class . '\.' . $class . '\{([^}]*)\}/', $css, $match) ? $match[1] : '';
};

$assert(1 === preg_match('/<span class="flex h-11 w-11 rounded-full border p-0\.5[^"]*\b(be-inline-geometry-[0-9a-f]+)/', $markup, $badge), 'A RichText span that generated its own box gets an engine carrier.');
$badgeRule = $rule($badge[1]);
foreach (array('display:flex', 'width:44px', 'height:44px', 'padding:2px', 'border-width:1px', 'border-style:solid', 'border-radius:9999px') as $declaration) {
    $assert(str_contains($badgeRule, $declaration), "The badge carrier restates its observed box: $declaration.");
}
$assert(1 !== preg_match('/(?:^|;)color:/', $badgeRule), 'The inline carrier leaves color to the link and state styles that own it.');
$assert(1 === preg_match('/<div class="wp-block-button [^"]*\b(be-inline-geometry-[0-9a-f]+)/', $markup, $button) && str_contains($rule($button[1]), 'display:flex'), 'The flex anchor carrier lands on the core/button wrapper.');
$assert(str_contains($css, '.' . $button[1] . '.' . $button[1] . '>.wp-block-button__link{display:flex;'), 'The saved link restates the anchor layout, so it lays out its children even where the wrapper is forced to a block.');
