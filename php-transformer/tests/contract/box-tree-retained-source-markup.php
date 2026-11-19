<?php
declare(strict_types=1);

require __DIR__ . '/../../vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

// With box-tree evidence the author stylesheet is not shipped (ADR 0005), so
// source markup a block keeps verbatim, rather than as a block or a RichText
// inline, keeps its observed box only through an engine carrier. A header's
// navigation item boxes and labels, and an icon control's artwork, otherwise
// render unstyled on every evidence route while legacy routes keep the
// author rules (#6696: uppercase nav labels and an absolutely positioned
// cart glyph lost on box-tree routes only).
$html = file_get_contents(__DIR__ . '/../fixtures/box-tree/index.html');
$tree = json_decode(file_get_contents(__DIR__ . '/../fixtures/box-tree/index.json'), true, 32, JSON_THROW_ON_ERROR);
$html = str_replace(
    '<p data-blocks-engine-node-id="n7">Evidence only.</p>',
    '<header data-blocks-engine-node-id="n20"><nav aria-label="Main" data-blocks-engine-node-id="n21"><ul class="menu" data-blocks-engine-node-id="n22">'
        . '<li class="menu-item" data-blocks-engine-node-id="n23"><div class="item-box" data-blocks-engine-node-id="n24"><a href="/about" data-blocks-engine-node-id="n25"><span class="item-label" data-blocks-engine-node-id="n26">About</span></a></div></li>'
        . '<li class="menu-item" data-blocks-engine-node-id="n27"><div class="item-box" data-blocks-engine-node-id="n28"><a href="/shop" data-blocks-engine-node-id="n29"><span class="item-label" data-blocks-engine-node-id="n30">Shop</span></a></div></li>'
        . '</ul></nav>'
        . '<div class="cart-slot" data-blocks-engine-node-id="n35"><button type="button" class="cart" aria-label="Cart" data-blocks-engine-node-id="n31"><span class="cart-icon" data-blocks-engine-node-id="n32">'
        . '<svg aria-hidden="true" viewBox="0 0 10 12" width="100%" height="100%" data-blocks-engine-node-id="n33"><path d="M0 0h10v12H0z" data-blocks-engine-node-id="n34"></path></svg>'
        . '</span></button></div></header>',
    $html
);
$html = str_replace('</head>', '<style>.item-box{padding:0 16px}.item-label{font-family:Georgia,serif;letter-spacing:2px;text-transform:uppercase}'
    . '.cart-slot{white-space:nowrap}.cart{display:block;width:38px;padding:0;border:0}.cart-icon{display:block;position:relative;padding-bottom:120%}.cart-icon>svg{position:absolute;top:0;left:0}</style></head>', $html);
$tree['source_hash'] = hash('sha256', $html);
$template = $tree['nodes']['n7'];
unset($tree['nodes']['n7']);
$observed = static function (array $styles, array $children = array()) use ($template): array {
    $node = $template;
    foreach ($node['viewports'] as $key => $_) {
        $node['viewports'][$key]['used_styles'] = array_replace($node['viewports'][$key]['used_styles'], $styles);
    }
    $node['children'] = $children;
    return $node;
};
$tree['nodes']['n5']['children'] = array('n6', 'n20');
$tree['nodes']['n20'] = $observed(array('display' => 'flex'), array('n21', 'n35'));
$tree['nodes']['n35'] = $observed(array('white-space' => 'nowrap'), array('n31'));
$tree['nodes']['n21'] = $observed(array(), array('n22'));
$tree['nodes']['n22'] = $observed(array('display' => 'flex'), array('n23', 'n27'));
foreach (array(array('n23', 'n24', 'n25', 'n26'), array('n27', 'n28', 'n29', 'n30')) as [$li, $box, $anchor, $label]) {
    $tree['nodes'][$li] = $observed(array('display' => 'list-item'), array($box));
    $tree['nodes'][$box] = $observed(array('padding-left' => '16px', 'padding-right' => '16px'), array($anchor));
    $tree['nodes'][$anchor] = $observed(array('display' => 'inline'), array($label));
    $tree['nodes'][$label] = $observed(array('display' => 'inline', 'font-family' => 'Georgia, serif', 'letter-spacing' => '2px', 'text-transform' => 'uppercase'));
}
$tree['nodes']['n31'] = $observed(array('display' => 'block', 'width' => '38px', 'white-space' => 'nowrap'), array('n32'));
$tree['nodes']['n32'] = $observed(array('white-space' => 'nowrap', 'position' => 'relative', 'width' => '38px', 'height' => '0px', 'padding-bottom' => '45.6px'), array('n33'));
$tree['nodes']['n33'] = $observed(array('white-space' => 'nowrap', 'position' => 'absolute', 'top' => '0px', 'left' => '0px', 'width' => '38px', 'height' => '45.6px'), array('n34'));
$tree['nodes']['n34'] = $observed(array('display' => 'inline', 'white-space' => 'nowrap'));

$result = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array('index.html' => $html, 'box-tree/index.json' => json_encode($tree))))->toArray();
$markup = $result['serialized_blocks'];
$css = implode("\n", array_column(array_filter($result['assets'], static fn(array $asset): bool => 'css' === ($asset['kind'] ?? null)), 'content'));
$assert = static function (bool $ok, string $message) use ($markup, $css): void {
    if (!$ok) throw new RuntimeException($message . "\n" . $markup . "\n" . $css);
    echo "PASS: $message\n";
};
$rule = static function (string $carrier) use ($css): string {
    return 1 === preg_match('/\.' . preg_quote($carrier, '/') . '\.' . preg_quote($carrier, '/') . '\{([^}]*)\}/', $css, $match) ? $match[1] : '';
};
$assert(!array_filter($result['diagnostics'] ?? array(), static fn(array $diagnostic): bool => str_starts_with((string) ($diagnostic['code'] ?? ''), 'box_tree_')), 'The box-tree evidence is accepted.');

$assert(1 === preg_match('/item-label [^"\\\\]*(be-inline-geometry-[0-9a-f]+)/', $markup, $label), 'A navigation label kept as source markup gets an engine carrier.');
$declared = $rule($label[1]);
$assert(str_contains($declared, 'font-family:Georgia, serif') && str_contains($declared, 'letter-spacing:2px') && str_contains($declared, 'text-transform:uppercase'), 'The label carrier restates its observed typography.');
$assert(1 === preg_match('/"className":"item-box (be-inline-geometry-[0-9a-f]+)"/', $markup, $box), 'A navigation item box kept around the link gets an engine carrier.');
$assert(str_contains($rule($box[1]), ' 16px'), 'The item box carrier restates its observed padding.');

$assert(1 === preg_match('/<button type="button" aria-label="Cart" class="cart (be-inline-geometry-[0-9a-f]+)"/', $markup, $control), 'The icon control itself gets an engine carrier.');
$declared = $rule($control[1]);
$assert(str_contains($declared, 'width:38px'), 'The control carrier restates its observed box.');
// The equal-geometry slot emits no block, so the control inherits from the
// header in the output: what it inherited from the slot is its own now.
$assert(!str_contains($markup, 'cart-slot') && str_contains($declared, 'white-space:nowrap'), 'A control inside a collapsed wrapper restates what it inherited from that wrapper.');
$assert(1 === preg_match('/<span class="cart-icon (be-inline-geometry-[0-9a-f]+)"/', $markup, $icon), 'An icon control wrapper saved as source markup gets an engine carrier.');
$declared = $rule($icon[1]);
$assert(str_contains($declared, 'position:relative') && str_contains($declared, ' 120%'), 'The wrapper carrier restates its positioning box, with its authored intrinsic ratio.');
$assert(1 === preg_match('/<svg [^>]*class="(be-inline-geometry-[0-9a-f]+)"/', $markup, $svg), 'The control artwork gets an engine carrier.');
$declared = $rule($svg[1]);
$assert(str_contains($declared, 'position:absolute') && str_contains($declared, 'width:38px') && str_contains($declared, 'height:45.6px'), 'The artwork carrier restates its observed absolute box.');
