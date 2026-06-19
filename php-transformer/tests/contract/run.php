<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\Contract\TransformerResult;
use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\FormatBridge\FormatAdapterInterface;
use Automattic\BlocksEngine\PhpTransformer\FormatBridge\FormatBridge;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\BlockFactory;
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
$assert('hero' === ($simple['components'][0]['name'] ?? ''), 'component candidates are exposed');
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
            'index.html'                    => '<main><section class="hero"><h1>Block type</h1></section><article class="card product-card" data-component="Product Card">A</article><article class="card product-card">B</article></main>',
            'blocks/hero/block.json'        => json_encode(
                array(
                    'apiVersion'   => 3,
                    'name'         => 'acme/hero',
                    'title'        => 'Hero',
                    'category'     => 'design',
                    'editorScript' => 'file:./index.js',
                    'viewScript'   => array('file:./view.js', 'wp-interactivity'),
                    'style'        => 'file:./style.css',
                    'editorStyle'  => 'file:./editor.css',
                    'render'       => 'file:./render.php',
                    'attributes'   => array(
                        'headline' => array('type' => 'string'),
                    ),
                    'supports'     => array('align' => true),
                ),
                JSON_UNESCAPED_SLASHES
            ),
            'blocks/hero/index.js'          => 'import metadata from "./block.json";',
            'blocks/hero/index.asset.php'   => '<?php return array("dependencies" => array("wp-blocks"), "version" => "1");',
            'blocks/hero/view.js'           => 'console.log("front");',
            'blocks/hero/style.css'         => '.wp-block-acme-hero{padding:2rem}',
            'blocks/hero/editor.css'        => '.wp-block-acme-hero{outline:1px solid}',
            'blocks/hero/render.php'        => '<?php echo $content;',
            'components/Hero.jsx'           => 'export default function Hero() { return <section />; }',
            'components/ProductGrid.tsx'    => 'export const ProductGrid = () => <div />;',
        ),
    )
)->toArray();
$assert(1 === count($blocks['block_types']), 'block.json roots are promoted into block type artifacts');
$heroBlock = $blocks['block_types'][0] ?? array();
$assert('chubes4/wordpress-block-type-artifact/v1' === ($heroBlock['schema'] ?? ''), 'block type exposes contract schema');
$assert('acme/hero' === ($heroBlock['name'] ?? ''), 'block type name is preserved');
$assert('hero' === ($heroBlock['slug'] ?? ''), 'block type slug is normalized');
$assert('blocks/hero' === ($heroBlock['directory'] ?? ''), 'block type exposes source directory');
$assert('blocks/hero/block.json' === ($heroBlock['block_json_path'] ?? ''), 'block type exposes block.json path');
$assert(3 === ($heroBlock['metadata']['apiVersion'] ?? null), 'block metadata preserves apiVersion');
$assert(array('align' => true) === ($heroBlock['metadata']['supports'] ?? null), 'block metadata preserves supports');
$assert('blocks/hero/index.js' === ($heroBlock['assets']['editor_script'][0]['path'] ?? ''), 'editor script file reference resolves to generated file');
$assert('wp-interactivity' === ($heroBlock['assets']['view_script'][1]['reference'] ?? ''), 'script handles are preserved as references');
$assert('blocks/hero/render.php' === ($heroBlock['assets']['render'][0]['path'] ?? ''), 'render file reference resolves to generated file');
$assert('blocks/hero/index.asset.php' === ($heroBlock['dependencies']['asset_files'][0]['path'] ?? ''), 'asset php dependency manifests are recorded');
$assert(array('wp-blocks') === ($heroBlock['dependencies']['asset_files'][0]['manifest']['dependencies'] ?? null), 'asset php dependencies are parsed when simple manifests are present');
$assert('1' === ($heroBlock['dependencies']['asset_files'][0]['manifest']['version'] ?? ''), 'asset php versions are parsed when simple manifests are present');
$assert(in_array('blocks/hero/style.css', $heroBlock['provenance']['files'] ?? array(), true), 'block provenance lists source files');
$assert(! empty($heroBlock['provenance']['source_hash'] ?? ''), 'block type exposes provenance hash');
$assert(! empty(array_filter($blocks['components'], static fn (array $component): bool => 'ProductGrid' === ($component['name'] ?? '') && 'jsx-component-file' === ($component['signal'] ?? ''))), 'TSX component declarations produce component candidates');
$assert(! empty(array_filter($blocks['components'], static fn (array $component): bool => 'product-card' === ($component['name'] ?? '') && 'class-token' === ($component['signal'] ?? ''))), 'repeated semantic classes produce component candidates');
$assert(! empty(array_filter($blocks['components'], static fn (array $component): bool => 'product-card' === ($component['name'] ?? '') && 'data-component' === ($component['signal'] ?? ''))), 'data-component markers produce component candidates');

$unnamedBlock = $compiler->compile(
    array(
        'files' => array(
            'index.html' => '<main>Fallback block</main>',
            'blocks/fallback/block.json' => '{"title":"Fallback"}',
        ),
    )
)->toArray();
$assert('generated/fallback' === ($unnamedBlock['block_types'][0]['name'] ?? ''), 'unnamed block.json receives stable generated name');
$assert(in_array('block_json_missing_name', array_column($unnamedBlock['diagnostics'], 'code'), true), 'unnamed block.json emits a diagnostic');

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

$documents = $compiler->compile(
    array(
        'files' => array(
            'content/about.md' => "---\ntitle: About Us\nslug: about\npost_type: page\nexcerpt: Short summary\ndate: 2026-06-19\ntemplate: page-wide\ncategories: [News, Updates]\ntags: launch, artifact\n---\n# About\n\nMarkdown body.",
        ),
    )
)->toArray();
$assert('success_with_warnings' === $documents['status'], 'document-only Markdown compiles with fallback warning', (string) $documents['status']);
$assert(1 === count($documents['documents']), 'Markdown source document is exposed');
$assert('content/about.md' === ($documents['documents'][0]['source_path'] ?? ''), 'document source path is preserved');
$assert('markdown' === ($documents['documents'][0]['body_format'] ?? ''), 'Markdown body format is exposed');
$assert('About Us' === ($documents['documents'][0]['title'] ?? ''), 'frontmatter title is parsed');
$assert('about' === ($documents['documents'][0]['slug'] ?? ''), 'frontmatter slug is parsed');
$assert('page' === ($documents['documents'][0]['post_type'] ?? ''), 'frontmatter post type is parsed');
$assert('Short summary' === ($documents['documents'][0]['excerpt'] ?? ''), 'frontmatter excerpt is parsed');
$assert('2026-06-19' === ($documents['documents'][0]['date'] ?? ''), 'frontmatter date is parsed');
$assert('page-wide' === ($documents['documents'][0]['template'] ?? ''), 'frontmatter template is parsed');
$assert(array( 'News', 'Updates' ) === ($documents['documents'][0]['taxonomies']['categories'] ?? null), 'frontmatter category list is parsed');
$assert('launch, artifact' === ($documents['documents'][0]['taxonomies']['tags'] ?? ''), 'frontmatter taxonomy scalar hints are preserved');
$assert(str_contains((string) ($documents['documents'][0]['block_markup'] ?? ''), '<!-- wp:html -->'), 'Markdown fallback block markup is exposed');
$assert(str_contains((string) $documents['serialized_blocks'], 'Markdown body.'), 'document fallback supplies serialized blocks when HTML is absent');
$assert('markdown_adapter_unavailable' === ($documents['documents'][0]['diagnostics'][0]['code'] ?? ''), 'missing Markdown adapter diagnostic is attached to document');

$mdx = $compiler->compile(
    array(
        'files' => array(
            'docs/page.mdx' => "---\ntitle: MDX Page\n---\nimport Hero from '../components/Hero';\nimport { Card as FeatureCard } from './FeatureCard';\n# MDX\n\n<Hero />\n<FeatureCard />\n<MissingThing />",
            'components/Hero.jsx' => 'export default function Hero() { return <section />; }',
        ),
    )
)->toArray();
$assert('success_with_warnings' === $mdx['status'], 'MDX documents compile with partial-support warnings', (string) $mdx['status']);
$assert('mdx' === ($mdx['documents'][0]['kind'] ?? ''), 'MDX source document is classified');
$assert('mdx' === ($mdx['documents'][0]['body_format'] ?? ''), 'MDX body format is exposed');
$assert(! empty(array_filter($mdx['components'], static fn (array $component): bool => 'Hero' === ($component['name'] ?? '') && 'mdx-jsx' === ($component['signal'] ?? ''))), 'MDX component candidate is exposed');
$assert(! empty(array_filter($mdx['components'], static fn (array $component): bool => 'Hero' === ($component['name'] ?? '') && 'components/Hero.jsx' === ($component['resolved_path'] ?? ''))), 'relative MDX imports resolve to artifact files');
$mdxDiagnosticCodes = array_column($mdx['diagnostics'], 'code');
$assert(in_array('mdx_source_document_detected', $mdxDiagnosticCodes, true), 'MDX detection diagnostic is emitted');
$assert(in_array('mdx_import_unresolved', $mdxDiagnosticCodes, true), 'unresolved relative MDX imports are diagnosed');
$assert(in_array('mdx_component_unresolved', $mdxDiagnosticCodes, true), 'unimported MDX component references are diagnosed');

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
assertContains('html_to_blocks_core_slice', array_column($result['diagnostics'], 'code'), 'expanded core-slice conversion diagnostic should be present.');
assertSame('html', $result['provenance'][0]['source_format'], 'source provenance should identify HTML input.');

$blockFactory = new BlockFactory();
$nestedList = $blockFactory->create(
    'core/list',
    array( 'ordered' => true ),
    array(
        $blockFactory->create('core/list-item', array( 'content' => 'First' )),
        $blockFactory->create('core/list-item', array( 'content' => '<strong>Second</strong>' )),
    )
);
assertSame('core/list', $nestedList['blockName'], 'extracted block factory should preserve block names.');
assertSame('<ol></ol>', $nestedList['innerHTML'], 'extracted block factory should preserve wrapper HTML.');
assertSame(array( '<ol>', null, null, '</ol>' ), $nestedList['innerContent'], 'extracted block factory should preserve nested innerContent placeholders.');
assertSame('<li><strong>Second</strong></li>', $nestedList['innerBlocks'][1]['innerHTML'], 'extracted block factory should preserve child block HTML.');

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
assertSame('core/heading', $bridge->toBlocks('<h2>Hello</h2>', 'html')[0]['blockName'], 'HTML input should convert through the default HTML adapter.');
assertSame('core/heading', $bridge->toBlocks("# Title\n\nBody", 'markdown')[0]['blockName'], 'Markdown input should convert through the default markdown adapter.');
assertSame('<p>Hello</p>', $bridge->convert('<!-- wp:paragraph --><p>Hello</p><!-- /wp:paragraph -->', 'blocks', 'html'), 'Serialized blocks should render to HTML through the default blocks/html adapters.');
assertStringContains('<!-- wp:heading {"content":"Hello","level":2} -->', $bridge->convert('<h2>Hello</h2>', 'html', 'blocks'), 'HTML should serialize to block markup through the block pivot.');
assertStringContains('<h1>Title</h1>', $bridge->convert("# Title\n\nBody", 'markdown', 'html'), 'Markdown should convert to HTML through the block pivot.');
assertStringContains('# Hello', $bridge->convert('<!-- wp:heading {"content":"Hello","level":1} --><h1>Hello</h1><!-- /wp:heading -->', 'blocks', 'markdown'), 'Serialized blocks should convert to markdown through rendered HTML.');
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

function assertStringContains(string $needle, string $haystack, string $message): void
{
    if ( ! str_contains($haystack, $needle) ) {
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
