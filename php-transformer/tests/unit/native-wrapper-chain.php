<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

$result = (new HtmlTransformer())->transform(
    '<div id="outer"><section id="inner"><h2>Title</h2><p>Editable copy</p></section></div>',
    array()
)->toArray();
$markup = $result['serialized_blocks'];
if (str_contains($markup, '/layout-shell') || !str_contains($markup, '<!-- wp:group') || !str_contains($markup, '<!-- wp:heading') || !str_contains($markup, '<!-- wp:paragraph')) {
    throw new RuntimeException('Wrapper chains must retain native editable blocks: ' . $markup);
}
$document = new DOMDocument();
$document->loadHTML($markup, LIBXML_NOERROR | LIBXML_NOWARNING);
if (1 !== (new DOMXPath($document))->query('//div[@id="outer"]/section[@id="inner"]/h2')->length) {
    throw new RuntimeException('Native groups must retain source wrapper ancestry.');
}
echo "Native wrapper chain passed\n";
