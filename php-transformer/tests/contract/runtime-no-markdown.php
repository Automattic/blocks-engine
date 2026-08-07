<?php
declare(strict_types=1);

// Markdown support is optional: consumers that vendor src/ in-tree may ship it
// without league/commonmark + league/html-to-markdown, and may omit
// MarkdownAdapter.php entirely. This contract runs WITHOUT the composer
// autoloader so League is never loadable, and exercises both degraded shapes:
//
//   A) MarkdownAdapter.php absent  — the class itself cannot autoload.
//   B) MarkdownAdapter.php present — the class loads but its League
//      dependencies do not.
//
// In both shapes FormatBridge must construct, refuse markdown as a format,
// keep html/blocks conversions working, and ArtifactCompiler must degrade
// markdown documents to the core/html fallback with a diagnostic.

$GLOBALS['a8c_be_skip_markdown_adapter'] = true;

spl_autoload_register(static function (string $class): void {
    $prefix = 'Automattic\\BlocksEngine\\PhpTransformer\\';
    if ( ! str_starts_with($class, $prefix) ) {
        return;
    }

    if ( ! empty($GLOBALS['a8c_be_skip_markdown_adapter']) && str_ends_with($class, 'FormatBridge\\MarkdownAdapter') ) {
        return;
    }

    $path = dirname(__DIR__, 2) . '/src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    if ( is_file($path) ) {
        require $path;
    }
});

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\FormatBridge\FormatBridge;
use Automattic\BlocksEngine\PhpTransformer\FormatBridge\MarkdownAdapter;

assertSame(false, class_exists('League\\CommonMark\\GithubFlavoredMarkdownConverter'), 'This contract must run without the League markdown dependencies.');

// Shape A: MarkdownAdapter.php is not vendored at all.
$bridge = new FormatBridge();
assertSame(array( 'blocks', 'html' ), $bridge->supportedFormats(), 'Without the markdown adapter, supported formats should advertise only blocks and html.');
assertSame(false, $bridge->supports('markdown'), 'Without the markdown adapter, markdown should be unsupported.');

$markdownResult = $bridge->convertResult("# Title\n\nBody", 'markdown', 'blocks')->toArray();
assertSame('failed', $markdownResult['status'] ?? null, 'Markdown conversion should fail cleanly without the adapter.');
assertSame('unsupported_source_format', $markdownResult['diagnostics'][0]['code'] ?? null, 'Markdown conversion failure should report the unsupported source format.');

$htmlResult = $bridge->convertResult('<p>Hello</p>', 'html', 'blocks')->toArray();
assertSame('success', $htmlResult['status'] ?? null, 'HTML to blocks conversion should keep working without the markdown adapter.');
assertSame('core/paragraph', $htmlResult['blocks'][0]['blockName'] ?? null, 'HTML to blocks conversion should still produce blocks.');

$compiled = ( new ArtifactCompiler() )->compile(
    array(
        'files' => array(
            'content/about.md' => "# About\n\nMarkdown body.",
        ),
    )
)->toArray();
$document = $compiled['documents'][0] ?? array();
assertSame('content/about.md', $document['source_path'] ?? null, 'Markdown source documents should still be compiled without the adapter.');
assertSame(true, str_contains((string) ($document['block_markup'] ?? ''), '<!-- wp:html -->'), 'Markdown documents should degrade to the core/html fallback without the adapter.');
assertSame(true, str_contains((string) ($document['block_markup'] ?? ''), 'Markdown body.'), 'The core/html fallback should preserve the source markdown.');
$diagnosticCodes = array_map(
    static fn (array $diagnostic): string => (string) ($diagnostic['code'] ?? ''),
    is_array($document['diagnostics'] ?? null) ? $document['diagnostics'] : array()
);
assertSame(true, in_array('markdown_adapter_unavailable', $diagnosticCodes, true), 'Markdown degradation should surface the markdown_adapter_unavailable diagnostic.');

// Shape B: MarkdownAdapter.php is vendored but League is missing.
$GLOBALS['a8c_be_skip_markdown_adapter'] = false;

assertSame(true, class_exists(MarkdownAdapter::class), 'Shape B expects the markdown adapter class itself to load.');
assertSame(false, MarkdownAdapter::isAvailable(), 'The markdown adapter should report unavailability when League is missing.');

$bridge = new FormatBridge();
assertSame(false, $bridge->supports('markdown'), 'A dependency-less markdown adapter should not register, rather than silently returning empty output.');
assertSame(array( 'blocks', 'html' ), $bridge->supportedFormats(), 'A dependency-less markdown adapter should not advertise markdown support.');

$markdownResult = $bridge->convertResult("# Title\n\nBody", 'markdown', 'blocks')->toArray();
assertSame('failed', $markdownResult['status'] ?? null, 'Markdown conversion should fail cleanly when League is missing.');
assertSame('unsupported_source_format', $markdownResult['diagnostics'][0]['code'] ?? null, 'Markdown conversion failure should report the unsupported source format when League is missing.');

fwrite(STDOUT, "Markdown-optional runtime contract passed.\n");

function assertSame(mixed $expected, mixed $actual, string $message): void
{
    if ( $expected !== $actual ) {
        fwrite(STDERR, $message . "\nExpected: " . var_export($expected, true) . "\nActual: " . var_export($actual, true) . "\n");
        exit(1);
    }
}
