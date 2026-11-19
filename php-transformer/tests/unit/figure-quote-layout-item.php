<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;
use Automattic\BlocksEngine\PhpTransformer\WordPress\BlockValidityValidator;

// A rail's height must come from separate quotation and caption block boxes,
// not a mixed-font inline paragraph/cite inside a collapsed figure.
$result = (new HtmlTransformer())->transform(
    '<style>.rail{display:flex}.review{flex:0 0 340px;padding:24px 36px 20px}'
    . '.review blockquote{margin:0 0 12px;font-size:14px;line-height:1.45}'
    . '.review figcaption{font-size:9px;font-weight:700}</style>'
    . '<div class="rail"><figure class="review"><blockquote>A quotation that wraps across lines.</blockquote>'
    . '<figcaption>Publication</figcaption></figure></div>'
)->toArray();
$figure = $result['blocks'][0]['innerBlocks'][0] ?? array();
$quote = $figure;
$text = $quote['innerBlocks'][0] ?? array();
if ('core/quote' !== ($quote['blockName'] ?? '')
    || 'core/paragraph' !== ($text['blockName'] ?? '')
    || 'blocks-engine-figure-quotation' !== ($text['attrs']['className'] ?? '')
    || '12px' !== ($text['attrs']['style']['spacing']['margin']['bottom'] ?? '')
    || '9px' !== ($quote['attrs']['style']['typography']['fontSize'] ?? '')
    || isset($quote['attrs']['style']['typography']['fontWeight'])
    || isset($quote['attrs']['style']['typography']['fontFamily'])
    || !str_contains($quote['attrs']['citation'] ?? '', 'Publication')
    || 'pass' !== ((new BlockValidityValidator())->validateBlocks($result['blocks'])['status'] ?? '')
) {
    throw new RuntimeException('Layout-item quote must retain independent text boxes without changing native topology: ' . $result['serialized_blocks']);
}
echo "Figure quote layout item passed\n";
