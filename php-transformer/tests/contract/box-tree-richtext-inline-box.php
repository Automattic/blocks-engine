<?php
declare(strict_types=1);

require __DIR__ . '/../../vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

// With box-tree evidence the author stylesheet is not shipped (ADR 0005).
// imb-culinary-craft header (#5862): the brand link `a.flex.gap-2.5`, a
// core/button, holds a 44px `span.flex.rounded-full.border` badge and the
// "Catering" label. The badge collapsed to an inline run and the link lost its
// flex row and gap, so the label sat 25px left of the capture at every width.
$html = file_get_contents(__DIR__ . '/../fixtures/box-tree/index.html');
$tree = json_decode(file_get_contents(__DIR__ . '/../fixtures/box-tree/index.json'), true, 32, JSON_THROW_ON_ERROR);
$html = str_replace(
    '<p data-blocks-engine-node-id="n7">Evidence only.</p>',
    '<div class="row" data-blocks-engine-node-id="n7"><a class="brand" href="/" data-blocks-engine-node-id="n8">'
        . '<span class="badge" data-blocks-engine-node-id="n9"><span class="logo" data-blocks-engine-node-id="n10">IMB</span></span>'
        . '<span class="name" data-blocks-engine-node-id="n11">Catering</span></a></div>',
    $html
);
$html = str_replace('</head>', '<style>.row{display:flex}.brand{display:flex;align-items:center;gap:10px;padding:8px 16px;border-radius:8px;background:#15281f;color:#fff}'
    . '.badge{display:flex;width:44px;height:44px;flex-shrink:0;align-items:center;justify-content:center;border:1px solid #c9a227;border-radius:9999px}'
    . '.name{font-size:18px;font-weight:800}</style></head>', $html);
$tree['source_hash'] = hash('sha256', $html);
$tree['nodes']['n7']['children'] = array('n8');
$observed = static function (array $styles, array $children) use ($tree): array {
    $node = $tree['nodes']['n7'];
    $node['children'] = $children;
    foreach ($node['viewports'] as $key => $_) $node['viewports'][$key]['used_styles'] = array_replace($node['viewports'][$key]['used_styles'], $styles);
    return $node;
};
$text = array('font-size' => '16px', 'font-weight' => '400', 'line-height' => '24px', 'color' => 'rgb(21, 40, 31)', 'margin-top' => '0px', 'margin-right' => '0px', 'margin-bottom' => '0px', 'margin-left' => '0px', 'padding-top' => '0px', 'padding-right' => '0px', 'padding-bottom' => '0px', 'padding-left' => '0px');
$tree['nodes']['n7'] = $observed($text + array('display' => 'flex'), array('n8'));
$tree['nodes']['n8'] = $observed(array('padding-top' => '8px', 'padding-right' => '16px', 'padding-bottom' => '8px', 'padding-left' => '16px', 'color' => 'rgb(255, 255, 255)') + $text + array('display' => 'flex', 'align-items' => 'center', 'row-gap' => '10px', 'column-gap' => '10px', 'width' => '161px', 'height' => '60px', 'border-radius' => '8px', 'background-color' => 'rgb(21, 40, 31)'), array('n9', 'n11'));
$border = '1px solid rgb(201, 162, 39)';
$tree['nodes']['n9'] = $observed($text + array('display' => 'flex', 'width' => '44px', 'height' => '44px', 'flex-shrink' => '0', 'align-items' => 'center', 'justify-content' => 'center', 'border-top' => $border, 'border-right' => $border, 'border-bottom' => $border, 'border-left' => $border, 'border-radius' => '9999px'), array('n10'));
// Flex items: a used `block` is the parent's blockification, not their own box.
$tree['nodes']['n10'] = $observed($text + array('display' => 'block', 'width' => '29px', 'height' => '20px'), array());
$tree['nodes']['n11'] = $observed(array_replace($text, array('display' => 'block', 'font-size' => '18px', 'font-weight' => '800', 'width' => '75px', 'height' => '28px')), array());

$result = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array('index.html' => $html, 'box-tree/index.json' => json_encode($tree))))->toArray();
$markup = $result['serialized_blocks'];
$css = implode("\n", array_column(array_filter($result['assets'], static fn(array $asset): bool => 'css' === ($asset['kind'] ?? null)), 'content'));
$assert = static function (bool $ok, string $message) use ($markup, $css): void {
    if (!$ok) throw new RuntimeException($message . "\n" . $markup . "\n" . $css);
    echo "PASS: $message\n";
};
$carrierRule = static function (string $class) use ($css): string {
    return 1 === preg_match('/\.' . $class . '\.' . $class . '\{([^}]*)\}/', $css, $rule) ? $rule[1] : '';
};

$assert(1 === preg_match('/<div class="wp-block-button [^"]*(be-inline-geometry-[0-9a-f]+)[^"]*"><a class="wp-block-button__link/', $markup, $button), 'The painted link becomes a core/button whose wrapper carries the observed box.');
$assert(1 === preg_match('/\.' . $button[1] . '\.' . $button[1] . '>\.wp-block-button__link\{([^}]*)\}/', $css, $link)
    && str_contains($link[1], 'display:flex') && str_contains($link[1], 'gap:10px') && str_contains($link[1], 'align-items:center'),
    'The link, which holds the badge and label, establishes the observed flex row and gap.');

$assert(1 === preg_match('/<span class="badge (be-inline-geometry-[0-9a-f]+)[^"]*"/', $markup, $badge), 'The badge inline gets an engine carrier.');
$rule = $carrierRule($badge[1]);
foreach (array('display:flex', 'width:44px', 'height:44px', 'flex-shrink:0', 'justify-content:center', 'border-width:1px', 'border-style:solid', 'border-radius:9999px') as $expected) {
    $assert(str_contains($rule, $expected), 'The badge carrier restates its observed box: ' . $expected);
}

$assert(1 === preg_match('/<span class="name (be-inline-geometry-[0-9a-f]+)[^"]*"/', $markup, $name), 'The label inline gets a typography carrier.');
$rule = $carrierRule($name[1]);
$assert(str_contains($rule, 'font-size:18px') && !str_contains($rule, 'display') && !str_contains($rule, 'width'), 'A blockified flex item keeps typography only, never box geometry.');
