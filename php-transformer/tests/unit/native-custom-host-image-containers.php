<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

// Static custom image hosts are independently editable containers, not a
// generated wrapper around an editable image. A source-less picture with no
// identity or presentation of its own is the image's box: it adds no
// container (and no List View level) between the host and the image.
$html = '<media-frame class="frame" data-image-info="{}" data-is-responsive="true" data-motion-part="background"><picture><img src="photo.jpg" alt="Photo"></picture></media-frame>';
$result = (new HtmlTransformer())->transform($html)->toArray();
$markup = $result['serialized_blocks'];
if (str_contains($markup, '/layout-shell') || str_contains($markup, '/responsive-media')
    || 1 !== substr_count($markup, '<!-- wp:group ')
    || 1 !== substr_count($markup, '<!-- wp:image ')) {
    throw new RuntimeException('Static image boxes must expose native groups and an editable core image.');
}
$document = new DOMDocument();
@$document->loadHTML($markup);
$xpath = new DOMXPath($document);
if (1 !== $xpath->query('//div[contains(concat(" ", @class, " "), " frame ")]/figure/img[@src="photo.jpg"]')->length
    || 0 !== $xpath->query('//picture')->length) {
    throw new RuntimeException('A neutral source-less picture must not become a native container.');
}
// Builder CSS often marks the picture relative inside an absolutely placed
// host. Around an in-flow image that is neither a containing block nor a
// separate paint layer, so it is still the image's own box.
$positioned = (new HtmlTransformer())->transform('<style>.frame{position:absolute;inset:0}.frame picture{position:relative}</style>' . $html)->toArray()['serialized_blocks'];
if (str_contains($positioned, '<picture') || 1 !== substr_count($positioned, '<!-- wp:image ')) {
    throw new RuntimeException('An offsetless relative picture inside a positioned host must not become a native container.');
}
// A picture that author CSS presents, positions as a paint layer of its own,
// or that selectors address, keeps its box.
foreach (array(
    '<style>.frame picture{display:block;border:2px solid red}</style>',
    '<style>.frame picture{position:relative}</style>',
    '<style>.frame{position:absolute}.frame picture{position:relative;z-index:2}</style>',
    '<style>picture > img{opacity:.5}</style>',
) as $style) {
    $styled = (new HtmlTransformer())->transform($style . $html)->toArray()['serialized_blocks'];
    $styledDocument = new DOMDocument();
    @$styledDocument->loadHTML($styled);
    if (1 !== (new DOMXPath($styledDocument))->query('//div[contains(concat(" ", @class, " "), " frame ")]/picture/figure/img[@src="photo.jpg"]')->length) {
        throw new RuntimeException('Pictures with presentation or dependent selectors must retain the host, picture, and image topology.');
    }
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
