<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;
use Automattic\BlocksEngine\PhpTransformer\WordPress\BlockValidityValidator;

$result = (new HtmlTransformer())->transform(
    '<style>.testimonial blockquote{font-size:14px;line-height:1.45}'
    . '.testimonial figcaption{font-size:9px;font-weight:700;line-height:1.5;letter-spacing:1.44px}</style>'
    . '<figure class="testimonial"><blockquote>A quotation</blockquote><figcaption>Publication</figcaption></figure>'
)->toArray();
$quote = $result['blocks'][0] ?? array();
$text = $quote['innerBlocks'][0] ?? array();
if ('core/quote' !== ($quote['blockName'] ?? '')
    || 'core/paragraph' !== ($text['blockName'] ?? '')
    || '14px' !== ($text['attrs']['style']['typography']['fontSize'] ?? '')
    || '1.45' !== ($text['attrs']['style']['typography']['lineHeight'] ?? '')
    || !str_contains($quote['attrs']['citation'] ?? '', 'font-size:9px;font-weight:700;line-height:1.5;letter-spacing:1.44px')
    || 'pass' !== ((new BlockValidityValidator())->validateBlocks($result['blocks'])['status'] ?? '')
) {
    throw new RuntimeException('Collapsed figure quote must preserve independent quotation and caption typography: ' . $result['serialized_blocks']);
}
echo "Figure quote presentation passed\n";
