<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;
use Automattic\BlocksEngine\PhpTransformer\WordPress\BlockValidityValidator;

$result = (new HtmlTransformer())->transform(
    '<style>.review blockquote{margin:0 0 12px;font-size:14px;line-height:1.45;width:237px}</style>'
    . '<figure class="review"><blockquote>A quotation spanning two lines.</blockquote><figcaption>Publication</figcaption></figure>'
)->toArray();
$quote = $result['blocks'][0] ?? array();
$text = $quote['innerBlocks'][0] ?? array();
if ('core/quote' !== ($quote['blockName'] ?? '')
    || '12px' !== ($text['attrs']['style']['spacing']['margin']['bottom'] ?? '')
    || !str_contains($text['innerHTML'] ?? '', 'margin-bottom:12px')
    || isset($text['attrs']['inlineGeometryStyle'])
    || 'pass' !== ((new BlockValidityValidator())->validateBlocks($result['blocks'])['status'] ?? '')
) {
    throw new RuntimeException('Consuming a figure quotation must retain its authored citation separation: ' . $result['serialized_blocks']);
}
// A figure with no authored quotation box keeps the existing synthetic
// paragraph contract; opting it into block layout changes unrelated quotes.
$unstyled = (new HtmlTransformer())->transform(
    '<figure><blockquote>“<!---->Quote text.<!---->”</blockquote><figcaption>Publication</figcaption></figure>'
)->toArray();
$unstyledText = $unstyled['blocks'][0]['innerBlocks'][0] ?? array();
if ('blocks-engine-synthetic-paragraph' !== ($unstyledText['attrs']['className'] ?? '')
    || isset($unstyledText['attrs']['style']['spacing'])
    || 'pass' !== ((new BlockValidityValidator())->validateBlocks($unstyled['blocks'])['status'] ?? '')
) {
    throw new RuntimeException('Unstyled figure quotations must retain their margin-neutral carrier: ' . $unstyled['serialized_blocks']);
}
echo "Figure quote citation spacing passed\n";
