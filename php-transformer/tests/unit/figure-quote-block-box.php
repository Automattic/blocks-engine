<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;
use Automattic\BlocksEngine\PhpTransformer\WordPress\BlockValidityValidator;

// A collapsed `figure > blockquote + figcaption` keeps both source block boxes:
// the paragraph standing in for the blockquote stays a block (not an inline
// run on the quote's 16px/24px strut) with the blockquote's margin, and the
// caption span stays a block so its 9px line box is not lifted to the strut.
// Otherwise every testimonial card grows and shifts the rest of the page.
$result = (new HtmlTransformer())->transform(
    '<style>body{font-size:16px;line-height:1.5}'
    . '.quote-item{min-height:104px;margin:0;padding:22px 24px 18px}'
    . '.quote-item blockquote{margin:0 0 12px;font-size:14px;font-style:italic;line-height:1.45}'
    . '.quote-item figcaption{letter-spacing:.16em;text-transform:uppercase;font-size:9px;font-weight:700}</style>'
    . '<section><figure class="quote-item"><blockquote>“<!---->A rare thing.<!---->”</blockquote>'
    . '<figcaption>Publication</figcaption></figure></section>'
)->toArray();
$serialized = $result['serialized_blocks'];
if (!preg_match('/<blockquote class="wp-block-quote quote-item"><!-- wp:paragraph [^>]*--><p class="([^"]*)" style="([^"]*)">/', $serialized, $paragraph)
    || !str_contains($paragraph[1], 'blocks-engine-source-box-paragraph')
    || !str_contains($paragraph[2], 'margin-bottom:12px')
    || !preg_match('/<cite><span style="[^"]*font-size:9px[^"]*display:block[^"]*">Publication<\/span><\/cite>/', $serialized)
    || 'pass' !== ((new BlockValidityValidator())->validateBlocks($result['blocks'])['status'] ?? '')
) {
    throw new RuntimeException('Collapsed figure quote must keep the blockquote and caption block boxes: ' . $serialized);
}

// An author-inline blockquote or caption stays an inline run.
$inline = (new HtmlTransformer())->transform(
    '<style>.q blockquote{display:inline;margin:0 0 12px;font-size:14px}.q figcaption{display:inline;font-size:9px}</style>'
    . '<figure class="q"><blockquote>Inline quote</blockquote><figcaption>Inline caption</figcaption></figure>'
)->toArray()['serialized_blocks'];
if (str_contains($inline, 'blocks-engine-source-box-paragraph') || str_contains($inline, 'margin-bottom:12px') || str_contains($inline, 'display:block')) {
    throw new RuntimeException('Inline quotation boxes must stay inline runs: ' . $inline);
}
echo "Figure quote block box passed\n";
