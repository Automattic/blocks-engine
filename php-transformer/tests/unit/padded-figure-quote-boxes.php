<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;
use Automattic\BlocksEngine\PhpTransformer\WordPress\BlockValidityValidator;

// A caption with small type needs its own block line box, not a small
// inline span inside a cite inheriting the card's larger line height.
$result = (new HtmlTransformer())->transform(
    '<style>body{font-size:16px;line-height:1.5}'
    . '.card{padding:24px 36px 20px;margin:0}'
    . '.card blockquote{margin:0 0 12px;font-size:14px;line-height:1.45}'
    . '.card figcaption{font-size:9px;font-weight:700}</style>'
    . '<figure class="card"><blockquote>A quotation</blockquote><figcaption>Publication</figcaption></figure>'
)->toArray();
$card = $result['blocks'][0] ?? array();
$quote = $card['innerBlocks'][0] ?? array();
$caption = $card['innerBlocks'][1] ?? array();
if ('core/group' !== ($card['blockName'] ?? '')
    || 'core/quote' !== ($quote['blockName'] ?? '')
    || isset($quote['attrs']['citation'])
    || 'core/paragraph' !== ($caption['blockName'] ?? '')
    || 'Publication' !== ($caption['attrs']['content'] ?? '')
    || 'pass' !== ((new BlockValidityValidator())->validateBlocks($result['blocks'])['status'] ?? '')
) {
    throw new RuntimeException('Padded quote cards must retain independent quote and caption boxes: ' . $result['serialized_blocks']);
}
echo "Padded figure quote boxes passed\n";
