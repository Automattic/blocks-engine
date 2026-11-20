<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;
use Automattic\BlocksEngine\PhpTransformer\WordPress\BlockValidityValidator;

// A small consumed caption needs its own line box, without changing the
// native cite's font metrics (which may also control theme em-based spacing).
$result = (new HtmlTransformer())->transform(
    '<style>body{font-size:16px;line-height:1.5}.review blockquote{font-size:14px;line-height:1.45;margin:0 0 12px;width:230px}.review figcaption{font-size:9px;font-weight:700}</style>'
    . '<figure class="review"><blockquote>A review</blockquote><figcaption>Publication</figcaption></figure><p>Unrelated text</p>'
)->toArray();
$document = new DOMDocument();
$document->loadHTML($result['serialized_blocks'], LIBXML_NOERROR | LIBXML_NOWARNING);
$xpath = new DOMXPath($document);
$cite = $xpath->query('//cite')->item(0);
$caption = $xpath->query('//cite/span')->item(0);
$css = implode("\n", array_column(array_filter($result['assets'], static fn(array $asset): bool => 'css' === ($asset['kind'] ?? '')), 'content'));
$quotation = $result['blocks'][0]['innerBlocks'][0] ?? array();
preg_match('/be-inline-geometry-[a-z0-9]+/', $quotation['attrs']['className'] ?? '', $quotationClass);
// Inline synthetic text uses its parent's 24px strut instead of the source's
// 20.3px lines; turning only the caption into a block over-corrects the row.
// The consumed quotation needs its own block lines AND its authored margin.
if (!isset($quotationClass[0])
    || !str_contains($css, 'p.' . $quotationClass[0] . '{display:block;margin:0 0 12px}')
    || str_contains($css, 'p.' . $quotationClass[0] . '{display:block;margin:0 0 12px;width:')
    || str_contains($result['blocks'][1]['attrs']['className'] ?? '', $quotationClass[0])
) {
    throw new RuntimeException('Only the consumed quotation must recover its block line boxes and authored separation');
}
if (!$cite instanceof DOMElement
    || !$caption instanceof DOMElement
    || 'font-size:9px;font-weight:700;display:block' !== $caption->getAttribute('style')
    || $cite->hasAttribute('style')
    || !str_contains($css, ' > cite{display:block;font-style:inherit}')
    || preg_match('/ > cite\\{[^}]*(?:font-size|font-family|font-weight):/', $css)
    || 'Publication' !== $caption->textContent
    || 'pass' !== ((new BlockValidityValidator())->validateBlocks($result['blocks'])['status'] ?? '')
) {
    throw new RuntimeException('Consumed figcaption must retain an independent line box without restyling cite: ' . $result['serialized_blocks']);
}
// The generated cite must not impose its UA italic style. Inheritance also
// preserves an italic figure, while an explicitly styled caption wins locally.
foreach (array('figure{font-style:italic}', 'figcaption{font-style:italic}') as $style) {
    $styled = (new HtmlTransformer())->transform(
        '<style>' . $style . 'figcaption{font-size:9px}</style>'
        . '<figure><blockquote>A review</blockquote><figcaption>Author</figcaption></figure>'
    )->toArray();
    $styledCss = implode("\n", array_column(array_filter($styled['assets'], static fn(array $asset): bool => 'css' === ($asset['kind'] ?? '')), 'content'));
    if (!str_contains($styledCss, ' > cite{display:block;font-style:inherit}')
        || ('figcaption{font-style:italic}' === $style && !str_contains($styled['blocks'][0]['attrs']['citation'], 'font-style:italic'))) {
        throw new RuntimeException('Caption flow must preserve source font style, including authored italics');
    }
}
// Existing citations and pullquotes do not have this direct-child caption topology.
foreach (array(
    '<figure class="testimonial-card"><blockquote>Build with care.</blockquote><figcaption><strong>Ada</strong>, Founder</figcaption></figure>',
    '<blockquote>A review<cite>Publication</cite></blockquote>',
    '<figure><blockquote>A review<cite>Existing citation</cite></blockquote><figcaption>Unused caption</figcaption></figure>',
    '<figure class="wp-block-pullquote"><blockquote>A review</blockquote><figcaption>Publication</figcaption></figure>',
) as $html) {
    $control = (new HtmlTransformer())->transform($html)->toArray();
    $controlCss = implode("\n", array_column(array_filter($control['assets'], static fn(array $asset): bool => 'css' === ($asset['kind'] ?? '')), 'content'));
    if (str_contains($controlCss, ' > cite{display:block')
        || str_contains($control['serialized_blocks'], 'testimonial-card be-inline-geometry-')) {
        throw new RuntimeException('Caption flow rule must not restyle existing citations or pullquotes');
    }
}
echo "Figure caption line box passed\n";
