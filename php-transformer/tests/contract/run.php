<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\Contract\TransformerResult;
use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\FormatBridge\FormatAdapterInterface;
use Automattic\BlocksEngine\PhpTransformer\FormatBridge\FormatBridge;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;
use Automattic\BlocksEngine\PhpTransformer\StaticSite\MaterializationPlanBuilder;

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

foreach ( array( 'status', 'components', 'block_types', 'source_reports', 'legacy_mapping', 'blocks', 'serialized_blocks', 'documents', 'assets', 'diagnostics', 'fallbacks', 'provenance', 'coverage', 'context', 'metrics' ) as $key ) {
    $assert(array_key_exists($key, $result), "Missing result key: {$key}");
}

$contextual = ( new HtmlTransformer() )->transform(
    '<main><h1>Context</h1><aside>Fallback</aside></main>',
    array(
        'source'          => 'fixture:contextual-html',
        'source_scope'    => 'contract-test',
        'strict'          => true,
        'allow_fallbacks' => false,
    )
)->toArray();
$assert('failed' === $contextual['status'], 'strict HTML transform fails when fallbacks are disallowed', (string) $contextual['status']);
$assert(true === ($contextual['context']['strict'] ?? null), 'HTML transform context exposes strict mode');
$assert(false === ($contextual['context']['allow_fallbacks'] ?? null), 'HTML transform context exposes fallback policy');
$assert('fixture:contextual-html' === ($contextual['provenance'][0]['source'] ?? ''), 'HTML provenance exposes generic source metadata');
$assert('contract-test' === ($contextual['provenance'][0]['scope'] ?? ''), 'HTML provenance exposes generic scope metadata');

$formFallback = ( new HtmlTransformer() )->transform(
    '<main><form action="/contact" method="post"><label for="email">Email</label><input id="email" name="email" type="email" required><select name="topic"><option value="support" selected>Support</option></select><button type="submit">Send</button></form></main>'
)->toArray();
$formDiagnostic = $formFallback['source_reports']['conversion_report']['fallback_diagnostics'][0] ?? array();
$assert(array() === ($formFallback['blocks'] ?? array()), 'form fallback does not synthesize canonical blocks');
$assert('html_form_fallback' === ($formDiagnostic['diagnostic_code'] ?? ''), 'conversion report exposes form fallback diagnostic code');
$assert('/contact' === ($formDiagnostic['form']['action'] ?? ''), 'conversion report exposes form action metadata');
$assert('post' === ($formDiagnostic['form']['method'] ?? ''), 'conversion report exposes normalized form method metadata');
$assert(3 === ($formDiagnostic['control_count'] ?? null), 'conversion report exposes form control count');
$assert('email' === ($formDiagnostic['controls'][0]['name'] ?? ''), 'conversion report exposes form control names');
$assert('Email' === ($formDiagnostic['controls'][0]['label'] ?? ''), 'conversion report exposes form control labels');
$assert(true === ($formDiagnostic['controls'][0]['required'] ?? null), 'conversion report exposes required form controls');
$assert('support' === ($formDiagnostic['controls'][1]['options'][0]['value'] ?? ''), 'conversion report exposes select option values');
$assert(is_int($formDiagnostic['html_bytes'] ?? null), 'conversion report exposes bounded fallback HTML byte size');

$assetMetadataOptions = array(
    'context' => array(
        'asset_metadata' => array(
            'assets/hero.jpg' => array(
                'id'  => 42,
                'url' => 'https://example.test/wp-content/uploads/hero.jpg',
            ),
        ),
    ),
);
$resolvedImage = ( new HtmlTransformer() )->transform('<main><img src="assets/hero.jpg" alt="Hero alt"></main>', $assetMetadataOptions)->toArray();
$resolvedImageAttrs = $resolvedImage['blocks'][0]['attrs'] ?? array();
$assert(42 === ($resolvedImageAttrs['id'] ?? null), 'HTML image transform applies resolved asset id from context metadata');
$assert('https://example.test/wp-content/uploads/hero.jpg' === ($resolvedImageAttrs['url'] ?? ''), 'HTML image transform applies resolved asset URL from context metadata');
$assert('Hero alt' === ($resolvedImageAttrs['alt'] ?? ''), 'HTML image transform preserves original alt text while resolving asset metadata');
$assert(str_contains((string) ($resolvedImage['serialized_blocks'] ?? ''), 'src="https://example.test/wp-content/uploads/hero.jpg"'), 'HTML image transform serializes resolved asset URL');
$assert(str_contains((string) ($resolvedImage['serialized_blocks'] ?? ''), 'class="wp-image-42"'), 'HTML image transform serializes resolved image id class');

$bridgeImageBlocks = ( new FormatBridge() )->toBlocks('<main><img src="assets/hero.jpg" alt="Hero alt"></main>', 'html', $assetMetadataOptions);
$bridgeImageAttrs = $bridgeImageBlocks[0]['attrs'] ?? array();
$assert(42 === ($bridgeImageAttrs['id'] ?? null), 'FormatBridge HTML adapter applies resolved asset id from context metadata');
$assert('https://example.test/wp-content/uploads/hero.jpg' === ($bridgeImageAttrs['url'] ?? ''), 'FormatBridge HTML adapter applies resolved asset URL from context metadata');
$assert('Hero alt' === ($bridgeImageAttrs['alt'] ?? ''), 'FormatBridge HTML adapter preserves original alt text while resolving asset metadata');

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
$assert(is_array($simple['legacy_mapping'] ?? null), 'migration mapping metadata is exposed');
$assert(strlen('<main><article data-component="Hero"><h1>Hello artifact</h1></article></main>') === ($simple['metrics']['input_bytes'] ?? null), 'artifact metrics expose input bytes');
$assert(strlen((string) $simple['serialized_blocks']) === ($simple['metrics']['output_bytes'] ?? null), 'artifact metrics expose output bytes');
$assert(0 === ($simple['metrics']['block_count'] ?? null), 'artifact metrics expose block count');
$assert(0 === ($simple['metrics']['fallback_count'] ?? null), 'artifact metrics expose fallback count');
$assert(0 === ($simple['metrics']['diagnostic_count'] ?? null), 'artifact metrics expose diagnostic count');
$assert(is_float($simple['metrics']['transform_duration_ms'] ?? null), 'artifact metrics expose transform duration');
$assert(MaterializationPlanBuilder::SCHEMA === ($simple['source_reports']['materialization_plan']['schema'] ?? ''), 'artifact exposes canonical materialization plan');
$assert('index.html' === ($simple['source_reports']['materialization_plan']['entry_path'] ?? ''), 'materialization plan exposes entry path');
$assert(1 === ($simple['source_reports']['materialization_plan']['totals']['pages'] ?? null), 'materialization plan counts pages');
$assert('index' === ($simple['source_reports']['materialization_plan']['pages'][0]['slug'] ?? ''), 'materialization plan exposes page slug');

$staticSite = $compiler->compile(
    array(
        'entrypoint' => 'index.html',
        'files'      => array(
            'index.html' => '<main><img src="assets/logo.png" alt="Logo"></main>',
            'parts/header.html' => '<header><nav><a href="/">Home</a><a href="/about.html">About</a></nav><img src="assets/logo.png" alt="Logo"></header>',
            'about.html' => '<main><h1>About</h1></main>',
            'assets/logo.png' => array(
                'content_base64' => base64_encode("\x89PNG\r\n\x1a\n"),
                'mime_type'      => 'image/png',
            ),
            'visual-repair.css' => '.wp-site-blocks{min-height:100vh}',
        ),
    )
)->toArray();
$staticPlan = $staticSite['source_reports']['materialization_plan'] ?? array();
$assert('parts/header.html' === ($staticPlan['template_part_writes'][0]['source_path'] ?? ''), 'materialization plan exposes template part writes');
$assert('wp_template_part' === ($staticPlan['template_part_writes'][0]['type'] ?? ''), 'template part writes identify the WordPress write target');
$assert(str_contains((string) ($staticPlan['visual_repair_css'] ?? ''), 'min-height:100vh'), 'materialization plan exposes visual repair CSS');
$assert(! empty(array_filter($staticPlan['asset_rewrite_candidates'] ?? array(), static fn (array $candidate): bool => 'template_part' === ($candidate['scope'] ?? '') && 'assets/logo.png' === ($candidate['asset_path'] ?? ''))), 'materialization plan exposes template part asset rewrite candidates');
$assert('/' === ($staticPlan['routes'][0]['target_path'] ?? ''), 'materialization plan exposes entry route path');
$assert('/about' === ($staticPlan['routes'][1]['target_path'] ?? ''), 'materialization plan exposes document route path');
$assert('navigation_link' === ($staticPlan['navigation_links'][0]['kind'] ?? ''), 'materialization plan exposes generic navigation link rows');
$assert('About' === ($staticPlan['navigation_links'][1]['label'] ?? ''), 'materialization plan exposes navigation link labels');
$assert('/about' === ($staticPlan['navigation_links'][1]['target_path'] ?? ''), 'materialization plan exposes navigation target paths');
$assert('menu' === ($staticPlan['menus'][0]['kind'] ?? ''), 'materialization plan exposes generic menu rows');
$assert(2 === ($staticPlan['menus'][0]['items'] ?? null), 'materialization plan counts menu items');

$productsPlan = ( new MaterializationPlanBuilder() )->fromCompiledSite(
    array(
        'products' => array(
            array('sku' => 'shirt-001', 'name' => 'Shirt'),
        ),
    )
);
$assert('shirt-001' === ($productsPlan['products'][0]['sku'] ?? ''), 'materialization plan preserves compiled site products manifest when present');

$fragment = $compiler->compileFragment('<main><h2>Fragment</h2><p>Copy</p></main>', 'fixture:fragment')->toArray();
$assert('success' === $fragment['status'], 'fragment compiles successfully', (string) $fragment['status']);
$assert('fixture:fragment' === ($fragment['provenance'][0]['source'] ?? ''), 'fragment compile exposes source provenance');
$assert('artifact-fragment' === ($fragment['provenance'][0]['scope'] ?? ''), 'fragment compile exposes source scope');
$assert(str_contains((string) $fragment['serialized_blocks'], '<!-- wp:heading'), 'fragment compile serializes heading block');

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
$assert('success' === $documents['status'], 'document-only Markdown compiles through canonical Markdown adapter', (string) $documents['status']);
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
$assert(str_contains((string) ($documents['documents'][0]['block_markup'] ?? ''), '<!-- wp:heading'), 'Markdown heading block markup is exposed');
$assert(str_contains((string) $documents['serialized_blocks'], 'Markdown body.'), 'document fallback supplies serialized blocks when HTML is absent');
$assert(array() === ($documents['documents'][0]['diagnostics'] ?? null), 'Markdown document conversion does not depend on ambient wrapper diagnostics');

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
assertSame('unsupported_element', $result['fallbacks'][0]['reason'], 'fallbacks should expose a stable generic reason.');
assertSame('html_unsupported_element', $result['fallbacks'][0]['diagnostic_code'], 'fallbacks should expose a diagnostic code for cross-process consumers.');
assertSame('html', $result['fallbacks'][0]['source_format'], 'fallbacks should expose the source format.');
assertSame('aside', $result['fallbacks'][0]['tag'], 'fallback should identify the unsupported tag.');
assertContains('html_to_blocks_core_slice', array_column($result['diagnostics'], 'code'), 'expanded core-slice conversion diagnostic should be present.');
assertSame('html', $result['provenance'][0]['source_format'], 'source provenance should identify HTML input.');
assertSame(strlen($fixture . "\n<ul><li>One</li><li><strong>Two</strong></li></ul><aside>Fallback</aside>"), $result['metrics']['input_bytes'], 'HTML metrics should expose input bytes.');
assertSame(strlen($result['serialized_blocks']), $result['metrics']['output_bytes'], 'HTML metrics should expose output bytes.');
assertSame(6, $result['metrics']['block_count'], 'HTML metrics should count nested blocks.');
assertSame(1, $result['metrics']['fallback_count'], 'HTML metrics should expose fallback count.');
assertSame(count($result['diagnostics']), $result['metrics']['diagnostic_count'], 'HTML metrics should expose diagnostic count.');
$assert(is_float($result['metrics']['transform_duration_ms'] ?? null), 'HTML metrics expose transform duration');

if ( ! str_contains($result['serialized_blocks'], '<!-- wp:heading {"content":"Hello blocks","level":1} -->') ) {
    fwrite(STDERR, "Serialized blocks did not include the expected heading block.\n");
    exit(1);
}

fwrite(STDOUT, "HTML-to-blocks contract passed.\n");

$bridge = new FormatBridge();

assertSame(array( 'blocks', 'html', 'markdown' ), $bridge->supportedFormats(), 'Default supported formats should be stable for adapter authors.');
assertSame(true, $bridge->supports('html'), 'Format bridge should expose adapter support checks.');
assertSame(false, $bridge->supports('xml'), 'Format bridge support checks should require a registered adapter.');
$markdownNormalizeResult = $bridge->convertResult("# Title\r\n\r\nBody\r\n", 'markdown', 'markdown')->toArray();
assertSame("# Title\n\nBody\n", $markdownNormalizeResult['documents'][0]['content'], 'Markdown line endings should normalize to LF through the result envelope.');
$htmlNormalizeResult = $bridge->convertResult('<main><h1>Hello</h1></main>', 'html', 'html')->toArray();
assertSame('<main><h1>Hello</h1></main>', $htmlNormalizeResult['documents'][0]['content'], 'HTML normalization should preserve valid HTML through the result envelope.');
$blocksNormalizeResult = $bridge->convertResult('<!-- wp:paragraph --><p>Hello</p><!-- /wp:paragraph -->', 'blocks', 'blocks')->toArray();
assertSame('<!-- wp:paragraph --><p>Hello</p><!-- /wp:paragraph -->', $blocksNormalizeResult['documents'][0]['content'], 'Serialized blocks should pass validation through the result envelope.');
$markdownToBlocksResult = $bridge->convertResult("# Title\n\nBody", 'markdown', 'blocks')->toArray();
assertSame('core/heading', $markdownToBlocksResult['blocks'][0]['blockName'], 'Markdown input should convert through the default markdown adapter.');
$blocksToHtmlResult = $bridge->convertResult('<!-- wp:paragraph --><p>Hello</p><!-- /wp:paragraph -->', 'blocks', 'html')->toArray();
assertSame('<p>Hello</p>', $blocksToHtmlResult['documents'][0]['content'], 'Serialized blocks should render to HTML through the default blocks/html adapters.');
$markdownToHtmlResult = $bridge->convertResult("# Title\n\nBody", 'markdown', 'html')->toArray();
assertStringContains('<h1>Title</h1>', $markdownToHtmlResult['documents'][0]['content'], 'Markdown should convert to HTML through the block pivot.');
$blocksToMarkdownResult = $bridge->convertResult('<!-- wp:heading {"content":"Hello","level":1} --><h1>Hello</h1><!-- /wp:heading -->', 'blocks', 'markdown')->toArray();
assertStringContains('# Hello', $blocksToMarkdownResult['documents'][0]['content'], 'Serialized blocks should convert to markdown through rendered HTML.');
$htmlToBlocksResult = $bridge->convertResult('<h2>Hello</h2>', 'html', 'blocks')->toArray();
assertSame('success', $htmlToBlocksResult['status'], 'Format bridge result conversion should succeed for public default adapters.');
assertSame('blocks-engine/php-transformer/result/v1', $htmlToBlocksResult['schema'], 'Format bridge result conversion should use the shared result envelope.');
assertSame('core/heading', $htmlToBlocksResult['blocks'][0]['blockName'], 'Format bridge result conversion should expose block arrays.');
assertStringContains('<!-- wp:heading {"content":"Hello","level":2} -->', $htmlToBlocksResult['serialized_blocks'], 'Format bridge result conversion should expose serialized blocks for block targets.');
assertSame('blocks', $htmlToBlocksResult['documents'][0]['format'], 'Format bridge result conversion should expose target document format.');
$unsupportedSourceResult = $bridge->convertResult('<p>Hello</p>', 'xml', 'html')->toArray();
assertSame('failed', $unsupportedSourceResult['status'], 'Unsupported source formats should fail through diagnostics.');
assertSame('unsupported_source_format', $unsupportedSourceResult['diagnostics'][0]['code'], 'Unsupported source diagnostics should identify the source format.');
$unsupportedTargetResult = $bridge->convertResult('<p>Hello</p>', 'html', 'xml')->toArray();
assertSame('failed', $unsupportedTargetResult['status'], 'Unsupported target formats should fail through diagnostics.');
assertSame('unsupported_target_format', $unsupportedTargetResult['diagnostics'][0]['code'], 'Unsupported target diagnostics should identify the target format.');
assertThrows(static fn () => $bridge->normalize('<!-- wp:paragraph /-->', 'markdown'), 'Declared markdown content contains serialized block comments.');
assertThrows(static fn () => $bridge->normalize("# Title\n<p>Hello</p>", 'html'), 'Declared HTML content contains markdown markers.');
assertThrows(static fn () => $bridge->normalize('<p>Hello</p>', 'blocks'), 'Declared blocks content does not contain serialized block comments.');
assertThrows(static fn () => $bridge->normalize('<!-- wp:paragraph --><p>Hello</p>', 'blocks'), 'Serialized block markup contains an unclosed block comment.');
assertThrows(static fn () => $bridge->normalize('<!-- wp:paragraph --><p>Hello</p><!-- /wp:heading -->', 'blocks'), 'Mismatched serialized block closing comment.');
assertThrows(static fn () => $bridge->convert('<p>Hello</p>', 'html', 'xml'), 'No format adapter is registered for format "xml".');

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
$plainResult = $bridge->convertResult('<p>Hello</p>', 'html', 'plain')->toArray();
assertSame('plain output', $plainResult['documents'][0]['content'], 'Conversion stubs should hand block pivot to registered target adapters.');

$optionCalls = array();
$bridge->registerAdapter(new class($optionCalls) implements FormatAdapterInterface {
    /**
     * @param array<int, array<string, mixed>> $calls
     */
    public function __construct(private array &$calls)
    {
    }

    public function slug(): string
    {
        return 'optioned';
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function toBlocks(string $content, array $options = array()): array
    {
        $this->calls[] = array('method' => 'toBlocks', 'options' => $options);

        return array(
            'sparse-key' => array(
                'blockName'    => 'core/paragraph',
                'attrs'        => array('content' => $options['marker'] ?? ''),
                'innerBlocks'  => array(),
                'innerHTML'    => '<p>' . $content . '</p>',
                'innerContent' => array('<p>' . $content . '</p>'),
            ),
        );
    }

    /**
     * @param array<int|string, array<string, mixed>> $blocks
     */
    public function fromBlocks(array $blocks, array $options = array()): string
    {
        $this->calls[] = array('method' => 'fromBlocks', 'options' => $options, 'block_keys' => array_keys($blocks));

        return (string) ($options['marker'] ?? '');
    }

    public function detect(string $content): bool
    {
        return '' !== trim($content);
    }
});

$optionedBlocks = $bridge->toBlocks('Optioned', 'optioned', array('marker' => 'forwarded'));
assertSame(array(0), array_keys($optionedBlocks), 'FormatBridge::toBlocks should return list-shaped block arrays.');
$optionedResult = $bridge->convertResult('Optioned', 'optioned', 'plain', array('marker' => 'forwarded'))->toArray();
assertSame('forwarded', $optionedResult['blocks'][0]['attrs']['content'], 'convertResult should forward options to source adapters.');
assertSame(2, count($optionCalls), 'convertResult should not call source adapters more than once after explicit toBlocks use.');
assertSame('toBlocks', $optionCalls[1]['method'], 'convertResult should use the source adapter directly for the block pivot.');
assertSame(array('marker' => 'forwarded'), $optionCalls[1]['options'], 'convertResult should preserve option arrays.');

$contextualBridgeResult = $bridge->convertResult(
    '<h2>Context</h2>',
    'html',
    'blocks',
    array(
        'context' => array(
            'strict'          => true,
            'allow_fallbacks' => false,
        ),
        'provenance' => array(
            'source' => 'fixture:format-bridge',
            'scope'  => 'contract-test',
        ),
    )
)->toArray();
assertSame(array('strict' => true, 'allow_fallbacks' => false), $contextualBridgeResult['context'], 'convertResult should expose normalized context flags.');
assertSame('fixture:format-bridge', $contextualBridgeResult['provenance'][0]['source'], 'convertResult should expose generic provenance source metadata.');
assertSame('contract-test', $contextualBridgeResult['provenance'][0]['scope'], 'convertResult should expose generic provenance scope metadata.');

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
