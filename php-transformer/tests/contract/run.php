<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\Contract\TransformerResult;
use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\FormatBridge\FormatAdapterInterface;
use Automattic\BlocksEngine\PhpTransformer\FormatBridge\FormatBridge;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

if ( ! function_exists('serialize_blocks') ) {
    /**
     * @param array<int, array<string, mixed>> $blocks
     */
    function serialize_blocks(array $blocks): string
    {
        $serialized = '';
        foreach ( $blocks as $block ) {
            $name         = $block['blockName'];
            $attrs        = empty($block['attrs']) ? '' : ' ' . json_encode($block['attrs'], JSON_UNESCAPED_SLASHES);
            $innerContent = $block['innerContent'] ?? array();
            $innerBlocks  = $block['innerBlocks'] ?? array();
            $inner        = '';

            foreach ( $innerContent as $part ) {
                if ( null === $part ) {
                    $inner .= serialize_blocks(array( array_shift($innerBlocks) ));
                    continue;
                }
                $inner .= $part;
            }

            $serialized .= '<!-- wp:' . substr($name, 5) . $attrs . ' -->' . $inner . '<!-- /wp:' . substr($name, 5) . ' -->';
        }

        return $serialized;
    }
}

$assert = static function (bool $condition, string $message, string $detail = ''): void {
    if ( $condition ) {
        return;
    }

    fwrite(STDERR, 'FAIL: ' . $message . ('' !== $detail ? ' - ' . $detail : '') . PHP_EOL);
    exit(1);
};

$fixture = file_get_contents(dirname(__DIR__) . '/fixtures/simple-html.html');
$result  = ( new HtmlTransformer() )->transform($fixture . "\n<ul><li>One</li><li><strong>Two</strong></li></ul><aside>Fallback</aside>")->toArray();

$assert(TransformerResult::SCHEMA === $result['schema'], 'result exposes schema');

foreach ( array( 'status', 'components', 'block_types', 'source_reports', 'legacy_mapping', 'blocks', 'serialized_blocks', 'documents', 'assets', 'diagnostics', 'fallbacks', 'provenance', 'coverage' ) as $key ) {
    $assert(array_key_exists($key, $result), "Missing result key: {$key}");
}

$compiler = new ArtifactCompiler();

$simple = $compiler->compile(
    array(
        'generated_html' => '<main><article data-component="Hero"><h1>Hello artifact</h1></article></main>',
    )
)->toArray();
$assert('success' === $simple['status'], 'simple artifact compiles successfully', (string) $simple['status']);
$assert('index.html' === ($simple['source_reports']['artifact']['entry_path'] ?? ''), 'generated HTML becomes an index entry');
$assert(str_contains((string) $simple['serialized_blocks'], '<!-- wp:html -->'), 'HTML is preserved as serialized block markup');
$assert('Hero' === ($simple['components'][0]['name'] ?? ''), 'component candidates are exposed');
$assert('serialized_blocks' === ($simple['legacy_mapping']['block-artifact-compiler/result/v1']['wordpress_artifacts.block_markup'] ?? ''), 'BAC block markup mapping is documented');

$missing = $compiler->compile(array('files' => array()))->toArray();
$assert('failed' === $missing['status'], 'missing HTML fails explicitly', (string) $missing['status']);
$assert('missing_entry_html' === ($missing['diagnostics'][0]['code'] ?? ''), 'missing entry diagnostic is exposed');

$unsafe = $compiler->compile(
    array(
        'entrypoints' => array('../unsafe.html'),
        'files'       => array(
            '../secret.html' => '<main>Nope</main>',
            'safe.html'     => '<main>Safe</main>',
        ),
    )
)->toArray();
$assert('success_with_warnings' === $unsafe['status'], 'unsafe paths produce warning status', (string) $unsafe['status']);
$assert(1 === ($unsafe['source_reports']['artifact']['rejected_count'] ?? null), 'unsafe paths are rejected');
$assert('unsafe_entrypoint_path' === ($unsafe['diagnostics'][0]['code'] ?? ''), 'unsafe entrypoints are diagnosed');

$binary = $compiler->compile(
    array(
        'entrypoint' => 'pages/home.html',
        'files'      => array(
            array(
                'path'           => 'pages/home.html',
                'content_base64' => base64_encode('<main><h1>Encoded</h1></main>'),
                'mime_type'      => 'text/html',
                'role'           => 'entry',
            ),
            array(
                'path'           => 'assets/logo.png',
                'content_base64' => base64_encode("\x89PNG\r\n\x1a\n"),
                'mime_type'      => 'image/png',
                'role'           => 'brand-asset',
            ),
            array(
                'path'           => 'assets/bad.bin',
                'content_base64' => 'not-valid-base64',
            ),
        ),
    )
)->toArray();
$assert('success_with_warnings' === $binary['status'], 'invalid base64 is a non-blocking warning', (string) $binary['status']);
$assert('pages/home.html' === ($binary['source_reports']['artifact']['entry_path'] ?? ''), 'base64 HTML entry is decoded and selected');
$assert(1 === ($binary['source_reports']['artifact']['files_by_mime']['image/png'] ?? 0), 'MIME counts include binary assets');
$assert(1 === ($binary['source_reports']['artifact']['files_by_role']['brand-asset'] ?? 0), 'role counts include binary assets');
$assert(1 === ($binary['source_reports']['artifact']['rejected_count'] ?? null), 'invalid base64 file is rejected');
$assert('assets/logo.png' === ($binary['assets'][0]['path'] ?? ''), 'binary asset appears in manifest');
$assert(true === ($binary['assets'][0]['binary'] ?? null), 'binary asset is marked binary');
$assert(! empty($binary['assets'][0]['content_base64'] ?? ''), 'binary asset keeps base64 payload');

$blocks = $compiler->compile(
    array(
        'files' => array(
            'index.html'             => '<main><h1>Block type</h1></main>',
            'blocks/hero/block.json' => json_encode(array('apiVersion' => 3, 'name' => 'acme/hero', 'title' => 'Hero'), JSON_UNESCAPED_SLASHES),
        ),
    )
)->toArray();
$assert(1 === count($blocks['block_types']), 'block.json roots are promoted into block type artifacts');
$assert('acme/hero' === ($blocks['block_types'][0]['name'] ?? ''), 'block type name is preserved');

$normalized = $compiler->compile(
    array(
        'entry'   => 'public/index.html',
        'files'   => array(
            array(
                'name' => 'public/index.html',
                'body' => '<main><h1>Aliases</h1></main>',
            ),
            'public/index.html' => '<main><h1>Duplicate path</h1></main>',
            'data/settings.json' => '{"ok":true}',
            'docs/readme.mdx' => '# Hello',
        ),
        'styles'  => 'body { color: rebeccapurple; }',
        'script'  => 'console.log("artifact");',
        'outputs' => array(
            array(
                'name' => 'assets/icon.svg',
                'content' => '<svg xmlns="http://www.w3.org/2000/svg"></svg>',
            ),
        ),
    )
)->toArray();
$assert('public/index.html' === ($normalized['source_reports']['artifact']['entry_path'] ?? ''), 'entry alias selects public index HTML');
$assetPaths = array_column($normalized['assets'], 'path');
$assert(in_array('public/index-2.html', $assetPaths, true), 'duplicate paths are deduped deterministically');
$assert(in_array('style.css', $assetPaths, true), 'styles shorthand becomes a CSS file');
$assert(in_array('site.js', $assetPaths, true), 'script shorthand becomes a JS file');
$assert(1 === ($normalized['source_reports']['artifact']['files_by_mime']['text/mdx'] ?? 0), 'MDX MIME is inferred');
$assert(1 === ($normalized['source_reports']['artifact']['files_by_role']['stylesheet'] ?? 0), 'CSS role is inferred');
$assert(1 === ($normalized['source_reports']['artifact']['files_by_intent']['behavior'] ?? 0), 'JS intent is inferred');
$assert(1 === ($normalized['source_reports']['artifact']['files_by_source']['styles'] ?? 0), 'source counts include top-level shorthand source');
$assert(! empty($normalized['source_reports']['artifact']['source_hash'] ?? ''), 'stable source hash is exposed in source reports');
$scriptAsset = null;
foreach ( $normalized['assets'] as $asset ) {
    if ( 'site.js' === ($asset['path'] ?? '') ) {
        $scriptAsset = $asset;
        break;
    }
}
$assert('script' === ($scriptAsset['role'] ?? ''), 'JS asset role is exposed in manifest');
$assert('behavior' === ($scriptAsset['intent'] ?? ''), 'JS asset intent is exposed in manifest');

$tooLarge = $compiler->compile(
    array(
        'files' => array(
            'index.html' => '<main>OK</main>',
            'huge.txt' => str_repeat('x', 1048577),
        ),
    )
)->toArray();
$assert('success_with_warnings' === $tooLarge['status'], 'oversized files are rejected with a warning status');
$assert(1 === ($tooLarge['source_reports']['artifact']['rejected_count'] ?? null), 'oversized file increments rejected count');
$assert('artifact_file_too_large' === ($tooLarge['diagnostics'][0]['code'] ?? ''), 'oversized file diagnostic is exposed');

assertSame('core/group', $result['blocks'][0]['blockName'], 'main wrapper should preserve multiple supported child blocks in a group.');
assertSame('core/heading', $result['blocks'][0]['innerBlocks'][0]['blockName'], 'h1 should convert to a heading block.');
assertSame(1, $result['blocks'][0]['innerBlocks'][0]['attrs']['level'], 'h1 level should be preserved.');
assertSame('core/paragraph', $result['blocks'][0]['innerBlocks'][1]['blockName'], 'p should convert to a paragraph block.');
assertSame('core/list', $result['blocks'][1]['blockName'], 'ul should convert to a list block.');
assertSame('core/list-item', $result['blocks'][1]['innerBlocks'][0]['blockName'], 'li should convert to list-item blocks.');
assertSame('unsupported_element', $result['fallbacks'][0]['type'], 'unsupported top-level elements should be reported as fallbacks.');
assertSame('aside', $result['fallbacks'][0]['tag'], 'fallback should identify the unsupported tag.');
assertContains('html_to_blocks_minimal', array_column($result['diagnostics'], 'code'), 'minimal conversion diagnostic should be present.');
assertSame('html', $result['provenance'][0]['source_format'], 'source provenance should identify HTML input.');

if ( ! str_contains($result['serialized_blocks'], '<!-- wp:heading {"content":"Hello blocks","level":1} -->') ) {
    fwrite(STDERR, "Serialized blocks did not include the expected heading block.\n");
    exit(1);
}

fwrite(STDOUT, "HTML-to-blocks contract passed.\n");

$bridge = new FormatBridge();

assertSame(array( 'blocks', 'html', 'markdown' ), $bridge->supportedFormats(), 'Default supported formats should be stable.');
assertSame("# Title\n\nBody\n", $bridge->normalize("# Title\r\n\r\nBody\r\n", 'markdown'), 'Markdown line endings should normalize to LF.');
assertSame('<main><h1>Hello</h1></main>', $bridge->normalize('<main><h1>Hello</h1></main>', 'html'), 'HTML normalization should preserve valid HTML.');
assertSame('<!-- wp:paragraph --><p>Hello</p><!-- /wp:paragraph -->', $bridge->normalize('<!-- wp:paragraph --><p>Hello</p><!-- /wp:paragraph -->', 'blocks'), 'Serialized blocks should pass validation.');
assertThrows(static fn () => $bridge->normalize('<!-- wp:paragraph /-->', 'markdown'), 'Declared markdown content contains serialized block comments.');
assertThrows(static fn () => $bridge->normalize("# Title\n<p>Hello</p>", 'html'), 'Declared HTML content contains markdown markers.');
assertThrows(static fn () => $bridge->normalize('<p>Hello</p>', 'blocks'), 'Declared blocks content does not contain serialized block comments.');
assertThrows(static fn () => $bridge->normalize('<!-- wp:paragraph --><p>Hello</p>', 'blocks'), 'Serialized block markup contains an unclosed block comment.');
assertThrows(static fn () => $bridge->normalize('<!-- wp:paragraph --><p>Hello</p><!-- /wp:heading -->', 'blocks'), 'Mismatched serialized block closing comment.');

$bridge->registerAdapter(new class implements FormatAdapterInterface {
    public function slug(): string
    {
        return 'plain';
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function toBlocks(string $content, array $options = array()): array
    {
        return array(
            array(
                'blockName'    => 'core/paragraph',
                'attrs'        => array(),
                'innerBlocks'  => array(),
                'innerHTML'    => '<p>' . $content . '</p>',
                'innerContent' => array( '<p>' . $content . '</p>' ),
            ),
        );
    }

    /**
     * @param array<int, array<string, mixed>> $blocks
     */
    public function fromBlocks(array $blocks, array $options = array()): string
    {
        return 'plain output';
    }

    public function detect(string $content): bool
    {
        return '' !== trim($content);
    }
});

assertSame(array( 'blocks', 'html', 'markdown', 'plain' ), $bridge->supportedFormats(), 'Registered adapters should extend supported formats.');
assertSame('plain output', $bridge->convert('<p>Hello</p>', 'html', 'plain'), 'Conversion stubs should hand block pivot to registered target adapters.');

fwrite(STDOUT, "Format bridge scaffold passed.\n");

function assertSame(mixed $expected, mixed $actual, string $message): void
{
    if ( $expected !== $actual ) {
        fwrite(STDERR, $message . "\nExpected: " . var_export($expected, true) . "\nActual: " . var_export($actual, true) . "\n");
        exit(1);
    }
}

function assertContains(mixed $needle, array $haystack, string $message): void
{
    if ( ! in_array($needle, $haystack, true) ) {
        fwrite(STDERR, $message . "\nNeedle: " . var_export($needle, true) . "\nHaystack: " . var_export($haystack, true) . "\n");
        exit(1);
    }
}

function assertThrows(callable $callback, string $expectedMessage): void
{
    try {
        $callback();
    } catch ( \InvalidArgumentException $exception ) {
        if ( $expectedMessage === $exception->getMessage() ) {
            return;
        }

        fwrite(STDERR, "Unexpected exception message.\n");
        fwrite(STDERR, 'Expected: ' . $expectedMessage . "\n");
        fwrite(STDERR, 'Actual: ' . $exception->getMessage() . "\n");
        exit(1);
    }

    fwrite(STDERR, 'Expected exception: ' . $expectedMessage . "\n");
    exit(1);
}
