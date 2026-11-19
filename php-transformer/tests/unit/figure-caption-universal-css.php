<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

// Caption paragraphs stay inside their source ancestors. A universal reset
// already reaches them and must not grow a selector arm for every caption.
$html = '<style>*{box-sizing:border-box}figure > *{outline-color:red}'
    . 'figcaption{font-size:9px;font-weight:700}</style>';
$html .= str_repeat('<figure><p>Review</p><figcaption>Publication</figcaption></figure>', 24);
$result = (new HtmlTransformer())->transform($html)->toArray();
$css = implode("\n", array_column(array_filter($result['assets'], static fn (array $asset): bool => 'css' === $asset['kind']), 'content'));
foreach (explode('}', $css) as $rule) {
    if ((str_contains($rule, 'box-sizing:border-box') || str_contains($rule, 'outline-color:red'))
        && str_contains($rule, 'p.blocks-engine-semantic-')) {
        throw new RuntimeException('Universal rules must not expand into caption-specific arms: ' . $rule);
    }
}
if (!str_contains($css, 'p.blocks-engine-semantic-') || !str_contains($css, 'font-size:9px;font-weight:700')) {
    throw new RuntimeException('Explicit caption typography must still reach its paragraph subject.');
}
if ('pass' !== ($result['source_reports']['wp_block_validity']['status'] ?? '')) {
    throw new RuntimeException('Caption blocks must remain valid.');
}
echo "Figure caption universal CSS passed\n";
