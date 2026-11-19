<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

// A source control already needs shell transport. Its static descendant boxes
// must not introduce extra saved levels or change any rendered selector edges.
$html = '<p>Editable label</p>';
for ($i = 0; $i < 5; ++$i) $html = '<div id="layer-' . $i . '">' . $html . '</div>';
$result = (new HtmlTransformer())->transform('<button type="button">' . $html . '</button>')->toArray();
$root = $result['blocks'][0];
if (!str_ends_with($root['blockName'], '/layout-shell')
    || 6 !== count($root['attrs']['wrappers'] ?? array())
    || array('core/paragraph') !== array_column($root['innerBlocks'] ?? array(), 'blockName')) {
    throw new RuntimeException('An existing shell must carry its static wrapper chain around native editable content.');
}
$markup = $result['serialized_blocks'];
$dom = new DOMDocument();
@$dom->loadHTML($markup);
$cursor = $dom->getElementsByTagName('button')->item(0);
for ($i = 4; $i >= 0; --$i) {
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
echo "existing-shell-wrapper-depth: passed\n";
