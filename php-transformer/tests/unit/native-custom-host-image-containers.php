<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

// Static custom image hosts and source-less pictures are independently
// editable containers, not a generated wrapper around an editable image.
$html = '<media-frame class="frame" data-image-info="{}" data-is-responsive="true" data-motion-part="background"><picture><img src="photo.jpg" alt="Photo"></picture></media-frame>';
$result = (new HtmlTransformer())->transform($html)->toArray();
$markup = $result['serialized_blocks'];
if (str_contains($markup, '/layout-shell') || str_contains($markup, '/responsive-media')
    || 2 !== substr_count($markup, '<!-- wp:group ')
    || 1 !== substr_count($markup, '<!-- wp:image ')) {
    throw new RuntimeException('Static image boxes must expose native groups and an editable core image.');
}
$document = new DOMDocument();
@$document->loadHTML($markup);
$xpath = new DOMXPath($document);
if (1 !== $xpath->query('//div[contains(concat(" ", @class, " "), " frame ")]/picture/figure/img[@src="photo.jpg"]')->length) {
    throw new RuntimeException('Native containers must retain the host, picture, and image topology.');
}
// An actual source-selection contract still needs its responsive boundary.
$responsive = (new HtmlTransformer())->transform(str_replace('<picture>', '<picture><source srcset="photo.webp" type="image/webp">', $html))->toArray();
if (!str_contains($responsive['serialized_blocks'], '/responsive-media')) {
    throw new RuntimeException('Authored picture source selection must survive.');
}
// A host without a picture keeps the linked-thumbnail presentation contract.
$thumbnail = (new HtmlTransformer())->transform('<a href="/post/"><div class="thumb"><media-frame><img src="photo.jpg" alt="Photo"></media-frame></div></a>')->toArray();
if (!str_contains($thumbnail['serialized_blocks'], '<a href="/post/"><div class="thumb"><!-- wp:custom/layout-shell')) {
    throw new RuntimeException('Linked hosts without pictures must retain their link and presentation carrier.');
}
echo "Native custom host image containers regression passed\n";
