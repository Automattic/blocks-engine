<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;
use Automattic\BlocksEngine\PhpTransformer\WordPress\BlockValidityValidator;

// A caption's smaller type must own a block line box, not share the
// larger native cite strut. Retain the figure's two independent children.
$result = (new HtmlTransformer())->transform(
    '<style>.testimonial blockquote{font-size:14px;line-height:1.45;margin:0 0 18px}'
    . '.testimonial figcaption{font-size:9px;font-weight:700;line-height:1.5;letter-spacing:1.44px}</style>'
    . '<figure class="testimonial"><blockquote>A quotation</blockquote><figcaption>Publication</figcaption></figure>'
)->toArray();
$figure = $result['blocks'][0] ?? array();
$quote = $figure['innerBlocks'][0] ?? array();
$caption = $figure['innerBlocks'][1] ?? array();
$captionClass = $caption['attrs']['className'] ?? '';
$authorCss = implode("\n", array_column(array_filter($result['assets'], static fn (array $asset): bool => 'author-css' === ($asset['source'] ?? '')), 'content'));
if ('' === $captionClass || !str_contains($authorCss, '.' . $captionClass) || !str_contains($authorCss, 'font-size:9px')) {
    throw new RuntimeException('Caption rules must follow the independent paragraph carrier');
}
if ('core/group' !== ($figure['blockName'] ?? '')
    || 'core/quote' !== ($quote['blockName'] ?? '')
    || 'core/paragraph' !== ($caption['blockName'] ?? '')
    || 'Publication' !== ($caption['attrs']['content'] ?? '')
    || !empty($quote['attrs']['citation'])
    || '14px' !== ($quote['attrs']['style']['typography']['fontSize'] ?? '')
    || '1.45' !== ($quote['attrs']['style']['typography']['lineHeight'] ?? '')
    || 'pass' !== ((new BlockValidityValidator())->validateBlocks($result['blocks'])['status'] ?? '')
) {
    throw new RuntimeException('Styled figure captions need independent block line boxes: ' . $result['serialized_blocks']);
}
// Ancestor typography does not require a separate authored caption box.
$inherited = (new HtmlTransformer())->transform(
    '<style>figure{font-size:16px;color:#123456}</style>'
    . '<figure><blockquote>Another quotation</blockquote><figcaption><b>Author</b><span>Title</span></figcaption></figure>'
)->toArray();
if ('<b>Author</b><span>Title</span>' !== ($inherited['blocks'][0]['attrs']['citation'] ?? '')) {
    throw new RuntimeException('Inherited caption typography must not change citation topology');
}
// Retaining the figure must not transfer its quotation's geometry or metrics
// to the caption or to an unrelated paragraph.
$scoped = (new HtmlTransformer())->transform(
    '<style>.review blockquote{font-size:14px;line-height:1.45;width:237px;margin:18px}'
    . '.review figcaption{font-size:9px;font-weight:700}p{font-size:16px}</style>'
    . '<figure class="review"><blockquote>Review text</blockquote><figcaption>Reviewer</figcaption></figure>'
    . '<p>Unrelated text</p>'
)->toArray();
$review = $scoped['blocks'][0]['innerBlocks'][0]['attrs'] ?? array();
$sibling = $scoped['blocks'][1]['attrs'] ?? array();
if ('14px' !== ($review['style']['typography']['fontSize'] ?? '')
    || isset($sibling['style']['typography']['fontSize'])
    || 'pass' !== ((new BlockValidityValidator())->validateBlocks($scoped['blocks'])['status'] ?? '')
) {
    throw new RuntimeException('Quote text styles must stay local: ' . $scoped['serialized_blocks']);
}
echo "Figure quote presentation passed\n";
