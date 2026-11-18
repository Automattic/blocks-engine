<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;
use Automattic\BlocksEngine\PhpTransformer\WordPress\BlockValidityValidator;

// A figure quotation collapses into core/quote. The source blockquote and
// figcaption are block boxes with their own line boxes: the quotation's
// lines follow its own line-height, and the caption is neither set on the
// quote's strut nor italicised by the native <cite>, and it keeps the
// quotation's block-end margin above it.
$result = (new HtmlTransformer())->transform(
    '<style>body{font-size:16px;line-height:1.5}'
    . '.quote-item blockquote{margin:0 0 12px;font-size:14px;font-style:italic;line-height:1.45}'
    . '.quote-item figcaption{color:#d33;letter-spacing:.16em;text-transform:uppercase;font-size:9px;font-weight:700}</style>'
    . '<figure class="quote-item"><blockquote>A rare thing: a rights argument you actually want to finish.</blockquote>'
    . '<figcaption>Publication</figcaption></figure>'
)->toArray();
$quote = $result['blocks'][0] ?? array();
$text = $quote['innerBlocks'][0] ?? array();
$classes = preg_split('/\s+/', (string) ($text['attrs']['className'] ?? '')) ?: array();
if ('core/quote' !== ($quote['blockName'] ?? '')
    || 'core/paragraph' !== ($text['blockName'] ?? '')
    || !in_array('blocks-engine-source-box-paragraph', $classes, true)
    || '1.45' !== ($text['attrs']['style']['typography']['lineHeight'] ?? '')
) {
    throw new RuntimeException('The collapsed quotation must keep a block line box for its own line-height: ' . $result['serialized_blocks']);
}
$citation = (string) ($quote['attrs']['citation'] ?? '');
if (!preg_match('/<span style="[^"]*font-size:9px[^"]*margin-top:12px[^"]*display:block[^"]*font-style:normal[^"]*">Publication<\/span>/', $citation)) {
    throw new RuntimeException('The restated caption must be an upright block box, the quotation\'s margin below it, inside <cite>: ' . $citation);
}
if ('pass' !== ((new BlockValidityValidator())->validateBlocks($result['blocks'])['status'] ?? '')) {
    throw new RuntimeException('Collapsed figure quote must stay valid: ' . $result['serialized_blocks']);
}
echo "Figure quote caption line boxes passed\n";
