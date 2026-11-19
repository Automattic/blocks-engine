<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

// A source control already needs shell transport. Its static descendant boxes
// must not introduce extra saved levels or change any rendered selector edges.
$html = '<p>Editable label</p>';
for ($i = 0; $i < 11; ++$i) $html = '<div id="layer-' . $i . '">' . $html . '</div>';
$result = (new HtmlTransformer())->transform('<button type="button">' . $html . '</button>')->toArray();
$root = $result['blocks'][0];
if (!str_ends_with($root['blockName'], '/layout-shell')
    || 12 !== count($root['attrs']['wrappers'] ?? array())
    || array('core/paragraph') !== array_column($root['innerBlocks'] ?? array(), 'blockName')) {
    throw new RuntimeException('An existing shell must carry its static wrapper chain around native editable content.');
}
$markup = $result['serialized_blocks'];
$dom = new DOMDocument();
@$dom->loadHTML($markup);
$cursor = $dom->getElementsByTagName('button')->item(0);
for ($i = 10; $i >= 0; --$i) {
    $children = array_values(array_filter(iterator_to_array($cursor->childNodes), static fn ($node): bool => $node instanceof DOMElement));
    if (1 !== count($children) || 'layer-' . $i !== $children[0]->getAttribute('id')) {
        throw new RuntimeException('Shell normalization changed a source parent-child edge.');
    }
    $cursor = $children[0];
}
if (!str_contains($markup, '<!-- wp:paragraph') || 1 !== substr_count($markup, '<!-- wp:custom/layout-shell')) {
    throw new RuntimeException('Normalization must retain native content without adding shells.');
}
// Independently styled boxes still expose their own block controls.
$owned = (new HtmlTransformer())->transform('<button type="button"><div style="padding:20px"><p>Label</p></div></button>')->toArray();
if ('core/group' !== ($owned['blocks'][0]['innerBlocks'][0]['blockName'] ?? null)) {
    throw new RuntimeException('Shell absorption must respect independent wrapper ownership.');
}
// Capture-only responsive markers are box identity, not runtime controls.
$marked = '<p>Label</p>';
for ($i = 0; $i < 11; ++$i) $marked = '<div id="box-' . $i . '" data-blocks-engine-capture-responsive-fragment="box-' . $i . '">' . $marked . '</div>';
$captured = (new HtmlTransformer())->transform('<button type="button">' . $marked . '</button>')->toArray();
if (12 !== count($captured['blocks'][0]['attrs']['wrappers'] ?? array())
    || 'box-10' !== ($captured['blocks'][0]['attrs']['wrappers'][1]['attributes']['data-blocks-engine-capture-responsive-fragment'] ?? null)) {
    throw new RuntimeException('Responsive fragment identity must remain on the absorbed source box.');
}
$shallow = (new HtmlTransformer())->transform('<button type="button"><div id="shallow"><p>Label</p></div></button>')->toArray();
if ('core/group' !== ($shallow['blocks'][0]['innerBlocks'][0]['blockName'] ?? null)) {
    throw new RuntimeException('A shell within the depth budget must not change descendant transport.');
}
echo "existing-shell-wrapper-depth: passed\n";
