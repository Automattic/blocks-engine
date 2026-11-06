<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

// A source-less picture is a presentation box, not an unknown custom
// element. Keep the whole linked box chain around independently editable media.
$html = '<a href="/about/#photos"><div id="outer"><div id="inner"><media-frame id="host" data-image-info="{}"><picture><img src="photo.jpg" alt="Photo"></picture></media-frame></div></div></a>';
$result = (new HtmlTransformer())->transform($html)->toArray();
$markup = $result['serialized_blocks'];
if (str_contains($markup, '/responsive-media') || 1 !== substr_count($markup, '<!-- wp:image ')) {
    throw new RuntimeException('Linked custom-host pictures must expose one native image.');
}
$document = new DOMDocument();
@$document->loadHTML($markup);
$xpath = new DOMXPath($document);
if (1 !== $xpath->query('//a[@href="/about/#photos"]/div[@id="outer"]/div[@id="inner"]/*[@id="host"]/picture//img[@src="photo.jpg"]')->length) {
    throw new RuntimeException('The link and every intermediate presentation box must survive in source order.');
}
// Authored source selection still needs its responsive-media boundary.
$responsive = (new HtmlTransformer())->transform(str_replace('<picture>', '<picture><source srcset="photo.webp" type="image/webp">', $html))->toArray();
if (!str_contains($responsive['serialized_blocks'], '/responsive-media')) {
    throw new RuntimeException('Picture source selection must not be flattened.');
}
echo "Linked custom image picture regression passed\n";
