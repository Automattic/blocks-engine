<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

// The document policy allows twenty levels, but List View becomes unusable
// sooner when most entries are transport wrappers. Preserve source boxes in
// shell transport while leaving content independently editable.
$html = '<p>Editable leaf</p>';
for ($i = 0; $i < 12; ++$i) {
    $html = '<div id="layer-' . $i . '">' . $html . '</div>';
}
$result = (new HtmlTransformer())->transform('<style>#layer-11 p{color:red}</style>' . $html)->toArray();
$height = static function (array $blocks) use (&$height): int {
    $max = 0;
    foreach ($blocks as $block) {
        $max = max($max, 1 + $height($block['innerBlocks'] ?? array()));
    }
    return $max;
};
if ($height($result['blocks']) > 8) {
    throw new RuntimeException('Group-heavy documents must fit the List View depth budget.');
}
foreach (range(0, 11) as $i) {
    if (!str_contains($result['serialized_blocks'], 'id="layer-' . $i . '"')) {
        throw new RuntimeException('Normalization must preserve every source box.');
    }
}
if (1 !== substr_count($result['serialized_blocks'], '<!-- wp:paragraph')) {
    throw new RuntimeException('Content must remain independently editable.');
}
echo "Group-heavy List View passed\n";
