<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

// Separate figure and quotation boxes must not share a line box: the figure's
// inherited 24px line-height otherwise enlarges the 20.3px quotation lines and
// shifts every following section, including unrelated paragraph blocks.
$result = (new HtmlTransformer())->transform(
    '<style>body{font-size:16px;line-height:1.5}'
    . '.review{padding:24px 36px 20px;border-right:1px solid #ccc;margin:0}'
    . '.review blockquote{margin:0 0 12px;font-size:14px;font-style:italic;line-height:1.45}'
    . '.review figcaption{font-size:9px;font-weight:700}</style>'
    . '<figure class="review"><blockquote>A quotation spanning two lines of text.</blockquote>'
    . '<figcaption>Publication</figcaption></figure><p>Following section</p>'
)->toArray();

$figure = $result['blocks'][0] ?? array();
$quote = $figure['innerBlocks'][0] ?? array();
$caption = $figure['innerBlocks'][1] ?? array();
if ('core/group' !== ($figure['blockName'] ?? '')
    || 'core/quote' !== ($quote['blockName'] ?? '')
    || '1.45' !== ($quote['attrs']['style']['typography']['lineHeight'] ?? '')
    || '12px' !== ($quote['attrs']['style']['spacing']['margin']['bottom'] ?? '')
    || 'core/paragraph' !== ($caption['blockName'] ?? '')
    || 'pass' !== ($result['source_reports']['wp_block_validity']['status'] ?? '')
) {
    throw new RuntimeException('Boxed figure must retain an independent quotation box: ' . $result['serialized_blocks']);
}
$css = implode("\n", array_column(array_filter($result['assets'], static fn (array $asset): bool => 'css' === $asset['kind']), 'content'));
preg_match('/blocks-engine-semantic-[^ ]+/', $caption['attrs']['className'] ?? '', $markers);
$marker = $markers[0] ?? '';
if ('' === $marker || !str_contains($css, 'p.' . $marker) || !str_contains($css, 'font-size:9px;font-weight:700')) {
    throw new RuntimeException('Caption author rules must reach its paragraph host: ' . $css);
}
echo "Boxed figure quote passed\n";
