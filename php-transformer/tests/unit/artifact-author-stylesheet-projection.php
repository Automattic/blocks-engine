<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;
use Automattic\BlocksEngine\PhpTransformer\WordPress\Runtime;
use Automattic\BlocksEngine\PhpTransformer\VisualParity\StaticStyleParityProbe;
use Automattic\BlocksEngine\PhpTransformer\VisualParity\StaticStyleParityRunner;

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
    && 1 === preg_match('#^assets/css/engine-support-after-author-[a-f0-9]{16}\.css$#', $assetPaths[6] ?? ''), 'allocated repeated-link alias avoids authored path collisions while preserving source occurrence and support placement order');
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
$assert('engine-support' === ($richTextAssets[0]['source'] ?? '') && 'before-author' === ($richTextAssets[0]['stylesheet_placement'] ?? '') && str_starts_with((string) ($richTextAssets[0]['content'] ?? ''), ':where(mark)[style*="--blocks-engine-richtext-marker:"]{background-color:transparent;color:inherit}') && str_contains((string) ($richTextAssets[1]['content'] ?? ''), '{color:#e8a020}') && ! str_contains((string) ($richTextAssets[1]['content'] ?? ''), 'background-color:transparent;color:inherit'), 'artifact projection emits one marker-reset support asset before the first projected author stylesheet');

$importedFont = ( new ArtifactCompiler() )->compile(array( 'files' => array(
    array( 'path' => 'index.html', 'kind' => 'html', 'content' => '<!doctype html><html><head><link rel="stylesheet" href="style.css"></head><body><p><span class="accent">Text</span></p></body></html>' ),
    array( 'path' => 'style.css', 'kind' => 'css', 'content' => '@import url("https://fonts.googleapis.com/css2?family=Inter");.accent{color:red}' ),
) ) )->toArray();
$importedFontAssets = array_column($importedFont['assets'] ?? array(), null, 'path');
$importedFontCss = (string) ($importedFontAssets['style.css']['content'] ?? '');
$assert(str_starts_with($importedFontCss, '@import url("https://fonts.googleapis.com/css2?family=Inter");') && ! str_contains($importedFontCss, ':where(mark)') && 'before-author' === ($importedFont['assets'][0]['stylesheet_placement'] ?? '') && 'style.css' === ($importedFont['assets'][1]['path'] ?? ''), 'author stylesheet imports retain their leading preamble while marker support loads from a preceding asset');

$inlineLayoutLeaves = ( new ArtifactCompiler() )->compile(array( 'files' => array(
    array( 'path' => 'index.html', 'kind' => 'html', 'content' => '<link rel="stylesheet" href="layout.css"><div class="artifact-card"><span class="card-label">Input</span><strong>index.html</strong><span class="card-label">styles.css</span><span class="card-label">assets/</span></div><p>Ordinary <strong>prose</strong> and <span>inline text</span>.</p>' ),
    array( 'path' => 'layout.css', 'kind' => 'css', 'content' => '.artifact-card{display:grid;grid-template-columns:1fr auto}.artifact-card > strong{display:block;margin:12px 0 4.8px}.artifact-card .card-label{display:block;grid-column:1 / -1;color:#6040cc;margin:2px 0}' ),
) ) )->toArray();
$inlineLayoutMarkup = (string) ($inlineLayoutLeaves['serialized_blocks'] ?? '');
$inlineLayoutCss = implode("\n", array_column(array_filter($inlineLayoutLeaves['assets'] ?? array(), static fn (array $asset): bool => 'css' === ($asset['kind'] ?? '')), 'content'));
$inlineLayoutBlocks = $inlineLayoutLeaves['blocks'] ?? array();
$assert(2 === count($inlineLayoutBlocks) && 'core/group' === ($inlineLayoutBlocks[0]['blockName'] ?? '') && 4 === count($inlineLayoutBlocks[0]['innerBlocks'] ?? array()) && ! array_filter($inlineLayoutBlocks[0]['innerBlocks'] ?? array(), static fn (array $block): bool => 'core/paragraph' !== ($block['blockName'] ?? '')), 'external card layout retains standalone semantic leaves in core Group and Paragraph blocks');
$assert(str_contains($inlineLayoutMarkup, 'artifact-card blocks-engine-css-owned-layout blocks-engine-css-owned-flow') && str_contains($inlineLayoutMarkup, '<p class="blocks-engine-inline-layout-carrier"><strong>index.html</strong></p>') && 3 === substr_count($inlineLayoutMarkup, '<span class="card-label">') && ! str_contains($inlineLayoutMarkup, 'blocks-engine/author-layout') && ! str_contains($inlineLayoutMarkup, 'wp:html'), 'card saves CSS-owned parent and real source leaves through neutral core paragraph carriers without custom or HTML blocks');
$assert(str_contains($inlineLayoutCss, '.artifact-card > p.blocks-engine-inline-layout-carrier > strong{display:block}') && str_contains($inlineLayoutCss, '.artifact-card > p.blocks-engine-inline-layout-carrier > strong{margin:12px 0 4.8px}') && str_contains($inlineLayoutCss, '.artifact-card p.blocks-engine-inline-layout-carrier > .card-label{display:block;grid-column:1 / -1;color:#6040cc}') && str_contains($inlineLayoutCss, '.artifact-card p.blocks-engine-inline-layout-carrier > .card-label{margin:2px 0}') && str_contains($inlineLayoutCss, ':where(p.blocks-engine-inline-layout-carrier){display:contents;margin:0!important;padding:0!important;border:0!important}') && str_contains($inlineLayoutCss, ':root :where(.blocks-engine-css-owned-flow>p){margin-top:0;margin-bottom:0}'), 'carrier projection preserves direct and descendant card selectors, label styles, and flow-neutral authored order');
$inlineLayoutValidity = ( new HtmlTransformer() )->transform('<style>.artifact-card{display:grid;grid-template-columns:1fr auto}.artifact-card > strong{display:block;margin:12px 0 4.8px}.artifact-card .card-label{display:block;grid-column:1 / -1;color:#6040cc;margin:2px 0}</style><div class="artifact-card"><span class="card-label">Input</span><strong>index.html</strong><span class="card-label">styles.css</span><span class="card-label">assets/</span></div><p>Ordinary <strong>prose</strong> and <span>inline text</span>.</p>')->toArray();
$assert(5 === substr_count($inlineLayoutMarkup, '<!-- wp:paragraph') && str_contains($inlineLayoutMarkup, '<p>Ordinary <strong>prose</strong> and <span>inline text</span>.</p>') && 'pass' === ($inlineLayoutValidity['source_reports']['wp_block_validity']['status'] ?? ''), 'ordinary prose inline semantics remain one valid RichText paragraph');

$standaloneFallback = ( new ArtifactCompiler() )->compile(array( 'files' => array(
    array( 'path' => 'index.html', 'kind' => 'html', 'content' => '<link rel="stylesheet" href="layout.css"><div class="card-grid"><div class="fallback-card"><strong>index.html</strong></div></div><p>Ordinary <strong>prose</strong> and <span>inline text</span>.</p>' ),
    array( 'path' => 'layout.css', 'kind' => 'css', 'content' => '.card-grid{display:grid}.fallback-card > strong{display:block;margin:12px 0 4.8px}' ),
) ) )->toArray();
$standaloneFallbackMarkup = (string) ($standaloneFallback['serialized_blocks'] ?? '');
$standaloneFallbackCss = implode("\n", array_column(array_filter($standaloneFallback['assets'] ?? array(), static fn (array $asset): bool => 'css' === ($asset['kind'] ?? '')), 'content'));
$standaloneFallbackValidity = ( new HtmlTransformer() )->transform('<style>.card-grid{display:grid}.fallback-card > strong{display:block;margin:12px 0 4.8px}</style><div class="card-grid"><div class="fallback-card"><strong>index.html</strong></div></div><p>Ordinary <strong>prose</strong> and <span>inline text</span>.</p>')->toArray();
$assert(str_contains($standaloneFallbackMarkup, '<div class="wp-block-group fallback-card blocks-engine-css-owned-layout"><!-- wp:paragraph {"className":"blocks-engine-inline-layout-carrier"') && str_contains($standaloneFallbackMarkup, '<p class="blocks-engine-inline-layout-carrier"><strong>index.html</strong></p>') && ! str_contains($standaloneFallbackMarkup, '<p class="fallback-card"') && ! str_contains($standaloneFallbackMarkup, 'wp:html'), 'ordinary fallback cards retain CSS-owned group behavior and their direct standalone strong in a core paragraph carrier when only a grandparent establishes grid');
$assert(str_contains($standaloneFallbackCss, '.fallback-card > p.blocks-engine-inline-layout-carrier > strong{display:block}') && str_contains($standaloneFallbackCss, '.fallback-card > p.blocks-engine-inline-layout-carrier > strong{margin:12px 0 4.8px}') && str_contains($standaloneFallbackCss, ':where(p.blocks-engine-inline-layout-carrier){display:contents;margin:0!important;padding:0!important;border:0!important}') && str_contains($standaloneFallbackMarkup, '<p>Ordinary <strong>prose</strong> and <span>inline text</span>.</p>') && 'pass' === ($standaloneFallbackValidity['source_reports']['wp_block_validity']['status'] ?? ''), 'standalone fallback carrier preserves authored block margins while ordinary prose remains valid native RichText');

$standaloneAnchorCarriers = ( new ArtifactCompiler() )->compile(array( 'files' => array(
    array( 'path' => 'index.html', 'kind' => 'html', 'content' => '<link rel="stylesheet" href="layout.css"><a class="flex-link" href="/flex">Flex link</a><a class="grid-link" href="/grid">Grid link</a><a class="standalone-link" href="/standalone">Standalone link</a><a class="standalone-link explicit-none" href="/none">Explicit none</a><div class="suppressed"><a class="standalone-link" href="/context">Context none</a></div><footer><a class="standalone-link" href="/top">Footer underline</a><a class="standalone-link" href="/footer">Footer none</a></footer><p>Normal <a href="/prose">prose link</a>.</p>' ),
    array( 'path' => 'layout.css', 'kind' => 'css', 'content' => '.flex-link{display:flex}.grid-link{display:grid}.standalone-link{display:block}.explicit-none{text-decoration:none}.suppressed>a:not(.button){text-decoration:none}footer>a:last-child{text-decoration-line:none}' ),
) ) )->toArray();
$standaloneAnchorMarkup = (string) ($standaloneAnchorCarriers['serialized_blocks'] ?? '');
$standaloneAnchorCss = implode("\n", array_column(array_filter($standaloneAnchorCarriers['assets'] ?? array(), static fn (array $asset): bool => 'css' === ($asset['kind'] ?? '')), 'content'));
$standaloneAnchorCandidate = StaticStyleParityRunner::candidateHtmlFromSerializedBlocks($standaloneAnchorMarkup);
$standaloneAnchorProbes = ( new StaticStyleParityProbe() )->extract($standaloneAnchorCandidate, $standaloneAnchorCss)['probes'] ?? array();
$standaloneAnchorDecorations = array();
foreach ( $standaloneAnchorProbes as $probe ) {
    if ( 'a' === ($probe['tag'] ?? '') ) {
        $standaloneAnchorDecorations[(string) ($probe['text'] ?? '')] = (string) ($probe['style']['text-decoration'] ?? '');
    }
}
$standaloneAnchorValidity = ( new HtmlTransformer() )->transform('<style>.flex-link{display:flex}.grid-link{display:grid}.standalone-link{display:block}.explicit-none{text-decoration:none}.suppressed>a:not(.button){text-decoration:none}footer>a:last-child{text-decoration-line:none}</style><a class="flex-link" href="/flex">Flex link</a><a class="grid-link" href="/grid">Grid link</a><a class="standalone-link" href="/standalone">Standalone link</a><a class="standalone-link explicit-none" href="/none">Explicit none</a><div class="suppressed"><a class="standalone-link" href="/context">Context none</a></div><footer><a class="standalone-link" href="/top">Footer underline</a><a class="standalone-link" href="/footer">Footer none</a></footer><p>Normal <a href="/prose">prose link</a>.</p>')->toArray();
$assert(7 === substr_count($standaloneAnchorMarkup, '<p class=') && str_contains($standaloneAnchorMarkup, '<p>Normal <a href="/prose">prose link</a>.</p>') && ! str_contains($standaloneAnchorMarkup, 'wp:html'), 'direct flex, grid, and standalone anchors retain native editable links in synthetic paragraph carriers without changing normal prose links or using HTML fallback');
$assert(str_contains($standaloneAnchorCss, ':where(p.blocks-engine-synthetic-paragraph)>a{text-decoration:underline}') && str_contains($standaloneAnchorCss, ':where(p.blocks-engine-synthetic-paragraph.blocks-engine-synthetic-anchor-undecorated)>a{text-decoration:none}') && 6 === substr_count($standaloneAnchorMarkup, 'blocks-engine-synthetic-anchor-undecorated') && 'none' === ($standaloneAnchorDecorations['Explicit none'] ?? '') && 'pass' === ($standaloneAnchorValidity['source_reports']['wp_block_validity']['status'] ?? ''), 'source-resolved explicit, direct-child :not(), and :last-child text-decoration suppressions survive synthetic carriers while default underline anchors remain covered by the baseline rule');

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

$embeddedStyles = ( new ArtifactCompiler() )->compile(array(
    'entrypoint' => 'index.html',
    'files' => array(
        array( 'path' => 'index.html', 'kind' => 'html', 'content' => '<!doctype html><html><head><style>.shared{color:red}</style><link rel="stylesheet" href="site.css"><style media="(min-width: 48rem)">.wide{color:blue}</style></head><body><style>.hero{background:url("images/banner.svg")}</style><main class="shared wide hero"><p>Home</p></main><fieldset><legend>Unsupported</legend></fieldset></body></html>' ),
        array( 'path' => 'about.html', 'kind' => 'html', 'content' => '<style>.shared{color:red}</style><main class="shared"><p>About</p></main>' ),
        array( 'path' => 'site.css', 'kind' => 'css', 'content' => '.linked{display:block}' ),
        array( 'path' => 'images/banner.svg', 'kind' => 'asset', 'mime_type' => 'image/svg+xml', 'content' => '<svg xmlns="http://www.w3.org/2000/svg"/>' ),
    ),
) )->toArray();
$embeddedStyleAssets = $embeddedStyles['assets'] ?? array();
$embeddedStylePaths = array_column($embeddedStyleAssets, 'path');
$embeddedStyleContents = implode("\n", array_column($embeddedStyleAssets, 'content'));
$embeddedStyleFallbacks = $embeddedStyles['fallbacks'] ?? array();
$styleFallbacks = array_filter($embeddedStyleFallbacks, static fn (array $fallback): bool => 'html_unsupported_element' === ($fallback['diagnostic_code'] ?? '') && 'style' === ($fallback['tag'] ?? ''));
$fieldsetFallbacks = array_filter($embeddedStyleFallbacks, static fn (array $fallback): bool => 'html_unsupported_element' === ($fallback['diagnostic_code'] ?? '') && 'fieldset' === ($fallback['tag'] ?? ''));
$assert(array( 'index.inline-1.css', 'site.css', 'index.inline-2.css', 'index.inline-3.css', 'about.inline.css' ) === array_slice($embeddedStylePaths, 0, 5), 'head and body styles preserve source-order stylesheet occurrences across pages');
$assert('(min-width: 48rem)' === (($embeddedStyleAssets[2]['media'] ?? '')), 'embedded media styles retain their stylesheet media scope');
$assert(str_contains($embeddedStyleContents, '.hero{background:url("images/banner.svg")}'), 'embedded CSS URL references retain canonical artifact-relative resolution');
$assert(array() === $styleFallbacks && 1 === count($fieldsetFallbacks), 'materialized style elements avoid unsupported-element fallbacks while genuine unsupported body elements remain reported');

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
$multiPageSupportAssets = array_values(array_filter($multiPage['assets'] ?? array(), static fn (array $asset): bool => 'engine-support' === ($asset['source'] ?? '')));
$assert(1 === count($multiPageSupportAssets), 'identical generated engine support stylesheets are emitted once across HTML routes');
$multiPageAssetPaths = array_column($multiPage['assets'] ?? array(), 'path');
$multiPageWordPressAssets = $multiPage['source_reports']['wordpress_site_plan']['assets'] ?? array();
$assert(1 === preg_match('#^assets/css/engine-support-before-author-[a-f0-9]{16}\.css$#', $multiPageAssetPaths[0] ?? '') && 'shared.css' === ($multiPageAssetPaths[1] ?? '') && 'shared' === ($multiPageSupportAssets[0]['compilation']['scope'] ?? '') && 'global' === ($multiPageWordPressAssets[0]['scopes'][0]['kind'] ?? ''), 'identical multi-page support is ordered before author CSS and promoted to global scope');
$assert('blocks-engine/wordpress-site-plan/v2' === ($multiPage['source_reports']['wordpress_site_plan']['schema'] ?? null), 'deduplicated multi-route assets produce a canonical WordPress site plan');

$siblingSupport = ( new ArtifactCompiler() )->compile(array(
    'entrypoint' => 'index.html',
    'files' => array(
        array( 'path' => 'index.html', 'kind' => 'html', 'content' => '<link rel="stylesheet" href="index.css"><main><p>Home</p></main>' ),
        array( 'path' => 'about.html', 'kind' => 'html', 'content' => '<link rel="stylesheet" href="about.css"><main><div class="grid"><div>A</div><div>B</div></div></main>' ),
        array( 'path' => 'index.css', 'kind' => 'css', 'content' => 'p{margin:0}' ),
        array( 'path' => 'about.css', 'kind' => 'css', 'content' => '.grid{display:grid;grid-template-columns:1fr 1fr}' ),
    ),
) )->toArray();
$siblingSupportAssets = $siblingSupport['assets'] ?? array();
$siblingWordPressAssets = $siblingSupport['source_reports']['wordpress_site_plan']['assets'] ?? array();
$assert(1 === preg_match('#^assets/css/engine-support-before-author-[a-f0-9]{16}\.css$#', (string) ($siblingSupportAssets[0]['path'] ?? '')) && 'before-author' === ($siblingSupportAssets[0]['stylesheet_placement'] ?? '') && 'index.css' === ($siblingSupportAssets[1]['path'] ?? '') && 'about.css' === ($siblingSupportAssets[2]['path'] ?? '') && 'about.html' === ($siblingSupportAssets[0]['compilation']['id'] ?? '') && 'about.html' === ($siblingWordPressAssets[0]['scopes'][0]['source_path'] ?? ''), 'non-entry page support stays page-scoped and precedes every author stylesheet');

$externalLayouts = ( new ArtifactCompiler() )->compile(array(
    'entrypoint' => 'index.html',
    'files' => array(
        array( 'path' => 'index.html', 'kind' => 'html', 'content' => '<link rel="stylesheet" href="styles.css"><main><div class="hero-visual"><div class="artifact-card"><span class="card-label">Input</span><strong>index.html</strong><span>styles.css</span><span>assets/</span></div></div></main>' ),
        array( 'path' => 'styles.css', 'kind' => 'css', 'content' => '.hero-visual{display:grid;gap:2rem}.artifact-card{display:grid;grid-template-columns:1fr auto}.artifact-card > span:not(.card-label){grid-column:2}.artifact-card > strong{grid-column:1}.artifact-card .card-label{grid-column:1 / -1}' ),
    ),
) )->toArray();
$externalLayoutPage = (string) ($externalLayouts['source_reports']['wordpress_site_plan']['pages'][0]['canonical_block_markup'] ?? '');
$externalLayoutCard = $externalLayouts['blocks'][0]['innerBlocks'][0] ?? array();
$externalLayoutCardChildren = $externalLayoutCard['innerBlocks'] ?? array();
$externalLayoutCss = implode("\n", array_column($externalLayouts['assets'] ?? array(), 'content'));
$assert(
    str_contains($externalLayoutPage, 'hero-visual blocks-engine-css-owned-layout blocks-engine-css-owned-grid')
    && str_contains($externalLayoutPage, 'artifact-card blocks-engine-css-owned-layout')
    && ! str_contains($externalLayoutPage, 'is-layout-grid')
    && 4 === count($externalLayoutCard['innerBlocks'] ?? array())
    && 'core/paragraph' === ($externalLayoutCardChildren[0]['blockName'] ?? '')
    && 'core/group' === ($externalLayoutCardChildren[1]['blockName'] ?? '')
    && str_contains((string) ($externalLayoutCardChildren[1]['attrs']['className'] ?? ''), 'blocks-engine-css-owned-layout-item')
    && 'core/paragraph' === ($externalLayoutCardChildren[1]['innerBlocks'][0]['blockName'] ?? '')
    && 4 <= substr_count($externalLayoutPage, 'blocks-engine-semantic-')
    && str_contains($externalLayoutCss, ':root :where(.wp-block-group.blocks-engine-css-owned-layout-item)>*{margin-block-start:0;margin-block-end:0}')
    && ! str_contains($externalLayoutCss, '.artifact-card > span:not(.card-label)')
    && ! str_contains($externalLayoutCss, '.artifact-card > strong'),
    'linked implicit and explicit grids retain valid semantic layout-item carriers with neutralized generated paragraph flow in canonical site-plan markup'
);

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

$listStyles = ( new ArtifactCompiler() )->compile(array(
    'entrypoint' => 'index.html',
    'files' => array(
        array( 'path' => 'index.html', 'kind' => 'html', 'content' => '<link rel="stylesheet" href="site.css"><main><ol class="pipeline maintenance-loop"><li class="stage"><div class="stage-copy">Build source<p>Maintenance detail</p><ul class="chips"><li>HTML</li></ul><ul class="check-list"><li>Verified delivery</li></ul></div></li></ol></main>' ),
        array( 'path' => 'site.css', 'kind' => 'css', 'content' => '.stage-copy{display:grid}.maintenance-loop li > div > p{margin:.25rem 0 0;color:#c8ded3;font-size:.78rem}.check-list li{position:relative;padding:0 0 0 1.75rem;margin:0 0 .75rem;font-size:1.125rem;line-height:1.5}.check-list li::before{content:"x";position:absolute;left:0}.chips li{position:relative;padding:.25rem .75rem;margin:0 .5rem .5rem 0;font-size:.875rem}.chips li:hover{color:#123456}' ),
    ),
) )->toArray();
$findBlocks = static function (array $blocks, string $name) use (&$findBlocks): array {
    $found = array();
    foreach ( $blocks as $block ) {
        if ( ! is_array($block) ) {
            continue;
        }
        if ( $name === ($block['blockName'] ?? '') ) {
            $found[] = $block;
        }
        $found = array_merge($found, $findBlocks($block['innerBlocks'] ?? array(), $name));
    }
    return $found;
};
$listStyleItems = $findBlocks($listStyles['blocks'] ?? array(), 'core/list-item');
$listStyleCss = implode("\n", array_column($listStyles['assets'] ?? array(), 'content'));
$listStyleMarkup = (string) ($listStyles['serialized_blocks'] ?? '');
$assert(1 === count($findBlocks($listStyles['blocks'] ?? array(), 'core/list')) && 1 === count($listStyleItems) && ! str_contains($listStyleMarkup, '<!-- wp:html'), 'wrapped nested lists remain inside the native outer list item without sibling core/list extraction');
$outerListItem = $listStyleItems[0] ?? array();
$outerContent = (string) ($outerListItem['attrs']['content'] ?? '');
$assert(! isset($outerListItem['attrs']['style']['spacing']['padding']['left']) && ! isset($outerListItem['attrs']['style']['typography']['fontSize']) && preg_match('/<div class="stage-copy blocks-engine-source-div-[a-f0-9]+-\d+">Build source<p class="blocks-engine-source-p-[a-f0-9]+-\d+">Maintenance detail<\/p><ul class="chips"><li class="blocks-engine-source-li-[a-f0-9]+-\d+">HTML<\/li><\/ul><ul class="check-list"><li class="blocks-engine-source-li-[a-f0-9]+-\d+">Verified delivery<\/li><\/ul><\/div>/', $outerContent) === 1, 'wrapped source-tag descendants retain provenance inside the stage-copy topology rather than moving beside it');
$assert(str_contains($listStyleCss, '.stage-copy{display:grid}') && str_contains($listStyleCss, ':where(.blocks-engine-source-p-') && str_contains($listStyleCss, 'margin:.25rem 0 0') && str_contains($listStyleCss, ':where(.blocks-engine-source-li-') && str_contains($listStyleCss, 'position:relative') && str_contains($listStyleCss, '.check-list :where(.blocks-engine-source-li-') && str_contains($listStyleCss, '::before') && str_contains($listStyleCss, ':hover{color:#123456}'), 'projected author CSS continues to address retained rich descendants, nested list leaves, and pseudo-elements');
$assert(isset($listStyles['source_reports']['wordpress_site_plan']) && str_contains((string) ($listStyles['source_reports']['wordpress_site_plan']['pages'][0]['canonical_block_markup'] ?? ''), '<!-- wp:list-item'), 'external list-item styling survives artifact compilation into the canonical WordPress site plan');
$listStyleValidity = ( new HtmlTransformer() )->transform(
    '<main><ol class="pipeline maintenance-loop"><li class="stage"><div class="stage-copy">Build source<p>Maintenance detail</p><ul class="chips"><li>HTML</li></ul><ul class="check-list"><li>Verified delivery</li></ul></div></li></ol></main>',
    array( 'static_css' => '.stage-copy{display:grid}.maintenance-loop li > div > p{margin:.25rem 0 0;color:#c8ded3;font-size:.78rem}.check-list li{position:relative;padding:0 0 0 1.75rem;margin:0 0 .75rem;font-size:1.125rem;line-height:1.5}.chips li{position:relative;padding:.25rem .75rem;margin:0 .5rem .5rem 0;font-size:.875rem}' )
)->toArray();
$assert('pass' === ($listStyleValidity['source_reports']['wp_block_validity']['status'] ?? ''), 'resolved external list-item styles serialize as Gutenberg-valid native blocks');
$directNestedList = ( new HtmlTransformer() )->transform('<ol><li>Stage<ul><li>Leaf</li></ul></li></ol>')->toArray();
$assert(2 === count($findBlocks($directNestedList['blocks'] ?? array(), 'core/list')) && 2 === count($findBlocks($directNestedList['blocks'] ?? array(), 'core/list-item')), 'direct-child nested lists continue to serialize as native nested list blocks');

$nativeTable = ( new ArtifactCompiler() )->compile(array(
    'entrypoint' => 'website/index.html',
    'files' => array(
        array( 'path' => 'website/index.html', 'kind' => 'html', 'content' => '<link rel="stylesheet" href="/table.css"><main><table><thead><tr><th>Layer</th><th>Owns</th></tr></thead><tbody><tr><th>Blocks Engine</th><td class="stack-cell">Compilation</td></tr><tr><th>Importer</th><td class="stack-cell"><div class="paragraph">Materialization</div></td></tr></tbody></table></main>' ),
        array( 'path' => 'website/table.css', 'kind' => 'css', 'content' => 'th,td{padding:1.35rem 1rem;vertical-align:top}thead th{font-size:.66rem;text-transform:uppercase}tbody th{width:22%;font-size:.9rem}tbody td{width:39%;color:#4c5851;font-size:.86rem}div.paragraph{padding-bottom:20px}@media(max-width:600px){td.stack-cell{display:block;width:100%;padding:10px 0}}' ),
    ),
) )->toArray();
$nativeTableMarkup = (string) ($nativeTable['serialized_blocks'] ?? '');
$nativeTableCss = implode("\n", array_column(array_filter($nativeTable['assets'] ?? array(), static fn (array $asset): bool => 'website/table.css' === ($asset['path'] ?? '')), 'content'));
$assert(preg_match('/<figure class="wp-block-table (blocks-engine-table-[^"]+)"><table/', $nativeTableMarkup, $nativeTableMarker) === 1 && str_contains($nativeTableMarkup, '<!-- wp:table'), 'external table stylesheet retains a native core/table with an isolated projection marker');
$assert(str_contains($nativeTableCss, '.' . ($nativeTableMarker[1] ?? '') . '>table>thead>tr:nth-child(1)>th:nth-child(1):not(blocks-engine-specificity-') && str_contains($nativeTableCss, '.' . ($nativeTableMarker[1] ?? '') . '>table>tbody>tr:nth-child(1)>td:nth-child(2):not(blocks-engine-specificity-') && ! str_contains($nativeTableCss, ':where(.' . ($nativeTableMarker[1] ?? '') . '>table>') && str_contains($nativeTableCss, 'padding:1.35rem 1rem'), 'direct th and td selectors use an isolated table class and exact path that beats .wp-block-table td/th defaults');
$assert(str_contains($nativeTableCss, 'font-size:.66rem') && str_contains($nativeTableCss, 'width:22%') && str_contains($nativeTableCss, 'width:39%'), 'thead and tbody cell selectors retain their external stylesheet presentation');
$assert(str_contains($nativeTableCss, '@media(max-width:600px)') && str_contains($nativeTableCss, '.' . ($nativeTableMarker[1] ?? '') . '>table>tbody>tr:nth-child(1)>td:nth-child(2)') && str_contains($nativeTableCss, 'display:block;width:100%;padding:10px 0'), 'external responsive cell selectors project through the isolated native table marker');
$assert(preg_match('/<div class="paragraph (blocks-engine-source-div-[^"]+)">Materialization<\/div>/', $nativeTableMarkup, $nativeTableDescendantMarker) === 1 && str_contains($nativeTableCss, ':where(.' . ($nativeTableDescendantMarker[1] ?? '') . ')') && str_contains($nativeTableCss, 'padding-bottom:20px'), 'preserved table-cell descendants retain source-tag selector markers');
$assert('pass' === ( new Runtime() )->validateBlockSerialization($nativeTableMarkup)['status'], 'projected artifact table markup remains editor-valid');

$tableNormalization = ( new ArtifactCompiler() )->compile(array(
    'files' => array(
        array( 'path' => 'index.html', 'kind' => 'html', 'content' => '<link rel="stylesheet" href="table.css"><main><div class="table-wrap"><table><thead><tr><th>Header</th></tr></thead><tbody><tr><td>Body</td></tr></tbody></table></div></main>' ),
        array( 'path' => 'table.css', 'kind' => 'css', 'content' => '.table-wrap{margin-bottom:2rem}table{margin:3rem 0;border-collapse:collapse;border-spacing:0}th,td{border-bottom:1px solid #d8d9d1}' ),
    ),
) )->toArray();
$tableNormalizationMarkup = (string) ($tableNormalization['serialized_blocks'] ?? '');
$tableNormalizationCss = implode("\n", array_column(array_filter($tableNormalization['assets'] ?? array(), static fn (array $asset): bool => 'css' === ($asset['kind'] ?? '')), 'content'));
$assert(preg_match('/<figure class="wp-block-table (blocks-engine-table-[^"]+)">/', $tableNormalizationMarkup, $tableNormalizationMarker) === 1, 'native table normalization uses an isolated table marker');
$tableCellReset = '.' . ($tableNormalizationMarker[1] ?? '') . '>table th,.' . ($tableNormalizationMarker[1] ?? '') . '>table td{border:0}';
$tableBottomBorder = '.' . ($tableNormalizationMarker[1] ?? '') . '>table>tbody>tr:nth-child(1)>td:nth-child(1)';
$assert(str_contains($tableNormalizationCss, '.' . ($tableNormalizationMarker[1] ?? '') . '{margin:0}') && ! str_contains($tableNormalizationCss, '.wp-block-table.' . ($tableNormalizationMarker[1] ?? '') . '{margin:0}') && str_contains($tableNormalizationCss, '.' . ($tableNormalizationMarker[1] ?? '') . '>table{border-collapse:collapse;border-spacing:0}') && str_contains($tableNormalizationCss, $tableCellReset) && str_contains($tableNormalizationCss, '.' . ($tableNormalizationMarker[1] ?? '') . '>table>thead{border-bottom:0}') && false !== strpos($tableNormalizationCss, $tableCellReset) && false !== strpos($tableNormalizationCss, $tableBottomBorder) && strpos($tableNormalizationCss, $tableCellReset) < strpos($tableNormalizationCss, $tableBottomBorder) && str_contains($tableNormalizationCss, $tableBottomBorder . ':not(blocks-engine-specificity-'), 'collapsed border tables clear all synthetic cell sides before projected bottom-only author rules restore the source geometry');
$assert(str_contains($tableNormalizationCss, '.table-wrap{margin-bottom:2rem}') && str_contains($tableNormalizationCss, 'table{margin:3rem 0}') && str_contains($tableNormalizationCss, 'table{border-collapse:collapse;border-spacing:0}'), 'authored wrapper and table margins remain in the source stylesheet rather than becoming a broad table override');
$assert('pass' === ( new Runtime() )->validateBlockSerialization($tableNormalizationMarkup)['status'], 'table normalization preserves editor-valid native markup');

$borderedTable = ( new ArtifactCompiler() )->compile(array(
    'files' => array(
        array( 'path' => 'index.html', 'kind' => 'html', 'content' => '<link rel="stylesheet" href="table.css"><main><table><tbody><tr><td>Framed</td></tr></tbody></table></main>' ),
        array( 'path' => 'table.css', 'kind' => 'css', 'content' => 'table{border:2px solid #123456;border-collapse:collapse}td{border-bottom:1px solid #d8d9d1}' ),
    ),
) )->toArray();
$borderedTableMarkup = (string) ($borderedTable['serialized_blocks'] ?? '');
$borderedTableCss = implode("\n", array_column(array_filter($borderedTable['assets'] ?? array(), static fn (array $asset): bool => 'css' === ($asset['kind'] ?? '')), 'content'));
$assert(preg_match('/<figure class="wp-block-table (blocks-engine-table-[^"]+)">/', $borderedTableMarkup, $borderedTableMarker) === 1 && str_contains($borderedTableCss, '.' . ($borderedTableMarker[1] ?? '') . '>table th,.' . ($borderedTableMarker[1] ?? '') . '>table td{border:0}') && str_contains($borderedTableCss, 'table{border:2px solid #123456;border-collapse:collapse}'), 'authored table borders retain their outer frame while generated cell borders are reset');
$assert('pass' === ( new Runtime() )->validateBlockSerialization($borderedTableMarkup)['status'], 'authored bordered tables remain editor-valid');

if ( $failures > 0 ) {
    exit(1);
}
fwrite(STDOUT, "Artifact author stylesheet projection unit tests passed\n");
