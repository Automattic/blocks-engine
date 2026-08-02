<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

$result = ( new ArtifactCompiler() )->compile(array(
    'files' => array(
        array( 'path' => 'index.html', 'kind' => 'html', 'content' => '<!doctype html><html><head><style>.red{color:red}</style><link rel="stylesheet" href="a.css"><style>.hero p{color:green}</style><link rel="stylesheet" href="b.css"><link rel="stylesheet" href="a.css"></head><body><a class="cta" href="/go" style="padding:1px;background:#000">Go</a><div class="hero"><p>Copy</p></div></body></html>' ),
        array( 'path' => 'a.css', 'kind' => 'css', 'content_base64' => base64_encode('a.cta:hover{padding:1rem}') ),
        array( 'path' => 'b.css', 'kind' => 'css', 'content' => '[href="/go"]{color:blue}' ),
        array( 'path' => 'a.occurrence-2.css', 'kind' => 'css', 'content' => '.authored-collision{color:purple}' ),
    ),
) )->toArray();

$failures = 0;
$assert = static function (bool $condition, string $message) use (&$failures): void {
    if ( ! $condition ) {
        ++$failures;
        fwrite(STDERR, "FAIL: {$message}\n");
    }
};
$assets = $result['assets'] ?? array();
$assetPaths = array_column($assets, 'path');
$assert(array( 'index.inline-1.css', 'a.css', 'index.inline-2.css', 'b.css', 'a.occurrence-2-generated-1.css', 'a.occurrence-2.css' ) === array_slice($assetPaths, 0, 6)
    && 1 === preg_match('#^assets/css/source-author-[a-f0-9]{16}\.css$#', $assetPaths[6] ?? ''), 'allocated repeated-link alias avoids authored path collisions while preserving source occurrence order');
foreach ( $assets as $asset ) {
    $content = (string) ($asset['content'] ?? '');
    $hash = hash('sha256', $content);
    $assert(strlen($content) === ($asset['bytes'] ?? null) && $hash === ($asset['hash'] ?? null), 'rewritten asset bytes and hashes describe emitted content');
}
$planAssets = $result['source_reports']['materialization_plan']['assets'] ?? array();
foreach ( $planAssets as $asset ) {
    $content = (string) ($asset['content'] ?? '');
    $hash = hash('sha256', $content);
    $assert(strlen($content) === ($asset['bytes'] ?? null) && $hash === ($asset['hash'] ?? null), 'materialization plan payload hashes describe rewritten content');
}
$assert(hash('sha256', base64_encode('a.cta:hover{padding:1rem}')) === ($assets[1]['source_hash'] ?? null) && ($assets[1]['hash'] ?? '') !== ($assets[1]['source_hash'] ?? ''), 'source hash retains linked pre-projection provenance');
$assert('text' === ($assets[1]['content_encoding'] ?? '') && ! isset($assets[1]['content_base64']), 'projected linked CSS invalidates the stale source payload encoding');
$assert(! str_contains((string) ($assets[1]['content'] ?? ''), 'a.cta:hover') && str_contains((string) ($assets[1]['content'] ?? ''), '> :where(.wp-block-button__link):hover'), 'linked button CSS is rewritten in place');
$assert(hash('sha256', '.hero p{color:green}') === ($assets[2]['source_hash'] ?? null) && ! str_contains((string) ($assets[2]['content'] ?? ''), '.hero p') && str_contains((string) ($assets[2]['content'] ?? ''), ':where(.blocks-engine-source-p-'), 'inline CSS is rewritten in place with original source provenance');
$assert(str_contains((string) ($assets[4]['content'] ?? ''), '> :where(.wp-block-button__link):hover') && '.authored-collision{color:purple}' === ($assets[5]['content'] ?? ''), 'allocated occurrence alias is referenced while authored collision CSS remains a deterministic orphan asset');

$richText = ( new ArtifactCompiler() )->compile(array(
    'files' => array(
        array( 'path' => 'index.html', 'kind' => 'html', 'content' => '<!doctype html><html><head><link rel="stylesheet" href="a.css"><link rel="stylesheet" href="b.css"></head><body><p><span class="quote-mark">&quot;</span>Testimonial</p></body></html>' ),
        array( 'path' => 'a.css', 'kind' => 'css', 'content' => '.quote-mark{color:#e8a020}' ),
        array( 'path' => 'b.css', 'kind' => 'css', 'content' => 'p{margin:0}' ),
    ),
) )->toArray();
$richTextAssets = $richText['assets'] ?? array();
$assert(str_starts_with((string) ($richTextAssets[0]['content'] ?? ''), ':where(mark)[style*="--blocks-engine-richtext-marker:"]{background-color:transparent;color:inherit}') && str_contains((string) ($richTextAssets[0]['content'] ?? ''), '{color:#e8a020}') && ! str_contains((string) ($richTextAssets[1]['content'] ?? ''), 'background-color:transparent;color:inherit'), 'artifact projection emits one marker reset before the first projected author stylesheet');

$importedFont = ( new ArtifactCompiler() )->compile(array( 'files' => array(
    array( 'path' => 'index.html', 'kind' => 'html', 'content' => '<!doctype html><html><head><link rel="stylesheet" href="style.css"></head><body><p><span class="accent">Text</span></p></body></html>' ),
    array( 'path' => 'style.css', 'kind' => 'css', 'content' => '@import url("https://fonts.googleapis.com/css2?family=Inter");.accent{color:red}' ),
) ) )->toArray();
$importedFontAssets = array_column($importedFont['assets'] ?? array(), null, 'path');
$importedFontCss = (string) ($importedFontAssets['style.css']['content'] ?? '');
$assert(str_starts_with($importedFontCss, '@import url("https://fonts.googleapis.com/css2?family=Inter");') && strpos($importedFontCss, '@import') < strpos($importedFontCss, ':where(mark)'), 'author stylesheet imports remain before generated marker and geometry rules');

$inlineLayoutLeaves = ( new ArtifactCompiler() )->compile(array( 'files' => array(
    array( 'path' => 'index.html', 'kind' => 'html', 'content' => '<link rel="stylesheet" href="layout.css"><div class="fallback-card"><strong class="artifact-name">index.html</strong><span class="artifact-meta">12 KB</span></div><div class="action-row"><span class="action-label">Deploy</span><span class="action-state">Ready</span></div><p>Ordinary <strong>prose</strong> and <span>inline text</span>.</p>' ),
    array( 'path' => 'layout.css', 'kind' => 'css', 'content' => '.fallback-card{display:grid;grid-template-columns:1fr auto}.fallback-card > strong{display:block;margin:12px 0 4.8px}.fallback-card > .artifact-meta{display:block;grid-column:2}.action-row{display:flex;gap:8px}.action-row > span{display:block;margin:2px 0}.action-state{order:-1}' ),
) ) )->toArray();
$inlineLayoutMarkup = (string) ($inlineLayoutLeaves['serialized_blocks'] ?? '');
$inlineLayoutCss = implode("\n", array_column(array_filter($inlineLayoutLeaves['assets'] ?? array(), static fn (array $asset): bool => 'css' === ($asset['kind'] ?? '')), 'content'));
$inlineLayoutBlocks = $inlineLayoutLeaves['blocks'] ?? array();
$assert(3 === count($inlineLayoutBlocks) && 'core/group' === ($inlineLayoutBlocks[0]['blockName'] ?? '') && 'core/group' === ($inlineLayoutBlocks[1]['blockName'] ?? '') && 'core/paragraph' === ($inlineLayoutBlocks[0]['innerBlocks'][0]['blockName'] ?? '') && 'core/paragraph' === ($inlineLayoutBlocks[1]['innerBlocks'][0]['blockName'] ?? ''), 'external grid and flex styles retain standalone semantic leaves in core Group and Paragraph blocks');
$assert(str_contains($inlineLayoutMarkup, '<p class="blocks-engine-inline-layout-carrier"><strong class="artifact-name">index.html</strong></p>') && str_contains($inlineLayoutMarkup, '<p class="blocks-engine-inline-layout-carrier"><span class="action-label">Deploy</span></p>') && ! str_contains($inlineLayoutMarkup, 'blocks-engine/author-layout') && ! str_contains($inlineLayoutMarkup, 'wp:html'), 'standalone semantic leaves save inside neutral core paragraph carriers without custom or HTML blocks');
$assert(str_contains($inlineLayoutCss, '.fallback-card > p.blocks-engine-inline-layout-carrier > strong{display:block}') && str_contains($inlineLayoutCss, '.fallback-card > p.blocks-engine-inline-layout-carrier > strong{margin:12px 0 4.8px}') && str_contains($inlineLayoutCss, '.action-row > p.blocks-engine-inline-layout-carrier > span{display:block}') && strpos($inlineLayoutCss, 'margin:2px 0') < strpos($inlineLayoutCss, 'order:-1') && str_contains($inlineLayoutCss, ':where(p.blocks-engine-inline-layout-carrier){display:contents;margin:0!important;padding:0!important;border:0!important}'), 'external selector projection addresses source leaves through a neutral direct-child carrier in authored order');
$inlineLayoutValidity = ( new HtmlTransformer() )->transform('<style>.fallback-card{display:grid;grid-template-columns:1fr auto}.fallback-card > strong{display:block;margin:12px 0 4.8px}.fallback-card > .artifact-meta{display:block;grid-column:2}.action-row{display:flex;gap:8px}.action-row > span{display:block;margin:2px 0}.action-state{order:-1}</style><div class="fallback-card"><strong class="artifact-name">index.html</strong><span class="artifact-meta">12 KB</span></div><div class="action-row"><span class="action-label">Deploy</span><span class="action-state">Ready</span></div><p>Ordinary <strong>prose</strong> and <span>inline text</span>.</p>')->toArray();
$assert(5 === substr_count($inlineLayoutMarkup, '<!-- wp:paragraph') && str_contains($inlineLayoutMarkup, '<p>Ordinary <strong>prose</strong> and <span>inline text</span>.</p>') && 'pass' === ($inlineLayoutValidity['source_reports']['wp_block_validity']['status'] ?? ''), 'ordinary prose inline semantics remain one valid RichText paragraph');

$multiPage = ( new ArtifactCompiler() )->compile(array(
    'files' => array(
        array( 'path' => 'index.html', 'kind' => 'html', 'content' => '<link rel="stylesheet" href="site.css"><main><p>Home</p></main>' ),
        array( 'path' => 'about.html', 'kind' => 'html', 'content' => '<link rel="stylesheet" href="site.css"><style>.rows li{gap:1rem}</style><main><ul class="rows"><li><span>About</span><span>Now</span></li></ul></main>' ),
        array( 'path' => 'site.css', 'kind' => 'css', 'content' => 'p{margin:0}.rows li{display:grid}' ),
    ),
) )->toArray();
$multiPageAssets = array_column($multiPage['assets'] ?? array(), null, 'path');
$sharedCss = (string) ($multiPageAssets['site.css']['content'] ?? '');
$aboutCss = (string) ($multiPageAssets['about.inline.css']['content'] ?? '');
$assert(str_contains($sharedCss, 'blocks-engine-source-p-') && str_contains($sharedCss, 'blocks-engine-source-li-'), 'shared stylesheet merges projections required by entry and sibling HTML documents');
$assert(str_contains($aboutCss, 'blocks-engine-source-li-') && str_ends_with($aboutCss, '.rows li{gap:1rem}'), 'sibling inline projection precedes the original stylesheet so retained selectors remain authoritative');
$multiPageCompiledAssetPaths = array_column($multiPage['source_reports']['compiled_site']['assets'] ?? array(), 'path');
$assert(count($multiPageCompiledAssetPaths) === count(array_unique($multiPageCompiledAssetPaths)), 'multi-page compilation deduplicates byte-identical generated assets by source path');
$assert(isset($multiPage['source_reports']['wordpress_site_plan']), 'multi-page generated asset aggregation remains a valid WordPress site plan');

$multiPageRuntime = ( new ArtifactCompiler() )->compile(array(
    'files' => array(
        array( 'path' => 'index.html', 'kind' => 'html', 'content' => '<main><h1>Home</h1></main>' ),
        array( 'path' => 'contact.html', 'kind' => 'html', 'content' => '<main><form action="#" method="post"><input type="email" name="email" required><button type="submit">Send</button></form></main>' ),
        array( 'path' => 'shop.html', 'kind' => 'html', 'content' => '<main><ul class="products"><li><article class="product-card"><h3>Tour Tee</h3><p>Heavy cotton shirt.</p><div class="price">$30</div><div aria-label="Quantity"><button data-dir="down">-</button><span aria-live="polite">1</span><button data-dir="up">+</button></div><button class="add-to-cart">Add to cart</button></article></li><li><article class="product-card"><h3>Signed CD</h3><p>Hand-signed disc.</p><div class="price">$15</div><button class="add-to-cart">Add to cart</button></article></li></ul></main>' ),
    ),
) )->toArray();
$runtimeFallbacks = array_column($multiPageRuntime['fallbacks'] ?? array(), null, 'diagnostic_code');
$runtimeReportFallbacks = array_column($multiPageRuntime['source_reports']['conversion_report']['fallback_diagnostics'] ?? array(), null, 'diagnostic_code');
$assert('contact.html' === ($runtimeFallbacks['html_form_fallback']['source'] ?? ''), 'sibling form finding reaches the artifact result with source-page identity');
$assert('shop.html' === ($runtimeFallbacks['html_product_grid_fallback']['source'] ?? ''), 'sibling product finding reaches the artifact result with source-page identity');
$assert(isset($runtimeReportFallbacks['html_form_fallback'], $runtimeReportFallbacks['html_product_grid_fallback']), 'site conversion report exposes sibling form and product provider targets');

$types = ( new ArtifactCompiler() )->compile(array(
    'files' => array(
        array( 'path' => 'index.html', 'kind' => 'html', 'content' => '<style type="TEXT/CSS; charset=UTF-8">.style-ok{color:red}</style><style type="text/css-not-a-mime">.style-bad{color:red}</style><link rel="stylesheet" href="ok.css" type="text/css; charset=utf-8"><link rel="stylesheet" href="bad.css" type="text/css-not-a-mime"><main><p>Types</p></main>' ),
        array( 'path' => 'ok.css', 'kind' => 'css', 'content' => '.link-ok{color:green}' ),
        array( 'path' => 'bad.css', 'kind' => 'css', 'content' => '.link-bad{color:blue}' ),
    ),
) )->toArray();
$typeAssets = $types['assets'] ?? array();
$typeContents = implode("\n", array_map(static fn (array $asset): string => (string) ($asset['content'] ?? ''), $typeAssets));
$assert(str_contains($typeContents, '.style-ok{color:red}') && str_contains($typeContents, '.link-ok{color:green}') && ! str_contains($typeContents, '.style-bad{color:red}') && ! str_contains($typeContents, '.link-bad{color:blue}'), 'CSS MIME parsing accepts case-insensitive text/css parameters and rejects non-MIME prefixes for style and link occurrences');

$image = ( new ArtifactCompiler() )->compile(array(
    'files' => array(
        array( 'path' => 'index.html', 'kind' => 'html', 'content' => '<link rel="stylesheet" href="image.css"><img class="root-photo" src="photo.jpg" alt="Root photo"><main><img class="photo relative-photo" src="photo.jpg" alt="Photo"></main>' ),
        array( 'path' => 'image.css', 'kind' => 'css', 'content_base64' => base64_encode('img{display:block;max-width:100%}.photo{position:absolute;width:123px;height:106px;object-fit:cover}img.photo{display:block}.relative-photo{width:86.356%;height:auto;aspect-ratio:727.431 / 593.583}body>.root-photo{height:80px}') ),
        array( 'path' => 'photo.jpg', 'kind' => 'image', 'content' => 'image-bytes' ),
    ),
) )->toArray();
$imageCss = (string) (($image['assets'][0]['content'] ?? ''));
$assert(str_contains($imageCss, '.photo{position:absolute;width:123px;height:106px;object-fit:cover}') && str_contains($imageCss, '.relative-photo{width:86.356%;height:auto;aspect-ratio:727.431 / 593.583}'), 'source image geometry remains on the canonical core/image wrapper');
$assert(str_contains($imageCss, '{display:block;width:100%;height:100%;max-width:100%;object-fit:inherit;object-position:inherit;border-radius:inherit}') && ! str_contains($imageCss, '> img{width:123px') && ! str_contains($imageCss, '> img{width:86.356%'), 'canonical nested images fill explicitly owned wrapper geometry instead of applying source dimensions twice');
$assert(str_contains($imageCss, '{display:block;max-width:100%;object-fit:inherit;object-position:inherit;border-radius:inherit}') && ! preg_match('/source-tag-img[^,{]*\.wp-block-image > img\{[^}]*width:100%/', $imageCss), 'generic image presentation selectors do not impose nested image geometry');
$assert(preg_match('/where\(figure\).*\.photo\.wp-block-image > img\{display:block;max-width:100%/', $imageCss) && preg_match('/blocks-engine-root-child-.*\.wp-block-image > img\{display:block;max-width:100%/', $imageCss), 'type and root-child image selectors project the canonical nested-image bridge without inventing dimensions');
$assert('text' === ($image['assets'][0]['content_encoding'] ?? '') && ! isset($image['assets'][0]['content_base64']) && '' !== $imageCss, 'stylesheet projection drops the stale base64 twin and keeps the rewritten text as the sole payload representation');
$assert(1 === preg_match('/<!-- wp:image [\s\S]*<figure[^>]*photo[^>]*><img/', (string) ($image['serialized_blocks'] ?? '')), 'image projection preserves canonical core/image figure markup');

$positionedImage = ( new ArtifactCompiler() )->compile(array(
    'files' => array(
        array( 'path' => 'index.html', 'kind' => 'html', 'content' => '<link rel="stylesheet" href="map.css"><main><img class="map" src="map.png" alt="Map" style="object-fit:fill;object-position:-197.702px -102.702px"></main>' ),
        array( 'path' => 'map.css', 'kind' => 'css', 'content' => '.map{width:872.97px;height:731.531px}' ),
        array( 'path' => 'map.png', 'kind' => 'image', 'content' => 'map-bytes' ),
    ),
) )->toArray();
$positionedImageCss = implode("\n", array_column($positionedImage['assets'] ?? array(), 'content'));
$assert(str_contains((string) ($positionedImage['serialized_blocks'] ?? ''), 'map be-inline-geometry-'), 'unsupported inline image presentation uses a deterministic carrier class');
$assert(str_contains($positionedImageCss, 'object-fit:fill !important;object-position:-197.702px -102.702px !important'), 'image presentation carrier preserves source object fit and position for the nested image bridge');
$assert(str_contains($positionedImageCss, '.map.wp-block-image > img{display:block;width:100%;height:100%;max-width:100%;object-fit:inherit;object-position:inherit;border-radius:inherit}'), 'nested image fills a wrapper with explicitly owned width and height');

$multiPage = ( new ArtifactCompiler() )->compile(array(
    'entrypoint' => 'index.html',
    'files' => array(
        array( 'path' => 'index.html', 'kind' => 'html', 'content' => '<link rel="stylesheet" href="shared.css"><main><p><span class="quote-mark">&quot;</span>Home</p></main>' ),
        array( 'path' => 'about.html', 'kind' => 'html', 'content' => '<link rel="stylesheet" href="shared.css"><main><p><span class="quote-mark">&quot;</span>About</p></main>' ),
        array( 'path' => 'shared.css', 'kind' => 'css', 'content' => '.quote-mark{color:#e8a020}' ),
    ),
) )->toArray();
$multiPageAuthorAssets = array_values(array_filter($multiPage['assets'] ?? array(), static fn (array $asset): bool => 'author-css' === ($asset['source'] ?? '')));
$assert(1 === count($multiPageAuthorAssets), 'identical generated author stylesheets are emitted once across HTML routes');
$assert('blocks-engine/wordpress-site-plan/v2' === ($multiPage['source_reports']['wordpress_site_plan']['schema'] ?? null), 'deduplicated multi-route assets produce a canonical WordPress site plan');

// Collapsed-paragraph cascade isolation via the source-`p` tag marker. A shared
// stylesheet carries a descendant-`p` body-copy rule (`.page-header p`) and an
// eyebrow (`.label`) that collapses to a paragraph. The projected `.page-header
// p` rule must target the source-`p` marker — carried only by elements that were
// `<p>` in the source — so it styles the real intro paragraph but never captures
// the collapsed eyebrow, which keeps its own class-owned type scale. No bare
// `.page-header p` may survive to capture the promoted paragraph.
$collapse = ( new ArtifactCompiler() )->compile(array(
    'entrypoint' => 'index.html',
    'files' => array(
        array( 'path' => 'index.html', 'kind' => 'html', 'content' => '<link rel="stylesheet" href="shared.css"><header class="page-header"><div class="label">Home Eyebrow</div><h1>Home</h1><p>Home intro copy.</p></header>' ),
        array( 'path' => 'about.html', 'kind' => 'html', 'content' => '<link rel="stylesheet" href="shared.css"><header class="page-header"><div class="label">About Eyebrow</div><h1>About</h1><p>About intro copy.</p></header>' ),
        array( 'path' => 'shared.css', 'kind' => 'css', 'content' => '*,*::before,*::after{margin:0;padding:0}.label{display:inline-flex;gap:.5rem;font-size:.68rem;letter-spacing:.2em;text-transform:uppercase}.page-header p{font-size:1.2rem;font-style:italic}' ),
    ),
) )->toArray();
$collapseCss = implode("\n", array_map(static fn (array $asset): string => (string) ($asset['content'] ?? ''), array_values(array_filter($collapse['assets'] ?? array(), static fn (array $asset): bool => 'css' === ($asset['kind'] ?? '') && str_contains((string) ($asset['content'] ?? ''), 'page-header')))));
$assert(str_contains($collapseCss, 'font-size:.68rem'), 'collapsed eyebrow keeps its own class-owned font-size rule');
$assert(! preg_match('/(?:^|[\s>~+])\.page-header p\s*\{/', $collapseCss), 'no bare .page-header p rule survives to capture the collapsed eyebrow');
$assert(preg_match('/\.page-header\s+:where\(\.blocks-engine-source-p-[a-f0-9]+-\d+\)/', $collapseCss) === 1, 'descendant .page-header p is projected through the source-p tag marker so it matches only real source paragraphs');

if ( $failures > 0 ) {
    exit(1);
}
fwrite(STDOUT, "Artifact author stylesheet projection unit tests passed\n");
