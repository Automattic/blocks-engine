<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

// Descendant selectors require every source box to survive. They must not
// require every incidental box to occupy a separate List View level.
$html = '<p>Editable content</p>';
for ($i = 11; $i >= 0; --$i) {
    $html = '<div class="layer-' . $i . '">' . $html . '</div>';
}
$result = (new HtmlTransformer())->transform('<style>.layer-0 p{color:red}</style>' . $html)->toArray();
$height = static function (array $blocks) use (&$height): int {
    $max = 0;
    foreach ($blocks as $block) {
        $max = max($max, 1 + $height($block['innerBlocks'] ?? array()));
    }
    return $max;
};
if ($height($result['blocks']) > 8) {
    throw new RuntimeException('Incidental wrapper chains must fit the List View depth budget.');
}
$markup = $result['serialized_blocks'];
for ($i = 0; $i < 12; ++$i) {
    if (!str_contains($markup, 'layer-' . $i)) {
        throw new RuntimeException('Shell transport lost a source selector identity.');
    }
}
if (!str_contains($markup, '<!-- wp:paragraph')) {
    throw new RuntimeException('The content must remain a native editable paragraph.');
}
// Neither run alone reaches eight levels. Shared ancestry still counts
// when a wrapper branches into independently editable content trees.
$branch = '<p>Branch content</p>';
for ($i = 4; $i >= 0; --$i) {
    $branch = '<div id="branch-' . $i . '">' . $branch . '</div>';
}
$html = $branch . str_replace('branch-', 'sibling-', $branch);
for ($i = 4; $i >= 0; --$i) {
    $html = '<div id="ancestor-' . $i . '">' . $html . '</div>';
}
$result = (new HtmlTransformer())->transform($html)->toArray();
if ($height($result['blocks']) > 8) {
    throw new RuntimeException('Shared ancestry must count toward List View depth.');
}
foreach (array('ancestor', 'branch', 'sibling') as $prefix) {
    for ($i = 0; $i < 5; ++$i) {
        if (!str_contains($result['serialized_blocks'], 'id="' . $prefix . '-' . $i . '"')) {
            throw new RuntimeException('Branch normalization lost a source wrapper.');
        }
    }
}
if (2 !== substr_count($result['serialized_blocks'], '<!-- wp:paragraph')) {
    throw new RuntimeException('Both branches must retain native editable paragraphs.');
}
echo "list-view-wrapper-depth: passed\n";
