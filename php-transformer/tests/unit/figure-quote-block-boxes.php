<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;
use Automattic\BlocksEngine\PhpTransformer\WordPress\BlockValidityValidator;

// A collapsed figure quote moves the blockquote's typography onto a synthetic
// paragraph and the figcaption's onto a span inside <cite>. Both were block
// boxes in the source. As inline carriers their lines would take the quote
// block's strut (16px/24px), making every quote card taller than the source.
$result = (new HtmlTransformer())->transform(
    '<style>.quote-item{min-height:0;padding:24px 36px 20px}'
    . '.quote-item blockquote{margin:0 0 12px;font-size:14px;font-style:italic;line-height:1.45}'
    . '.quote-item figcaption{font-size:9px;font-weight:700;letter-spacing:.16em;text-transform:uppercase}</style>'
    . '<figure class="quote-item"><blockquote>A rare thing: a rights argument you actually want to finish.</blockquote>'
    . '<figcaption>Publication</figcaption></figure>'
)->toArray();
$quote = $result['blocks'][0] ?? array();
$text = $quote['innerBlocks'][0] ?? array();
$classes = preg_split('/\s+/', (string) ($text['attrs']['className'] ?? '')) ?: array();
$citation = (string) ($quote['attrs']['citation'] ?? '');
if ( 'core/quote' !== ($quote['blockName'] ?? '')
    || 'core/paragraph' !== ($text['blockName'] ?? '')
    || ! in_array('blocks-engine-synthetic-paragraph', $classes, true)
    // The engine lays a synthetic paragraph out inline unless it is a source box.
    || ! in_array('blocks-engine-source-box-paragraph', $classes, true)
    // Consuming a blockquote must not transfer its box to the text carrier.
    || isset($text['attrs']['style']['spacing'])
    || ! str_contains($citation, 'font-size:9px')
    || ! str_contains($citation, 'display:block')
    // <cite> is italic in the UA stylesheet; the caption was not.
    || ! str_contains($citation, 'font-style:normal')
    || 'pass' !== ((new BlockValidityValidator())->validateBlocks($result['blocks'])['status'] ?? '')
) {
    throw new RuntimeException('Collapsed figure quote must keep the quotation and caption as block boxes: ' . $result['serialized_blocks']);
}

// A caption inheriting italic from the figure stays italic.
$italic = (new HtmlTransformer())->transform(
    '<style>.q{font-style:italic}.q figcaption{font-size:9px}</style>'
    . '<figure class="q"><blockquote>Quotation</blockquote><figcaption>Source</figcaption></figure>'
)->toArray();
if ( ! str_contains((string) ($italic['blocks'][0]['attrs']['citation'] ?? ''), 'font-style:italic') ) {
    throw new RuntimeException('Caption must keep its inherited font style: ' . $italic['serialized_blocks']);
}
// Unstyled quotes and captions with only paint/weight do not need a new
// line box. Keep their existing save shape and UA citation styling.
foreach (array('', '<style>figcaption{color:#123456;font-weight:700}</style>') as $css) {
    $plain = (new HtmlTransformer())->transform(
        $css . '<figure><blockquote>Plain quotation</blockquote><figcaption>Source</figcaption></figure>'
    )->toArray();
    $plainQuote = $plain['blocks'][0];
    if ( 'blocks-engine-synthetic-paragraph' !== ($plainQuote['innerBlocks'][0]['attrs']['className'] ?? '')
        || str_contains($plainQuote['attrs']['citation'] ?? '', 'display:block')
        || str_contains($plainQuote['attrs']['citation'] ?? '', 'font-style:normal') ) {
        throw new RuntimeException('Unrelated figure quotes must keep their existing presentation: ' . $plain['serialized_blocks']);
    }
}
echo "Figure quote block boxes passed\n";
