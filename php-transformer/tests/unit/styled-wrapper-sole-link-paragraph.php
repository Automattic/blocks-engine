<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};

// Issue #6559: rebuilding a folded paragraph must retain anchor-leading
// RichText in its attributes, just as it retains text and emphasis.
$contents = array(
    '<a href="/y">Link text</a>',
    '<a href="https://news.example.edu/a/">https://news.example.edu/a...</a>',
    'Plain text',
    '<em>Emph</em>',
    'See <a href="/y">link</a>',
);
foreach (array('.x{color:red}', '*{color:#444}') as $css) {
    foreach ($contents as $content) {
        $result = (new HtmlTransformer())->transform(
            '<style>' . $css . '</style><main><div class="x"><p>' . $content . '</p></div></main>'
        )->toArray();
        // Universal color already uses an inline mark for emphasis; retain
        // that existing styling contract rather than requiring raw source HTML.
        $expected = '*{color:#444}' === $css && '<em>Emph</em>' === $content
            ? '<em><mark style="color:#444;background-color:transparent" role="none">Emph</mark></em>'
            : $content;
        $block = $result['blocks'][0];
        $assert('core/paragraph' === $block['blockName'], 'The styled wrapper still folds into a native paragraph.');
        $assert(array() === $block['innerBlocks'], 'The fold does not add a nested block.');
        $assert(1 === preg_match('/^<p\b[^>]*>' . preg_quote($expected, '/') . '<\/p>$/', $block['innerHTML']), 'The folded paragraph keeps its exact inline content: ' . $content);
        $assert(str_contains($result['serialized_blocks'], $expected), 'Saved block markup retains the inline content.');
        foreach ($result['diagnostics'] ?? array() as $diagnostic) {
            $assert('html_content_dropped' !== ($diagnostic['code'] ?? ''), 'The fold must not report dropped content.');
        }
        $assert('pass' === $result['source_reports']['wp_block_validity']['status'], 'The folded paragraph remains editor-valid.');
    }
}

fwrite(STDOUT, "Styled wrapper sole-link paragraph passed\n");
