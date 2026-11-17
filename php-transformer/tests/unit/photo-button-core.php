<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

// Public conversion must expose the photo to Core's image editor without
// changing the native control or its percentage-sized containing block.
$result = (new HtmlTransformer())->transform(
    '<style>.photo{border:1px solid #aaa}.photo img{width:100%;aspect-ratio:1;object-fit:cover}</style>'
    . '<button aria-label="Product photo" class="photo"><img src="https://example.com/photo.jpg" alt="" width="256" height="256"></button>'
)->toArray();
$markup = $result['serialized_blocks'];
foreach (array(
    'no opaque control' => !str_contains($markup, '/authored-button'),
    'no generated shell' => !str_contains($markup, '/layout-shell'),
    'editable image' => str_contains($markup, '<!-- wp:image '),
    'native container' => str_contains($markup, '"blocksEngineControlTagName":"button"'),
    'accessible name' => str_contains($markup, '"aria-label":"Product photo"'),
    'valid core save' => 'pass' === ($result['source_reports']['wp_block_validity']['status'] ?? ''),
) as $label => $passed) {
    if (!$passed) throw new RuntimeException($label . ': ' . $markup);
}
// A small named icon is not photo content: retain the icon control's
// native authored representation, including its accessibility attributes.
$icon = (new HtmlTransformer())->transform(
    '<button type="button" aria-label="Choose photo"><img src="https://example.com/icon.jpg" alt="" width="24" height="24"></button>'
)->toArray()['serialized_blocks'];
if (!str_contains($icon, '/authored-button') || !str_contains($icon, 'aria-label="Choose photo"')) {
    throw new RuntimeException('Small icons must keep native control lowering: ' . $icon);
}
echo "Photo button core: 8 assertions passed\n";
