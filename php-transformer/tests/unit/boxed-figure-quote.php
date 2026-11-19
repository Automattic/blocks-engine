<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

// A consumed blockquote's shorter leading needs its own block line box, not
// the figure's inherited strut. Keep the existing native quote/citation shape.
$result = (new HtmlTransformer())->transform(
    '<style>body{font-size:16px;line-height:1.5}'
    . '.review{padding:24px 36px 20px;border-right:1px solid #ccc;margin:0}'
    . '.review blockquote{margin:0 0 12px;font-size:14px;font-style:italic;line-height:1.45}'
    . '.review figcaption{font-size:9px;font-weight:700}</style>'
    . '<figure class="review"><blockquote>A quotation spanning two lines of text.</blockquote>'
    . '<figcaption>Publication</figcaption></figure><p>Following section</p>'
)->toArray();

$quote = $result['blocks'][0] ?? array();
$text = $quote['innerBlocks'][0] ?? array();
if ('core/quote' !== ($quote['blockName'] ?? '')
    || 'core/paragraph' !== ($text['blockName'] ?? '')
    || !str_contains($text['attrs']['className'] ?? '', 'blocks-engine-source-box-paragraph')
    || '1.45' !== ($text['attrs']['style']['typography']['lineHeight'] ?? '')
    || isset($text['attrs']['style']['spacing'])
    || !str_contains($quote['attrs']['citation'] ?? '', 'Publication')
    || array() !== ($result['blocks'][1]['attrs']['style'] ?? array())
    || 'pass' !== ($result['source_reports']['wp_block_validity']['status'] ?? '')
) {
    throw new RuntimeException('Quotation leading must retain a block line box without changing surrounding topology: ' . $result['serialized_blocks']);
}
echo "Boxed figure quote passed\n";
