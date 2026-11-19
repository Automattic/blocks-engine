<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;
use Automattic\BlocksEngine\PhpTransformer\WordPress\BlockValidityValidator;

// A small consumed caption needs its own line box, without changing the
// native cite's font metrics (which may also control theme em-based spacing).
$result = (new HtmlTransformer())->transform(
    '<style>body{font-size:16px;line-height:1.5}.review figcaption{font-size:9px;font-weight:700}</style>'
    . '<figure class="review"><blockquote>A review</blockquote><figcaption>Publication</figcaption></figure>'
)->toArray();
$document = new DOMDocument();
$document->loadHTML($result['serialized_blocks'], LIBXML_NOERROR | LIBXML_NOWARNING);
$xpath = new DOMXPath($document);
$cite = $xpath->query('//cite')->item(0);
$caption = $xpath->query('//cite/span')->item(0);
$css = implode("\n", array_column(array_filter($result['assets'], static fn(array $asset): bool => 'css' === ($asset['kind'] ?? '')), 'content'));
if (!$cite instanceof DOMElement
    || !$caption instanceof DOMElement
    || 'font-size:9px;font-weight:700;display:block' !== $caption->getAttribute('style')
    || $cite->hasAttribute('style')
    || str_contains($css, ' > cite{')
    || 'Publication' !== $caption->textContent
    || 'pass' !== ((new BlockValidityValidator())->validateBlocks($result['blocks'])['status'] ?? '')
) {
    throw new RuntimeException('Consumed figcaption must retain an independent line box without restyling cite: ' . $result['serialized_blocks']);
}
echo "Figure caption line box passed\n";
