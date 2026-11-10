<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

// Exercise the public transform: the custom host retains its independent
// presentation, but the destination belongs to the editable native image.
$html = '<style>.destination{display:block;width:100%;height:100%;text-decoration:none}media-frame{display:block}</style><div><a class="destination" data-testid="linkElement" href="/home/" target="_self"><media-frame data-image-info="{}"><img src="logo.png" alt="Home"></media-frame></a></div>';
$result = (new HtmlTransformer())->transform($html)->toArray();
$markup = $result['serialized_blocks'];
if (1 !== preg_match('/<!-- wp:image (.*?) -->/s', $markup, $match)) {
    throw new RuntimeException('Expected one editable native image.');
}
if (preg_match('/<!-- wp:[^ ]+\/layout-shell .*?"tagName":"a"/', $markup)
    || str_contains($markup, '/responsive-media') || str_contains($markup, '<!-- wp:html')) {
    throw new RuntimeException('An image-only custom host must not leave a companion anchor editing surface.');
}
$document = new DOMDocument();
@$document->loadHTML($markup);
$xpath = new DOMXPath($document);
if (1 !== $xpath->query('//div[contains(concat(" ",normalize-space(@class)," ")," blocks-engine-css-owned-layout ")]//figure/a[@href="/home/" and @target="_self" and contains(@class,"destination")]//img[@src="logo.png"]')->length) {
    throw new RuntimeException('Retain the custom presentation host around the linked native image.');
}
echo "Custom host native image link regression passed\n";
