<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;
use Automattic\BlocksEngine\PhpTransformer\WordPress\BlockValidityValidator;

// Small caption text must not share the native cite's larger line-box strut.
// Repeated cards otherwise increase their row height and shift later sections.
$result = (new HtmlTransformer())->transform(
    '<style>body{font-size:16px;line-height:1.5}.review figcaption{font-size:9px;font-weight:700}</style>'
    . '<figure class="review"><blockquote>A review</blockquote><figcaption>Publication</figcaption></figure>'
)->toArray();
$document = new DOMDocument();
$document->loadHTML($result['serialized_blocks'], LIBXML_NOERROR | LIBXML_NOWARNING);
$caption = (new DOMXPath($document))->query('//cite')->item(0);
$css = implode("\n", array_column(array_filter($result['assets'], static fn(array $asset): bool => 'css' === ($asset['kind'] ?? '')), 'content'));
$carrier = $result['blocks'][0]['attrs']['className'] ?? '';
preg_match('/be-inline-geometry-[a-z0-9]+/', $carrier, $match);
if (!$caption instanceof DOMElement
    || !isset($match[0])
    || !str_contains($css, '.' . $match[0] . ' > cite{')
    || $caption->getElementsByTagName('span')->length > 0
    || !str_contains($css, ' > cite{font-size:9px;font-weight:700;display:block}')
    || 'Publication' !== $caption->textContent
    || 'pass' !== ((new BlockValidityValidator())->validateBlocks($result['blocks'])['status'] ?? '')
) {
    throw new RuntimeException('Consumed figcaption must retain an independent block line box: ' . $result['serialized_blocks']);
}
echo "Figure caption line box passed\n";
