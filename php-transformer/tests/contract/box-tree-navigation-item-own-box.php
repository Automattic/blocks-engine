<?php
declare(strict_types=1);

require __DIR__ . '/../../vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

// Core renders a navigation link as its `<li>` and the source link as its own
// subject inside it. On a box-tree route the `<li>` must keep the source
// item's observed box, not the link's: a Wix header item is a flex row
// (`display:flex;gap:7px`) holding its label box and an empty divider, while
// its link is a block. Restyled as the link, the item fell back to a list
// item whose block-level divider pushed every label 14px right (#5862).
// The carrier must also outrank the engine's list-item display baseline,
// which a route stylesheet can load after the shared-chrome carrier.
$html = file_get_contents(__DIR__ . '/../fixtures/box-tree/index.html');
$tree = json_decode(file_get_contents(__DIR__ . '/../fixtures/box-tree/index.json'), true, 32, JSON_THROW_ON_ERROR);
$html = str_replace(
    '<p data-blocks-engine-node-id="n7">Evidence only.</p>',
    '<header data-blocks-engine-node-id="n20"><nav aria-label="Main" data-blocks-engine-node-id="n21"><ul class="menu" data-blocks-engine-node-id="n22">'
        . '<li class="menu-item" data-blocks-engine-node-id="n23"><div class="item-box" data-blocks-engine-node-id="n24"><a href="/about" data-blocks-engine-node-id="n25"><span class="item-label" data-blocks-engine-node-id="n26">About</span></a></div><span class="divider" data-blocks-engine-node-id="n40"></span></li>'
        . '<li class="menu-item" data-blocks-engine-node-id="n27"><div class="item-box" data-blocks-engine-node-id="n28"><a href="/shop" data-blocks-engine-node-id="n29"><span class="item-label" data-blocks-engine-node-id="n30">Shop</span></a></div><span class="divider" data-blocks-engine-node-id="n41"></span></li>'
        . '</ul></nav></header>',
    $html
);
$html = str_replace('</head>', '<style>.menu{display:flex;gap:7px}.menu-item{display:flex;gap:7px;align-items:center}.item-box{padding:0 11px}.menu-item a{display:block}.divider{display:block}</style></head>', $html);
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
$tree['nodes']['n20'] = $observed(array(), array('n21'));
$tree['nodes']['n21'] = $observed(array(), array('n22'));
$tree['nodes']['n22'] = $observed(array('display' => 'flex', 'column-gap' => '7px', 'row-gap' => '7px'), array('n23', 'n27'));
foreach (array(array('n23', 'n24', 'n25', 'n26', 'n40'), array('n27', 'n28', 'n29', 'n30', 'n41')) as [$li, $box, $anchor, $label, $divider]) {
    $tree['nodes'][$li] = $observed(array('display' => 'flex', 'column-gap' => '7px', 'row-gap' => '7px', 'align-items' => 'center'), array($box, $divider));
    $tree['nodes'][$box] = $observed(array('padding-left' => '11px', 'padding-right' => '11px'), array($anchor));
    $tree['nodes'][$anchor] = $observed(array('display' => 'block'), array($label));
    $tree['nodes'][$label] = $observed(array('display' => 'inline'));
    $tree['nodes'][$divider] = $observed(array('display' => 'block'));
}

$result = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array('index.html' => $html, 'box-tree/index.json' => json_encode($tree))))->toArray();
$markup = $result['serialized_blocks'];
$css = implode("\n", array_column(array_filter($result['assets'], static fn(array $asset): bool => 'css' === ($asset['kind'] ?? null)), 'content'));
$assert = static function (bool $ok, string $message) use ($markup, $css): void {
    if (!$ok) throw new RuntimeException($message . "\n" . $markup . "\n" . $css);
    echo "PASS: $message\n";
};
$assert(!array_filter($result['diagnostics'] ?? array(), static fn(array $diagnostic): bool => str_starts_with((string) ($diagnostic['code'] ?? ''), 'box_tree_')), 'The box-tree evidence is accepted.');

preg_match_all('/<!-- wp:navigation-link (\{.*?\}) \/-->/s', $markup, $links);
$assert(2 === count($links[1]), 'Each source item becomes a navigation link.');
foreach ($links[1] as $json) {
    $attrs = json_decode($json, true, 64, JSON_THROW_ON_ERROR);
    $assert(1 === preg_match('/\b(be-inline-geometry-[0-9a-f]+)\b/', (string) ($attrs['className'] ?? ''), $carrier), 'The link block carries a box-tree carrier on its <li>.');
    $assert(1 === preg_match('/(?:^|[}\s])(li)?\.' . preg_quote($carrier[1], '/') . '\.' . preg_quote($carrier[1], '/') . '\{([^}]*)\}/', $css, $rule), 'The item carrier has a rule.');
    $assert(str_contains($rule[2], 'display:flex') && str_contains($rule[2], 'gap:7px') && str_contains($rule[2], 'align-items:center'), 'The <li> restates the source item\'s flex row, not the link\'s block box: ' . $rule[2]);
    $assert('li' === $rule[1], 'The item carrier outranks the engine list-item display baseline whatever stylesheet loads last.');
}

echo "Box-tree navigation item own box contract passed.\n";
