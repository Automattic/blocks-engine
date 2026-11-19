<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;
use Automattic\BlocksEngine\PhpTransformer\WordPress\BlockValidityValidator;

$result = (new HtmlTransformer())->transform(
    '<style>.testimonial blockquote{font-size:14px;line-height:1.45;margin:0 0 18px}'
    . '.testimonial figcaption{font-size:9px;font-weight:700;line-height:1.5;letter-spacing:1.44px}</style>'
    . '<figure class="testimonial"><blockquote>A quotation</blockquote><figcaption>Publication</figcaption></figure>'
)->toArray();
$quote = $result['blocks'][0] ?? array();
$text = $quote['innerBlocks'][0] ?? array();
if ('core/quote' !== ($quote['blockName'] ?? '')
    || 'core/paragraph' !== ($text['blockName'] ?? '')
    || '14px' !== ($text['attrs']['style']['typography']['fontSize'] ?? '')
    || '1.45' !== ($text['attrs']['style']['typography']['lineHeight'] ?? '')
    // The paragraph is the blockquote's block box in the figure (#6445): its
    // margin keeps spacing the quotation from the caption.
    || array('bottom' => '18px') !== ($text['attrs']['style']['spacing']['margin'] ?? null)
    || !str_contains($quote['attrs']['citation'] ?? '', 'font-size:9px;font-weight:700;line-height:1.5;letter-spacing:1.44px')
    || 'pass' !== ((new BlockValidityValidator())->validateBlocks($result['blocks'])['status'] ?? '')
) {
    throw new RuntimeException('Collapsed figure quote must preserve independent quotation and caption typography: ' . $result['serialized_blocks']);
}
// Ancestor typography already survives on the figure. Do not add a
// citation wrapper or re-project its existing RichText descendants.
$inherited = (new HtmlTransformer())->transform(
    '<style>figure{font-size:16px;color:#123456}</style>'
    . '<figure><blockquote>Another quotation</blockquote><figcaption><b>Author</b><span>Title</span></figcaption></figure>'
)->toArray();
if ('<b>Author</b><span>Title</span>' !== ($inherited['blocks'][0]['attrs']['citation'] ?? '')) {
    throw new RuntimeException('Inherited caption typography must not change citation topology');
}
// Consuming the quote must not change another paragraph's typography or
// transfer the quotation's size onto its synthetic text carrier.
$scoped = (new HtmlTransformer())->transform(
    '<style>.review blockquote{font-size:14px;line-height:1.45;width:237px;margin:18px}'
    . '.review figcaption{font-size:9px;font-weight:700}p{font-size:16px}</style>'
    . '<figure class="review"><blockquote>Review text</blockquote><figcaption>Reviewer</figcaption></figure>'
    . '<p>Unrelated text</p>'
)->toArray();
$reviewText = $scoped['blocks'][0]['innerBlocks'][0]['attrs'] ?? array();
$sibling = $scoped['blocks'][1]['attrs'] ?? array();
if ('14px' !== ($reviewText['style']['typography']['fontSize'] ?? '')
    || isset($sibling['style']['typography']['fontSize'])
    || array('typography', 'spacing') !== array_keys($reviewText['style'] ?? array())
    || array('margin') !== array_keys($reviewText['style']['spacing'])
    || isset($reviewText['inlineGeometryStyle'])
    || 'pass' !== ((new BlockValidityValidator())->validateBlocks($scoped['blocks'])['status'] ?? '')
) {
    throw new RuntimeException('Collapsed quote text styles must stay local: ' . $scoped['serialized_blocks']);
}
echo "Figure quote presentation passed\n";
