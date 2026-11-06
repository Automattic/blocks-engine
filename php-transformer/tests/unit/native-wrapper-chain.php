<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

$result = (new HtmlTransformer())->transform(
    '<div id="outer"><section id="inner"><h2>Editable title</h2></section></div>',
    array()
)->toArray();
$markup = $result['serialized_blocks'];
if (str_contains($markup, '/layout-shell') || !str_contains($markup, '<!-- wp:group') || !str_contains($markup, '<!-- wp:heading')) {
    throw new RuntimeException('Wrapper chains must retain native editable blocks: ' . $markup);
}
$document = new DOMDocument();
$document->loadHTML($markup, LIBXML_NOERROR | LIBXML_NOWARNING);
if (1 !== (new DOMXPath($document))->query('//div[@id="outer"]/section[@id="inner"]/h2')->length) {
    throw new RuntimeException('Native groups must retain source wrapper ancestry.');
}
// A declared responsive content owner must keep its projected shell boundary.
$responsive = (new HtmlTransformer())->transform(
    '<div id="outer"><section id="inner"><p class="be-responsive-counterpart-0123456789ab">Editable counterpart</p><p>Sibling</p></section></div>',
    array()
)->toArray();
$responsiveMarkup = $responsive['serialized_blocks'];
if (!str_contains($responsiveMarkup, '/layout-shell')
    || !str_contains($responsiveMarkup, 'be-responsive-counterpart-0123456789ab')
    || !str_contains($responsiveMarkup, '<!-- wp:paragraph')
) {
    throw new RuntimeException('Responsive wrapper boundaries must survive native chain normalization: ' . $responsiveMarkup);
}
echo "Native wrapper chain passed\n";
