<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactNormalizer;
use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\PayloadReader;
use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\RuntimeDeclarations;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\WordPressSitePlan;

$assert = static function (bool $condition, string $message): void { if (!$condition) throw new RuntimeException($message); };
$throws = static function (callable $callback, string $message) use ($assert): void { try { $callback(); } catch (InvalidArgumentException) { return; } $assert(false, $message); };
$artifact = array(
    'entrypoints' => array('index.html'),
    'files' => array(
        array('path' => 'assets/site.css', 'content' => 'main{color:#123;background:url(logo.png)}', 'metadata' => array('compilation' => array('scope' => 'shared'))),
        array('path' => 'assets/about.css', 'content' => '.about-grid{display:grid;grid-template-columns:1fr 1fr}', 'media' => '(min-width: 48rem)', 'metadata' => array('compilation' => array('scope' => 'page', 'id' => 'about.html'))),
        array('path' => 'about.html', 'content' => '<link rel="stylesheet" href="assets/site.css"><link rel="stylesheet" href="assets/about.css"><main class="about-grid"><h1>About</h1></main>'),
        array('path' => 'contact.html', 'content' => '<link rel="stylesheet" href="assets/site.css"><main><h1>Contact</h1></main>'),
        array('path' => 'index.html', 'content' => '<link rel="stylesheet" href="assets/site.css"><main><h1>Home</h1></main>'),
        array('path' => 'assets/logo.png', 'content_base64' => base64_encode('binary-png-fixture'), 'mime_type' => 'image/png', 'metadata' => array('compilation' => array('scope' => 'shared'))),
    ),
);
$forms = array();
for ($index = 0; $index < 29; ++$index) $forms[] = array('id' => 'form-' . $index, 'definition' => str_repeat('x', 14075));
$formsPayload = array('schema' => 'generic/forms/v1', 'entities' => $forms);
$formsPayloadBytes = strlen(RuntimeDeclarations::canonicalJson($formsPayload));
$assert($formsPayloadBytes > 262144 && $formsPayloadBytes < RuntimeDeclarations::MAX_TOTAL_DECLARATION_BYTES, 'The generated 29-form declaration represents the bounded payload size that exceeds the former per-payload limit.');
$artifact['runtime_declarations'] = array(array('kind' => 'entity_collection', 'type' => 'forms', 'source_path' => 'index.html', 'payload' => $formsPayload));
$compiler = new ArtifactCompiler();
$shared = $compiler->prepareShared($artifact);
$assert('blocks-engine/php-transformer/staged-shared-plan/v1' === $shared['schema'] && 2 === $shared['summary']['file_count'] && preg_match('/^[a-f0-9]{64}$/', $shared['digest']), 'Shared preparation preserves the published v1 plan envelope and digest.');
$assert('artifact' === ($shared['shared_reduction']['files_source'] ?? null) && !array_key_exists('files', $shared['shared_reduction']), 'Inline shared reductions reference their digest-bound artifact files instead of serializing a duplicate payload.');
$assert(array('diagnostics', 'projected_count') === array_keys($shared['analysis']['captured_dialogs']), 'Shared preparation persists bounded captured-dialog evidence without duplicating projected artifact files.');
// Inline assets expanded out of an unannotated page follow that page, not the
// immutable shared plan: parking page-varying content in the shared plan would
// invalidate every page plan on a page edit.
$inlineArtifact = $artifact;
$inlineArtifact['files'][2]['content'] .= '<style>main{gap:1rem}</style><script>console.log("about");</script>';
$inlineShared = $compiler->prepareShared($inlineArtifact);
$assert(2 === $inlineShared['summary']['file_count'], 'Shared preparation excludes page-owned inline expansions.');
$assert(4 === $compiler->preparePage($inlineArtifact, $inlineShared, 'about.html')['summary']['file_count'], 'Page preparation owns explicit and inline page assets with the page html.');

$pageIds = array('index.html', 'about.html', 'contact.html');
$pages = array();
foreach ($pageIds as $pageId) $pages[$pageId] = $compiler->preparePage($artifact, $shared, $pageId);
$assert(array('index.html', 'about.html', 'contact.html') === array_keys($pages), 'Three independent page plans are addressable by canonical page ownership ids.');
$batchPages = $compiler->preparePages($artifact, $shared);
$assert(array('about.html', 'contact.html', 'index.html') === array_keys($batchPages) && $pages['index.html'] === $batchPages['index.html'] && $pages['about.html'] === $batchPages['about.html'] && $pages['contact.html'] === $batchPages['contact.html'], 'Batch preparation partitions once while preserving every independently prepared page plan byte for byte.');

$compiledPages = array();
foreach ($pageIds as $pageId) $compiledPages[$pageId] = $compiler->compilePage($artifact, $shared, $pageId);
$assert(ArtifactCompiler::COMPACT_RECEIPT_SCHEMA === ($compiledPages['about.html']['receipt_schema'] ?? null) && 1 === ($compiledPages['about.html']['work']['compiled_document_count'] ?? null) && isset($compiledPages['about.html']['compiled_documents']['about.html']), 'A compiled page plan persists only its bounded page-owned document receipt.');
$assert(!array_key_exists('files', $compiledPages['about.html']['terminal_reduction']) && !array_key_exists('entry_blocks', $compiledPages['index.html']['terminal_reduction']), 'Compact receipts reference canonical page files and compiled entry output instead of serializing duplicate terminal payloads.');
$compiledBatch = $compiler->compilePreparedPages($shared, $pages);
foreach ($compiledBatch as &$compiledBatchPage) unset($compiledBatchPage['work']['compile_duration_ms']);
unset($compiledBatchPage);
foreach ($compiledPages as &$compiledPage) unset($compiledPage['work']['compile_duration_ms']);
unset($compiledPage);
$assert($compiledPages === $compiledBatch, 'Worker-batch compilation reuses bounded analysis without changing independently compiled receipt content.');

// One batch verifies its immutable shared plan once, so the batch entry point
// stays the enforcement boundary for every shared-plan invariant.
$tamperedReduction = $shared;
$tamperedReduction['shared_reduction']['component_facts']['tampered'] = true;
$throws(static fn () => $compiler->compilePreparedPages($tamperedReduction, $pages), 'Batch compilation rejects a shared reduction whose contents no longer match its digest.');
$throws(static fn () => $compiler->compilePreparedPage($tamperedReduction, $pages['index.html']), 'Single-page compilation rejects a shared reduction whose contents no longer match its digest.');
$tamperedSharedDigest = $shared;
$tamperedSharedDigest['digest'] = str_repeat('0', 64);
$throws(static fn () => $compiler->compilePreparedPages($tamperedSharedDigest, $pages), 'Batch compilation rejects a shared plan whose declared plan digest is invalid.');
$throws(static fn () => $compiler->compilePreparedPages(array_diff_key($shared, array('schema' => null)), $pages), 'Batch compilation rejects a shared plan that omits its staged schema.');

// Simulate interruption/resume and arbitrary parallel completion order.
$resumedShared = json_decode(json_encode($shared, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);
$resumedPages = json_decode(json_encode(array($pages['contact.html'], $pages['index.html'], $pages['about.html']), JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);
$staged = $compiler->compose($resumedShared, $resumedPages)->toArray();
$partialLegacy = $compiler->compose($resumedShared, array($pages['index.html']))->toArray();
$assert(array('index.html') === array_column($partialLegacy['source_reports']['wordpress_site_plan']['pages'] ?? array(), 'source_path'), 'Legacy prepared envelopes retain partial composition for batch-local page sets.');
$whole = $compiler->compile($artifact)->toArray();
$assert(($whole['source_reports']['wordpress_site_plan'] ?? array()) === ($staged['source_reports']['wordpress_site_plan'] ?? array()), 'Whole and staged compilation yield byte-for-byte equivalent canonical site plans, including source-operation provenance and hashes.');
$assert(($whole['source_reports']['materialization_plan'] ?? array()) === ($staged['source_reports']['materialization_plan'] ?? array()), 'Whole and staged compilation yield byte-for-byte equivalent materialization receipts.');
$compiledStaged = $compiler->compose($shared, array($compiledPages['contact.html'], $compiledPages['index.html'], $compiledPages['about.html']))->toArray();
$assert(($whole['source_reports']['wordpress_site_plan'] ?? array()) === ($compiledStaged['source_reports']['wordpress_site_plan'] ?? array()), 'Terminal composition consumes persisted compiled page receipts without changing the canonical site plan.');
$manyPages = array();
for ($index = 0; $index < 50; ++$index) {
    $path = sprintf('pages/page-%02d.html', $index);
    $manyPages[] = array('path' => $path, 'content' => '<link rel="stylesheet" href="../assets/site.css"><main class="page"><h1>Page ' . $index . '</h1><p>Page-local content ' . str_repeat((string) $index, 8) . '</p></main>');
}
$manyPages[0]['path'] = 'index.html';
$manyPages[0]['content'] = '<link rel="stylesheet" href="assets/site.css"><main class="page"><h1>Page 0</h1><p>Page-local content</p></main>';
$manyArtifact = array('entrypoints' => array('index.html'), 'files' => array_merge(array(array('path' => 'assets/site.css', 'content' => '.page{color:#123;margin:1rem}', 'metadata' => array('compilation' => array('scope' => 'shared')))), $manyPages));
$manyShared = $compiler->prepareShared($manyArtifact);
$assert(50 === count($manyShared['analysis']['page_ids'] ?? array()) && 'assets/site.css' === ($manyShared['analysis']['stylesheets'][0]['path'] ?? null), 'Shared preparation persists immutable stylesheet and source analysis for all page receipts.');
$manyInline = $compiler->compile($manyArtifact)->toArray();
$serializedShared = json_decode(json_encode($manyShared, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);
$preparedPages = $compiler->preparePages($manyArtifact, $manyShared);
$assert(50 === count($preparedPages), 'Batch preparation emits every page plan from one bounded whole-artifact partition.');
$serializedPages = json_decode(json_encode($preparedPages, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);
// Resume from serialized plans in fresh workers. Page compilation receives no
// source artifact, so a worker cannot normalize or retain all fifty pages.
$manyReceipts = array();
$initialWorker = new ArtifactCompiler();
$manyReceipts = array_values($initialWorker->compilePreparedPages($serializedShared, array_slice($serializedPages, 0, 25, true)));
unset($initialWorker, $manyArtifact, $preparedPages);
$resumedWorker = new ArtifactCompiler();
$manyReceipts = array_merge($manyReceipts, array_values($resumedWorker->compilePreparedPages($serializedShared, array_slice($serializedPages, 25, null, true))));
$serializedReceipts = json_decode(json_encode($manyReceipts, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);
$expandedReceipts = $serializedReceipts;
foreach ($expandedReceipts as &$expandedReceipt) {
    $expandedReceipt['terminal_reduction']['files'] = $expandedReceipt['artifact']['files'];
    $expandedReceipt['terminal_reduction']['entry_blocks'] = $expandedReceipt['compiled_documents']['index.html'] ?? null;
}
unset($expandedReceipt);
$compactReceiptBytes = strlen(json_encode($serializedReceipts, JSON_THROW_ON_ERROR));
$expandedReceiptBytes = strlen(json_encode($expandedReceipts, JSON_THROW_ON_ERROR));
$assert($compactReceiptBytes < $expandedReceiptBytes, sprintf('Fifty compact receipts serialize fewer bytes than equivalent duplicate terminal payloads (%d compact bytes versus %d expanded bytes).', $compactReceiptBytes, $expandedReceiptBytes));
$terminalWorker = new ArtifactCompiler();
$manyStaged = $terminalWorker->compose($serializedShared, array_reverse($serializedReceipts))->toArray();
$canonical = static function (mixed $value) use (&$canonical): mixed {
    if (!is_array($value)) return $value;
    unset($value['transform_duration_ms'], $value['compile_duration_ms'], $value['html_document_transform_count'], $value['normalization_count'], $value['analysis_count'], $value['terminal_reduction_count']);
    foreach ($value as $key => $item) $value[$key] = $canonical($item);
    return $value;
};
$assert(0 === ($manyStaged['metrics']['html_document_transform_count'] ?? null), 'Terminal aggregation performs no HTML document transformations after independently serialized page receipts are complete.');
$assert(0 === ($manyStaged['metrics']['normalization_count'] ?? null) && 0 === ($manyStaged['metrics']['analysis_count'] ?? null) && 0 === ($serializedReceipts[0]['work']['normalization_count'] ?? null) && 3 === ($serializedReceipts[0]['work']['analysis_count'] ?? null), 'Prepared pages are not normalized again by receipt workers, and terminal aggregation performs no normalization or raw analysis.');
$assert(50 === ($manyInline['metrics']['html_document_transform_count'] ?? null), 'Inline compilation performs bounded work once per page document.');
$assert($canonical($manyInline['source_reports']['wordpress_site_plan'] ?? array()) === $canonical($manyStaged['source_reports']['wordpress_site_plan'] ?? array()) && $canonical($manyInline['diagnostics'] ?? array()) === $canonical($manyStaged['diagnostics'] ?? array()), 'Fifty-page arbitrary-order resume preserves canonical WordPress plans and diagnostics after observational fields are excluded.');
$assert($canonical($manyInline) === $canonical($manyStaged), 'Fifty-page arbitrary-order resume preserves the complete canonical transformer result after observational fields are excluded.');
$manyPageComponent = current(array_filter($manyStaged['components'], static fn(array $component): bool => 'page' === ($component['name'] ?? null)));
$assert(50 === ($manyPageComponent['occurrences'] ?? null), 'A class occurring once per page is qualified from the globally summed uncapped component facts.');
$largeReceiptArtifact = array('entrypoint' => 'index.html', 'files' => array(array('path' => 'index.html', 'content' => '<main><p>' . str_repeat('receipt-payload ', 32768) . '</p></main>')));
$largeReceiptShared = $compiler->prepareShared($largeReceiptArtifact);
$largeReceipt = $compiler->compilePage($largeReceiptArtifact, $largeReceiptShared, 'index.html');
$largeExpandedReceipt = $largeReceipt;
$largeExpandedReceipt['terminal_reduction']['files'] = $largeExpandedReceipt['artifact']['files'];
$largeExpandedReceipt['terminal_reduction']['entry_blocks'] = $largeExpandedReceipt['compiled_documents']['index.html'];
$largeCompactBytes = strlen(json_encode($largeReceipt, JSON_THROW_ON_ERROR));
$largeExpandedBytes = strlen(json_encode($largeExpandedReceipt, JSON_THROW_ON_ERROR));
$assert($largeCompactBytes < (int) ($largeExpandedBytes * 0.7), sprintf('A large compiled page receipt is at least thirty percent smaller without duplicate source and entry output (%d compact bytes versus %d expanded bytes).', $largeCompactBytes, $largeExpandedBytes));
$largeSharedArtifact = array('entrypoint' => 'index.html', 'files' => array(
    array('path' => 'index.html', 'content' => '<main>Shared reduction size</main>'),
    array('path' => 'assets/shared.bin', 'content_base64' => base64_encode(str_repeat('shared-payload', 32768)), 'mime_type' => 'application/octet-stream', 'metadata' => array('compilation' => array('scope' => 'shared'))),
));
$largeSharedPlan = $compiler->prepareShared($largeSharedArtifact);
$largeExpandedSharedPlan = $largeSharedPlan;
$largeExpandedSharedPlan['shared_reduction']['files'] = $largeExpandedSharedPlan['artifact']['files'];
unset($largeExpandedSharedPlan['shared_reduction']['files_source']);
$largeSharedBytes = strlen(json_encode($largeSharedPlan, JSON_THROW_ON_ERROR));
$largeExpandedSharedBytes = strlen(json_encode($largeExpandedSharedPlan, JSON_THROW_ON_ERROR));
$assert($largeSharedBytes < (int) ($largeExpandedSharedBytes * 0.7), sprintf('A large shared plan is at least thirty percent smaller when its reduction references the digest-bound artifact files (%d compact bytes versus %d expanded bytes).', $largeSharedBytes, $largeExpandedSharedBytes));
$pageScopedScriptArtifact = array('entrypoint' => 'index.html', 'files' => array(
    array('path' => 'index.html', 'content' => '<main id="home-target"><script src="js/home.js"></script><h1>Home</h1></main>'),
    array('path' => 'about.html', 'content' => '<main id="about-target"><script src="js/about.js"></script><h1>About</h1></main>'),
    array('path' => 'js/home.js', 'content' => 'document.getElementById("home-target").addEventListener("click", function () {});', 'metadata' => array('compilation' => array('scope' => 'page', 'id' => 'index.html'))),
    array('path' => 'js/about.js', 'content' => 'document.getElementById("about-target").addEventListener("click", function () {});', 'metadata' => array('compilation' => array('scope' => 'page', 'id' => 'about.html'))),
));
$pageScopedWhole = $compiler->compile($pageScopedScriptArtifact)->toArray();
$pageScopedShared = $compiler->prepareShared($pageScopedScriptArtifact);
$pageScopedReceipts = array();
foreach ($pageScopedShared['analysis']['page_ids'] as $pageId) $pageScopedReceipts[] = $compiler->compilePage($pageScopedScriptArtifact, $pageScopedShared, $pageId);
$pageScopedStaged = $compiler->compose($pageScopedShared, array_reverse($pageScopedReceipts))->toArray();
$pageScopedDependencies = $pageScopedWhole['source_reports']['runtime_dependency_parity']['dependencies'] ?? array();
$assert('pass' === ($pageScopedWhole['source_reports']['runtime_dependency_parity']['status'] ?? '') && array() === array_values(array_filter($pageScopedDependencies, static fn (array $dependency): bool => 'js/about.js' === ($dependency['script_path'] ?? ''))), 'page-owned scripts are not evaluated against another page output');
$assert($canonical($pageScopedWhole) === $canonical($pageScopedStaged), 'page-owned script parity remains deterministic for staged receipt composition.');
$componentArtifact = array('entrypoint' => 'index.html', 'files' => array(
    array('path' => 'index.html', 'content' => '<main class="distributed-widget"><h1>Home</h1></main>'),
    array('path' => 'second.html', 'content' => '<main class="distributed-widget"><h1>Second</h1></main>'),
));
$componentShared = $compiler->prepareShared($componentArtifact);
$componentReceipts = array();
foreach ($componentShared['analysis']['page_ids'] as $pageId) $componentReceipts[] = $compiler->compilePage($componentArtifact, $componentShared, $pageId);
$componentInline = $compiler->compile($componentArtifact)->toArray();
$componentStaged = $compiler->compose($componentShared, array_reverse($componentReceipts))->toArray();
$qualified = current(array_filter($componentStaged['components'], static fn(array $component): bool => 'distributed-widget' === ($component['name'] ?? null)));
$assert(2 === ($qualified['occurrences'] ?? null) && $canonical($componentInline) === $canonical($componentStaged), 'Uncapped page component statistics qualify, order, and cap only after their global occurrence counts are summed.');
$sourceArtifact = array('entrypoint' => 'index.html', 'files' => array(
    array('path' => 'index.html', 'content' => '<main><h1>Home</h1></main>'),
    array('path' => 'notes.md', 'content' => "---\ntitle: Notes\n---\n\n# Notes\n\nMarkdown body."),
    array('path' => 'guide.mdx', 'content' => "import Callout from './Callout'\n\n# Guide\n\n<Callout>MDX body.</Callout>"),
));
$sourceShared = $compiler->prepareShared($sourceArtifact);
$assert(array('guide.mdx', 'index.html', 'notes.md') === ($sourceShared['analysis']['page_ids'] ?? null), 'Shared preparation declares HTML, Markdown, and MDX page ownership without reading page content.');
$sourceReceipts = array();
foreach ($sourceShared['analysis']['page_ids'] as $pageId) $sourceReceipts[] = $compiler->compilePage($sourceArtifact, $sourceShared, $pageId);
$sourceInline = $compiler->compile($sourceArtifact)->toArray();
$sourceStaged = $compiler->compose($sourceShared, array_reverse($sourceReceipts))->toArray();
$assert($canonical($sourceInline) === $canonical($sourceStaged), 'Compiled receipts exactly cover HTML, Markdown, and MDX sources and preserve their complete canonical result.');
$nestedEntryArtifact = array('entrypoint' => 'website/index.html', 'files' => array(
    array('path' => 'website/about.html', 'content' => '<main>About</main>'),
    array('path' => 'website/blog/post/index.html', 'content' => '<main>Post</main>', 'entrypoint' => true),
    array('path' => 'website/index.html', 'content' => '<main>Home</main>'),
));
$nestedEntryShared = $compiler->prepareShared($nestedEntryArtifact);
$nestedEntryReceipts = array();
foreach ($nestedEntryShared['analysis']['page_ids'] as $pageId) $nestedEntryReceipts[] = $compiler->compilePage($nestedEntryArtifact, $nestedEntryShared, $pageId);
$nestedEntryStaged = $compiler->compose($nestedEntryShared, array_reverse($nestedEntryReceipts))->toArray();
$nestedEntryPages = array_column($nestedEntryStaged['source_reports']['compiled_site']['pages'] ?? array(), 'entrypoint', 'source_path');
$assert(isset($nestedEntryStaged['source_reports']['wordpress_site_plan']) && array('website/about.html' => false, 'website/blog/post/index.html' => false, 'website/index.html' => true) === $nestedEntryPages, 'Nested index candidates remain ordinary routes when the artifact selects a shallower canonical entrypoint.');
$throws(static fn() => $compiler->compose($manyShared, array_slice($serializedReceipts, 1)), 'Composition rejects a missing compiled page receipt deterministically.');
$sitePlan = $whole['source_reports']['wordpress_site_plan'] ?? array();
$siteAssets = array_column($sitePlan['assets'] ?? array(), null, 'source_path');
$siteWrites = array_column($sitePlan['writes'] ?? array(), null, 'target_path');
$bootstrap = (string) ($siteWrites['functions.php']['payload']['data'] ?? '');
$assert(array(array('kind' => 'global')) === ($siteAssets['assets/site.css']['scopes'] ?? null), 'Shared stylesheets retain an explicit global runtime scope.');
$assert('about.html' === ($siteAssets['assets/about.css']['scopes'][0]['source_path'] ?? null) && str_contains($bootstrap, "if ( is_page() && 'about' === trim( get_page_uri( get_queried_object_id() ), '/' ) ) wp_enqueue_style"), 'Page-owned stylesheets enqueue only on their canonical WordPress route.');
$assert('(min-width: 48rem)' === ($siteAssets['assets/about.css']['media'] ?? null) && str_contains($bootstrap, "array(), null, '(min-width: 48rem)'"), 'Stylesheet media conditions are retained as canonical frontend enqueue arguments.');
$assert(str_contains($bootstrap, "\$css = '@media ' . \$style['media'] . '{' . \$css . '}'"), 'Canonical editor styles preserve their stylesheet media conditions.');
$assert(str_contains($bootstrap, "add_filter( 'wp_theme_json_data_theme'") && str_contains($bootstrap, "update_with( array( 'version' => 3, 'styles' => array( 'css' => \$presentation ) ) )") && str_contains($bootstrap, "add_filter( 'block_editor_settings_all'") && str_contains($bootstrap, "blocks-engine-presentation:") && str_contains($bootstrap, "get_theme_file_path( \$style['target_path'] )") && str_contains($bootstrap, "! \$include_editor_only && ! empty( \$style['editor_only'] )") && str_contains($bootstrap, "\$context->post") && str_contains($bootstrap, "get_page_uri( \$post )") && str_contains($bootstrap, "'__unstableType' => 'user'") && str_contains($bootstrap, "'isGlobalStyles' => true"), 'Canonical bootstrap routes content-addressed route styles through theme JSON, excludes editor-only assets from frontend theme JSON, and provides a user Global Styles fallback for the edited post iframe.');
$themeScaffold = json_decode((string) ($siteWrites['theme.json']['payload']['data'] ?? ''), true);
$assert(is_array($themeScaffold) && '0px' === ($themeScaffold['styles']['spacing']['blockGap'] ?? null), 'Generated theme.json declares an explicit block gap so the editor canvas does not inherit the WordPress 24px layout gap that the frontend never emits.');
$inlineEntryArtifact = $inlineArtifact;
$inlineEntryArtifact['entrypoints'] = array('about.html');
$inlineSitePlan = $compiler->compile($inlineEntryArtifact)->toArray()['source_reports']['wordpress_site_plan'] ?? array();
$inlineAssets = array_column($inlineSitePlan['assets'] ?? array(), null, 'source_path');
$assert('about.html' === ($inlineAssets['about.inline.css']['scopes'][0]['source_path'] ?? null) && false === ($inlineAssets['about.inline.css']['scopes'][0]['front_page'] ?? null), 'Inferred inline stylesheet ownership follows its canonical non-root route even when that page is the compiler entrypoint.');
$bundledPages = array();
foreach (array('index.html', 'about.html', 'posts/news.html') as $page) {
    $styles = '';
    for ($index = 0; $index < 8; ++$index) {
        $styles .= '<style>.cascade-' . $index . '{color:#' . $index . $index . $index . '}</style>';
    }
    $styles .= '<style media="(max-width: 48rem)">.responsive-' . str_replace(array('/', '.'), '-', $page) . '{display:block}</style>';
    $bundledPages[] = array('path' => $page, 'content' => '<link rel="stylesheet" href="' . ('index.html' === $page ? 'assets/shared.css' : str_repeat('../', substr_count($page, '/')) . 'assets/shared.css') . '">' . $styles . '<main>Bundled ' . $page . '</main>');
}
$bundledArtifact = array('entrypoint' => 'index.html', 'files' => array_merge(array(array('path' => 'assets/shared.css', 'content' => '.shared{display:grid}', 'metadata' => array('compilation' => array('scope' => 'shared')))), $bundledPages));
$bundleCompiler = new ArtifactCompiler();
$bundledWhole = $bundleCompiler->compile($bundledArtifact)->toArray();
$bundledShared = $bundleCompiler->prepareShared($bundledArtifact);
$bundledReceipts = array();
foreach ($bundledShared['analysis']['page_ids'] as $pageId) $bundledReceipts[] = $bundleCompiler->compilePage($bundledArtifact, $bundledShared, $pageId);
$bundledStaged = $bundleCompiler->compose($bundledShared, array_reverse($bundledReceipts))->toArray();
$bundledPlan = $bundledWhole['source_reports']['wordpress_site_plan'] ?? array();
$bundledCss = array_values(array_filter($bundledPlan['assets'] ?? array(), static fn(array $asset): bool => 'css' === ($asset['kind'] ?? null)));
$bundledBootstrap = (string) ((array_column($bundledPlan['writes'] ?? array(), null, 'target_path')['functions.php']['payload']['data'] ?? ''));
$assert(8 === count($bundledCss) && 8 === substr_count($bundledBootstrap, 'wp_enqueue_style(') && 2 === substr_count($bundledBootstrap, 'is_front_page()'), 'Three-page inline-style fragmentation coalesces into one shared and two bounded stylesheet records per route, plus the existing global engine-support stylesheet.');
$indexBundle = current(array_filter($bundledCss, static fn(array $asset): bool => 'page' === ($asset['scopes'][0]['kind'] ?? null) && true === ($asset['scopes'][0]['front_page'] ?? null) && '' === ($asset['media'] ?? '')));
$assert(is_array($indexBundle) && str_contains((string) ($indexBundle['content'] ?? ''), '.cascade-0{color:#000}') && strpos((string) $indexBundle['content'], '.cascade-0{color:#000}') < strpos((string) $indexBundle['content'], '.cascade-7{color:#777}') && hash('sha256', (string) $indexBundle['content']) === ($indexBundle['content_hash'] ?? null), 'A coalesced route bundle preserves author cascade order and content-addressed identity.');
$assert($canonical($bundledWhole['source_reports']['wordpress_site_plan'] ?? array()) === $canonical($bundledStaged['source_reports']['wordpress_site_plan'] ?? array()) && $canonical($bundledWhole['diagnostics'] ?? array()) === $canonical($bundledStaged['diagnostics'] ?? array()), 'Bounded stylesheet bundles preserve direct and staged canonical plans and diagnostics.');
$formsDeclaration = current(array_filter($whole['source_reports']['wordpress_site_plan']['runtime_declarations'] ?? array(), static fn(array $declaration): bool => 'forms' === ($declaration['type'] ?? null)));
$assert(29 === count($formsDeclaration['payload']['entities'] ?? array()) && $formsPayloadBytes === strlen(RuntimeDeclarations::canonicalJson($formsDeclaration['payload'] ?? null)), 'Compilation retains the complete bounded 29-form runtime declaration.');

$oversizedDeclaration = array('kind' => 'dependency', 'capability' => 'oversized', 'source_path' => 'runtime/oversized.json', 'payload' => array('schema' => 'generic/dependency/v1', 'value' => str_repeat('x', RuntimeDeclarations::MAX_TOTAL_DECLARATION_BYTES + 1)));
$throws(static fn() => $compiler->compile(array('entrypoint' => 'index.html', 'runtime_declarations' => array($oversizedDeclaration), 'files' => array('index.html' => '<main>Oversized</main>'))), 'Compilation rejects a runtime declaration payload above the established aggregate resource boundary.');

$differentShared = $shared;
$differentShared['artifact']['files'][0]['content'] = 'main{color:#456}';
$throws(static fn() => $compiler->compose($differentShared, $resumedPages), 'Composition rejects a serialized shared payload whose digest no longer matches.');
$wrongBinding = $resumedPages;
$wrongBinding[0]['shared_digest'] = str_repeat('0', 64);
$throws(static fn() => $compiler->compose($shared, $wrongBinding), 'Composition rejects a page plan bound to another shared digest.');
$corruptCompiledPage = $compiledPages['about.html'];
$corruptCompiledPage['compiled_documents']['about.html']['serialized_blocks'] = 'corrupt';
$throws(static fn() => $compiler->compose($shared, array($compiledPages['index.html'], $corruptCompiledPage, $compiledPages['contact.html'])), 'Composition rejects compiled page receipts that no longer match their page-plan digest.');
$incompleteReceipt = $compiledPages['about.html'];
unset($incompleteReceipt['terminal_reduction']['source_documents']);
$throws(static fn() => $compiler->compose($shared, array($compiledPages['index.html'], $incompleteReceipt, $compiledPages['contact.html'])), 'Composition rejects incomplete compiled reductions.');
$optionMismatch = $compiledPages['about.html'];
$optionMismatch['compiler_options']['compiled_page_schema'] = 'incompatible';
$throws(static fn() => $compiler->compose($shared, array($compiledPages['index.html'], $optionMismatch, $compiledPages['contact.html'])), 'Composition rejects option-mismatched receipts.');
$schemaMismatch = $compiledPages['about.html'];
$schemaMismatch['output_schema'] = 'incompatible';
$throws(static fn() => $compiler->compose($shared, array($compiledPages['index.html'], $schemaMismatch, $compiledPages['contact.html'])), 'Composition rejects output-schema-mismatched receipts.');
$reductionMismatch = $shared;
$reductionMismatch['shared_reduction']['component_facts']['classes']['corrupt'] = 1;
$throws(static fn() => $compiler->compose($reductionMismatch, array()), 'Composition rejects a shared reduction whose immutable digest no longer matches.');

$throws(static fn() => $compiler->compose($shared, array($pages['index.html'], $pages['index.html'])), 'Composition rejects more than one page plan for the same page id.');

$v2Shared = $shared;
$v2Shared['compiler_options']['compiled_page_schema'] = ArtifactCompiler::COMPILED_RECEIPT_SCHEMA;
$v2Shared['digest'] = RuntimeDeclarations::hash(array('artifact' => $v2Shared['artifact'], 'analysis' => $v2Shared['analysis'], 'shared_reduction' => $v2Shared['shared_reduction'], 'shared_reduction_digest' => $v2Shared['shared_reduction_digest'], 'compiler_options' => $v2Shared['compiler_options']));
$v2Receipts = array();
foreach ($pageIds as $pageId) {
    $v2Page = $compiler->preparePage($artifact, $v2Shared, $pageId);
    $v2Page['compiler_options']['compiled_page_schema'] = ArtifactCompiler::COMPILED_RECEIPT_SCHEMA;
    $v2Page['digest'] = RuntimeDeclarations::hash(array('shared_digest' => $v2Page['shared_digest'], 'page_id' => $v2Page['page_id'], 'artifact' => $v2Page['artifact'], 'compiler_options' => $v2Page['compiler_options'], 'output_schema' => $v2Page['output_schema']));
    $v2Receipts[] = $compiler->compilePreparedPage($v2Shared, $v2Page);
}
$v2Result = $compiler->compose($v2Shared, array_reverse($v2Receipts))->toArray();
$assert(array_key_exists('files', $v2Receipts[0]['terminal_reduction']) && array_key_exists('entry_blocks', $v2Receipts[0]['terminal_reduction']) && $whole['blocks'] === $v2Result['blocks'] && ($whole['source_reports']['wordpress_site_plan'] ?? array()) === ($v2Result['source_reports']['wordpress_site_plan'] ?? array()), 'Persisted v2 duplicate-payload receipts retain canonical composition compatibility.');

$legacyShared = $shared;
unset($legacyShared['shared_reduction'], $legacyShared['shared_reduction_digest']);
$legacyShared['compiler_options']['compiled_page_schema'] = ArtifactCompiler::PAGE_RECEIPT_SCHEMA;
$legacyShared['digest'] = RuntimeDeclarations::hash(array('artifact' => $legacyShared['artifact'], 'analysis' => $legacyShared['analysis'], 'compiler_options' => $legacyShared['compiler_options']));
$legacyReceipts = array();
foreach ($pageIds as $pageId) {
    $legacyPage = $compiler->preparePage($artifact, $legacyShared, $pageId);
    $legacyPage['compiler_options']['compiled_page_schema'] = ArtifactCompiler::PAGE_RECEIPT_SCHEMA;
    $legacyPage['digest'] = RuntimeDeclarations::hash(array('shared_digest' => $legacyPage['shared_digest'], 'page_id' => $legacyPage['page_id'], 'artifact' => $legacyPage['artifact'], 'compiler_options' => $legacyPage['compiler_options'], 'output_schema' => $legacyPage['output_schema']));
    $legacyReceipts[] = $compiler->compilePreparedPage($legacyShared, $legacyPage);
}
$legacyResult = $compiler->compose($legacyShared, array_reverse($legacyReceipts))->toArray();
$assert($whole['blocks'] === $legacyResult['blocks'] && ($whole['source_reports']['wordpress_site_plan'] ?? array()) === ($legacyResult['source_reports']['wordpress_site_plan'] ?? array()), 'Serialized v1 shared plans, page plans, and compiled receipts retain legacy envelope composition behavior.');

// A validly digested page plan prepared from a divergent artifact must not
// silently collide with (and get dedupe-renamed against) the shared files.
$collidingArtifact = $artifact;
$collidingArtifact['files'][0]['metadata'] = array('compilation' => array('scope' => 'page', 'id' => 'about.html'));
$collidingPage = $compiler->preparePage($collidingArtifact, $shared, 'about.html');
$throws(static fn() => $compiler->compose($shared, array($collidingPage)), 'Composition rejects staged plans that collide on an artifact path.');

// References carry only portable identity metadata. The reader is injected by
// the consumer, keeping the compiler independent of the backing store.
$referencedArtifact = $artifact;
$payloads = array();
foreach ($referencedArtifact['files'] as &$file) {
    $content = is_string($file['content_base64'] ?? null) ? base64_decode($file['content_base64'], true) : $file['content'];
    $id = 'payload:' . $file['path'];
    $payloads[$id] = $content;
    unset($file['content'], $file['content_base64']);
    $file['payload_reference'] = array('schema' => 'blocks-engine/payload-reference/v1', 'id' => $id, 'bytes' => strlen($content), 'sha256' => hash('sha256', $content));
}
unset($file);
$reader = new class($payloads) implements PayloadReader {
    public array $reads = array();
    public function __construct(private array $payloads) {}
    public function read(array $reference): string { $this->reads[] = $reference['id']; if (!isset($this->payloads[$reference['id']])) throw new InvalidArgumentException('missing'); return $this->payloads[$reference['id']]; }
};
$referencedShared = $compiler->prepareShared($referencedArtifact, $reader);
$assert(!in_array('payload:assets/logo.png', $reader->reads, true) && 5 === count($reader->reads), 'Shared preparation establishes the digest-bound canonical text source catalog while retaining binary references.');
$reader->reads = array();
$referencedPages = array();
foreach ($pageIds as $pageId) $referencedPages[] = $compiler->preparePage($referencedArtifact, $referencedShared, $pageId, $reader);
$assert(7 === count($reader->reads) && in_array('payload:assets/site.css', $reader->reads, true), 'Page reference preparation hydrates only selected-page and required shared text inputs.');
$assert(!isset($referencedShared['artifact']['files'][0]['content']) && isset($referencedShared['artifact']['files'][0]['payload_reference']), 'Prepared reference plans remain serializable without hydrated payload bytes.');
$reader->reads = array();
$referencedResult = $compiler->compose($referencedShared, array_reverse($referencedPages), $reader)->toArray();
$assert(($whole['blocks'] ?? array()) === ($referencedResult['blocks'] ?? array()) && ($whole['serialized_blocks'] ?? '') === ($referencedResult['serialized_blocks'] ?? ''), 'Referenced staged compilation preserves the text compilation output of inline compilation.');
$inlineWrites = array_column($whole['source_reports']['wordpress_site_plan']['writes'] ?? array(), null, 'target_path');
$referencePlan = $referencedResult['source_reports']['wordpress_site_plan'] ?? array();
$referenceAssets = array_column($referencePlan['assets'] ?? array(), null, 'source_path');
$referenceWrites = array_column($referencePlan['writes'] ?? array(), null, 'target_path');
$binary = $payloads['payload:assets/logo.png'];
$inlineAsset = $siteAssets['assets/logo.png'];
$referenceAsset = $referenceAssets['assets/logo.png'];
$inlineMaterialization = array_column($whole['source_reports']['materialization_plan']['assets'] ?? array(), null, 'path')['assets/logo.png'] ?? array();
$referenceMaterialization = array_column($referencedResult['source_reports']['materialization_plan']['assets'] ?? array(), null, 'path')['assets/logo.png'] ?? array();
$assert('base64' === ($inlineWrites['assets/assets/logo.png']['payload']['encoding'] ?? null) && base64_encode($binary) === ($inlineWrites['assets/assets/logo.png']['payload']['data'] ?? null), 'Inline binary compilation retains the existing base64 write transport.');
$assert(array('source_path' => $inlineAsset['source_path'], 'target_path' => $inlineAsset['target_path'], 'token' => $inlineAsset['token'], 'bytes' => $inlineAsset['bytes'], 'binary' => $inlineAsset['binary'], 'raw_sha256' => $inlineAsset['raw_sha256']) === array('source_path' => $referenceAsset['source_path'], 'target_path' => $referenceAsset['target_path'], 'token' => $referenceAsset['token'], 'bytes' => $referenceAsset['bytes'], 'binary' => $referenceAsset['binary'], 'raw_sha256' => $referenceAsset['raw_sha256']) && hash('sha256', base64_encode($binary)) === ($inlineAsset['transport_sha256'] ?? null) && !isset($referenceAsset['transport_sha256'], $referenceAsset['content_base64']) && ($referenceAsset['payload_reference']['id'] ?? null) === 'payload:assets/logo.png' && hash('sha256', $binary) === ($referenceAsset['content_hash'] ?? null), 'Inline and referenced binaries retain identical semantic identity and raw digest; representation intentionally differs only as canonical base64 transport versus raw-byte reference.');
$assert('reference' === ($referenceWrites['assets/assets/logo.png']['payload']['encoding'] ?? null) && ($referenceWrites['assets/assets/logo.png']['payload']['reference']['id'] ?? null) === 'payload:assets/logo.png' && hash('sha256', $binary) === ($referenceWrites['assets/assets/logo.png']['raw_sha256'] ?? null), 'Referenced binary assets survive to matching materialization writes with their raw-byte SHA.');
$assert(($referenceMaterialization['payload_reference']['id'] ?? null) === 'payload:assets/logo.png' && hash('sha256', $binary) === ($referenceMaterialization['raw_sha256'] ?? null) && !isset($referenceMaterialization['content_base64'], $referenceMaterialization['transport_sha256']) && base64_encode($binary) === ($inlineMaterialization['content_base64'] ?? null) && hash('sha256', base64_encode($binary)) === ($inlineMaterialization['transport_sha256'] ?? null), 'Materialization plans preserve reference identity and raw digest while inline transport retains its canonical base64 digest.');
$svgReferencePlan = $referencePlan;
$svgReferencePlan['assets'][array_search('assets/logo.png', array_column($svgReferencePlan['assets'], 'source_path'), true)]['mime_type'] = 'image/svg+xml';
$throws(static fn() => WordPressSitePlan::assertValid($svgReferencePlan), 'WordPress plan validation rejects reference-backed SVG assets because SVG payloads must be hydrated for publication safety.');
$mismatchedReferencePlan = $referencePlan;
$mismatchedReferencePlan['writes'][array_search('assets/assets/logo.png', array_column($mismatchedReferencePlan['writes'], 'target_path'), true)]['raw_sha256'] = str_repeat('0', 64);
$throws(static fn() => WordPressSitePlan::assertValid($mismatchedReferencePlan), 'WordPress plan validation rejects reference writes whose raw hash does not match the declared asset reference.');
$assert(5 === count($reader->reads) && !in_array('payload:assets/logo.png', $reader->reads, true), 'Composition lazily reads text payloads while preserving binary references.');
$reader->reads = array();
$referencedReceipts = array();
foreach ($referencedPages as $referencedPage) $referencedReceipts[] = (new ArtifactCompiler())->compilePreparedPage($referencedShared, $referencedPage, $reader);
$pagePayloadsRemoved = new class implements PayloadReader { public array $reads = array(); public function read(array $reference): string { $this->reads[] = $reference['id']; throw new InvalidArgumentException('page payload access is forbidden at terminal assembly'); } };
$receiptResult = (new ArtifactCompiler())->compose($referencedShared, array_reverse($referencedReceipts), $pagePayloadsRemoved)->toArray();
$assert(array() === $pagePayloadsRemoved->reads && $canonical($referencedResult) === $canonical($receiptResult), 'Compiled receipts compose with a reader that rejects every payload access and preserve the complete canonical result.');
$assert($referencedShared['digest'] === $compiler->prepareShared($referencedArtifact, new class($payloads) implements PayloadReader { public function __construct(private array $payloads) {} public function read(array $reference): string { return $this->payloads[$reference['id']]; } } )['digest'], 'Reference-backed shared plan digests are deterministic.');
$pageOnlyArtifact = array('entrypoint' => 'index.html', 'files' => array(array('path' => 'index.html', 'payload_reference' => $referencedArtifact['files'][4]['payload_reference'])));
$pageOnlyShared = $compiler->prepareShared($pageOnlyArtifact, $reader);
$pageOnlyShared = json_decode(json_encode($pageOnlyShared, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);
$pageOnlyPage = $compiler->preparePage($pageOnlyArtifact, $pageOnlyShared, 'index.html', $reader);
$assert(isset($pageOnlyPage['artifact']['files'][0]['payload_reference']), 'A serialized empty shared reference partition remains valid for a page-only artifact.');
$corrupt = new class($payloads) implements PayloadReader { public function __construct(private array $payloads) {} public function read(array $reference): string { return 'corrupt'; } };
$throws(static fn() => $compiler->prepareShared($referencedArtifact, $corrupt), 'Reference preparation rejects corrupt payload bytes and sha256.');
$missing = new class implements PayloadReader { public function read(array $reference): string { throw new InvalidArgumentException('missing'); } };
$throws(static fn() => $compiler->compose($referencedShared, $referencedPages, $missing), 'Composition rejects missing referenced payloads.');

// File-level entrypoint and role metadata are normalized whole-artifact
// semantics. Staging must retain them even when the entry is page-owned.
$assertReceiptEquality = static function (array $artifact, string $message) use ($assert, $canonical): void {
    $inline = (new ArtifactCompiler())->compile($artifact)->toArray();
    $shared = (new ArtifactCompiler())->prepareShared($artifact);
    $receipts = array();
    foreach ($shared['analysis']['page_ids'] as $pageId) $receipts[] = (new ArtifactCompiler())->compilePage($artifact, $shared, $pageId);
    $staged = (new ArtifactCompiler())->compose($shared, array_reverse($receipts))->toArray();
    $expected = $canonical($inline);
    $actual = $canonical($staged);
    $firstDifference = static function (mixed $left, mixed $right, string $path = '') use (&$firstDifference): string {
        if (!is_array($left) || !is_array($right)) return $left === $right ? '' : $path;
        foreach (array_unique(array_merge(array_keys($left), array_keys($right))) as $key) {
            if (!array_key_exists($key, $left) || !array_key_exists($key, $right)) return $path . '/' . $key;
            $difference = $firstDifference($left[$key], $right[$key], $path . '/' . $key);
            if ('' !== $difference) return $difference;
        }
        return '';
    };
    $assert($expected === $actual, $message . ' First difference: ' . $firstDifference($expected, $actual));
    $assert(0 === ($staged['metrics']['html_document_transform_count'] ?? null) && 0 === ($staged['metrics']['normalization_count'] ?? null) && 0 === ($staged['metrics']['analysis_count'] ?? null), $message . ' Terminal composition performs no page work.');
};
$fileEntrypointArtifact = array('files' => array(
    array('path' => 'index.html', 'content' => '<main><h1>Non-entry</h1></main>', 'role' => 'page'),
    array('path' => 'landing.html', 'content' => '<main><h1>Selected entry</h1></main>', 'entrypoint' => true, 'role' => 'entry'),
));
$assertReceiptEquality($fileEntrypointArtifact, 'File-level entrypoint and role selection preserve the exact complete inline result through staged receipts.');
$responsiveShell = static fn(string $title): string => '<div class="desktop-document"><header class="desktop-header">Desktop header</header><main><h1>' . $title . '</h1></main><footer class="desktop-footer">Desktop footer</footer></div><div class="mobile-document"><header class="mobile-header">Mobile header</header><main><h1>' . $title . ' mobile</h1></main><footer class="mobile-footer">Mobile footer</footer></div>';
$responsiveShellArtifact = array('entrypoint' => 'index.html', 'files' => array(
    'index.html' => $responsiveShell('Home'),
    'about.html' => $responsiveShell('About'),
));
$assertReceiptEquality($responsiveShellArtifact, 'Shared responsive shell variants are compiled into durable receipts before terminal composition.');

$dialogHtml = '<div role="dialog" aria-label="Contact"><p>Captured dialog</p></div>';
$capturedStates = array(
    'schema' => 'data-liberation/captured-interactions/v1',
    'pages' => array(array(
        'sourceUrl' => 'https://example.test/',
        'states' => array(array(
            'status' => 'captured',
            'trigger' => array('selector' => 'body > main > a', 'tag' => 'a', 'ariaHaspopup' => 'dialog', 'dataBindings' => array('data-popupid' => 'contact')),
            'dialog' => array('html' => $dialogHtml, 'htmlBytes' => strlen($dialogHtml), 'htmlTruncated' => false),
        )),
    )),
);
$capturedDialogArtifact = array('site_slug' => 'staged-dialog', 'files' => array(
    array('path' => 'website/index.html', 'content' => '<main><a role="button" aria-haspopup="dialog" data-popupid="contact">Contact</a></main>', 'entrypoint' => true),
    array('path' => 'capture-receipt.json', 'content' => json_encode(array('schema' => 'data-liberation/capture-receipt/v1', 'routes' => array(array('url' => 'https://example.test/', 'path' => 'website/index.html'))), JSON_UNESCAPED_SLASHES)),
    array('path' => 'interaction-states.json', 'content' => json_encode($capturedStates, JSON_UNESCAPED_SLASHES)),
));
$assertReceiptEquality($capturedDialogArtifact, 'Digest-bound captured-dialog projection preserves blocks, diagnostics, interaction reports, and complete canonical output through staged receipts.');

$assertReferenceReceiptEquality = static function (array $artifact, string $message) use ($assert, $canonical): void {
    $inline = (new ArtifactCompiler())->compile($artifact)->toArray();
    $payloads = array();
    foreach ($artifact['files'] as &$file) {
        $id = 'reference:' . $file['path'];
        $payloads[$id] = $file['content'];
        unset($file['content']);
        $file['payload_reference'] = array('schema' => 'blocks-engine/payload-reference/v1', 'id' => $id, 'bytes' => strlen($payloads[$id]), 'sha256' => hash('sha256', $payloads[$id]));
    }
    unset($file);
    $reader = new class($payloads) implements PayloadReader { public function __construct(private array $payloads) {} public function read(array $reference): string { return $this->payloads[$reference['id']] ?? throw new InvalidArgumentException('missing'); } };
    $shared = (new ArtifactCompiler())->prepareShared($artifact, $reader);
    $receipts = array();
    foreach ($shared['analysis']['page_ids'] as $pageId) $receipts[] = (new ArtifactCompiler())->compilePage($artifact, $shared, $pageId, $reader);
    $terminalReader = new class implements PayloadReader { public int $reads = 0; public function read(array $reference): string { ++$this->reads; throw new InvalidArgumentException('terminal payload access'); } };
    $staged = (new ArtifactCompiler())->compose($shared, array_reverse($receipts), $terminalReader)->toArray();
    $assert(0 === $terminalReader->reads && $canonical($inline) === $canonical($staged), $message);
    $assert(0 === ($staged['metrics']['html_document_transform_count'] ?? null) && 0 === ($staged['metrics']['normalization_count'] ?? null) && 0 === ($staged['metrics']['analysis_count'] ?? null), $message . ' Terminal composition performs no reads or work.');
};
$assertReferenceReceiptEquality($capturedDialogArtifact, 'Fully reference-backed captured dialogs preserve the exact complete canonical result.');
$assertReferenceReceiptEquality(array('entrypoint' => 'index.html', 'files' => array(
    array('path' => 'index.html', 'content' => $responsiveShell('Home')),
    array('path' => 'about.html', 'content' => $responsiveShell('About')),
)), 'Fully reference-backed shared responsive shell variants compose without terminal reads or work.');
$duplicateStylesheetArtifact = array('entrypoint' => 'index.html', 'files' => array(
    array('path' => 'index.html', 'content' => '<link rel="stylesheet" href="assets/site.css"><link rel="stylesheet" href="assets/site.css"><main class="card">Duplicate stylesheet</main>'),
    array('path' => 'assets/site.css', 'content' => '.card{color:#123}', 'metadata' => array('compilation' => array('scope' => 'shared'))),
));
$assertReferenceReceiptEquality($duplicateStylesheetArtifact, 'Fully reference-backed duplicate linked stylesheets preserve canonical pre-occurrence identity and complete output.');
$sourceOrderArtifact = array('entrypoint' => 'index.html', 'files' => array(
    array('path' => 'index.html', 'content' => '<main>Home</main>'),
    array('path' => 'z.mdx', 'content' => "import Widget from './Widget'\n\n# Z"),
    array('path' => 'a.mdx', 'content' => "import Widget from './Widget'\n\n# A"),
));
$assertReceiptEquality($sourceOrderArtifact, 'Source-document diagnostics retain original index,z.mdx,a.mdx order through receipt composition.');
$sameCompiler = new ArtifactCompiler();
$sameCompiler->compile($artifact);
$sameInstanceTerminal = $sameCompiler->compose($shared, array_reverse($compiledPages))->toArray();
$assert(0 === ($sameInstanceTerminal['metrics']['html_document_transform_count'] ?? null) && 0 === ($sameInstanceTerminal['metrics']['normalization_count'] ?? null) && 0 === ($sameInstanceTerminal['metrics']['analysis_count'] ?? null), 'Compose resets inherited process-observability counters after prior compiler work.');

$fiftyReferencePayloads = array('shared-css' => '.page{color:#123}');
$fiftyReferenceFiles = array(array('path' => 'assets/site.css', 'payload_reference' => array('schema' => 'blocks-engine/payload-reference/v1', 'id' => 'shared-css', 'bytes' => strlen($fiftyReferencePayloads['shared-css']), 'sha256' => hash('sha256', $fiftyReferencePayloads['shared-css'])), 'metadata' => array('compilation' => array('scope' => 'shared'))));
for ($index = 0; $index < 50; ++$index) {
    $path = sprintf('pages/page-%02d.html', $index);
    $content = '<link rel="stylesheet" href="../assets/site.css"><main class="page">Page ' . $index . '</main>';
    $id = 'page-' . $index;
    $fiftyReferencePayloads[$id] = $content;
    $fiftyReferenceFiles[] = array('path' => $path, 'payload_reference' => array('schema' => 'blocks-engine/payload-reference/v1', 'id' => $id, 'bytes' => strlen($content), 'sha256' => hash('sha256', $content)));
}
$fiftyReferenceFiles[1]['path'] = 'index.html';
$fiftyReferenceArtifact = array('entrypoint' => 'index.html', 'files' => $fiftyReferenceFiles);
$boundedReader = new class($fiftyReferencePayloads) implements PayloadReader { public array $reads = array(); public function __construct(private array $payloads) {} public function read(array $reference): string { $this->reads[] = $reference['id']; return $this->payloads[$reference['id']] ?? throw new InvalidArgumentException('missing'); } };
$fiftyShared = (new ArtifactCompiler())->prepareShared($fiftyReferenceArtifact, $boundedReader);
$boundedReader->reads = array();
$selectedPlan = (new ArtifactCompiler())->preparePage($fiftyReferenceArtifact, $fiftyShared, 'pages/page-24.html', $boundedReader);
$assert(array('shared-css', 'page-24') === $boundedReader->reads, 'A 50-page reference-backed page preparation reads only its selected page plus bounded shared inputs.');
$boundedReader->reads = array();
$selectedReceipt = (new ArtifactCompiler())->compilePreparedPage($fiftyShared, $selectedPlan, $boundedReader);
$assert(array('page-24') === $boundedReader->reads, 'A reference-backed page worker reads only its selected page after shared catalog preparation.');
$terminalNoRead = new class implements PayloadReader { public int $reads = 0; public function read(array $reference): string { ++$this->reads; throw new InvalidArgumentException('terminal read'); } };
$throws(static fn() => (new ArtifactCompiler())->compose($fiftyShared, array($selectedReceipt), $terminalNoRead), 'Incomplete 50-page receipt composition fails before terminal payload access.');
$assert(0 === $terminalNoRead->reads, 'Incomplete terminal composition performs zero payload reads.');
$oversizedReads = new class implements PayloadReader { public int $reads = 0; public function read(array $reference): string { ++$this->reads; return ''; } };
$oversizedReference = array('entrypoint' => 'index.html', 'files' => array(array('path' => 'index.html', 'payload_reference' => array('schema' => 'blocks-engine/payload-reference/v1', 'id' => 'oversized', 'bytes' => ArtifactNormalizer::DEFAULT_MAX_FILE_BYTES + 1, 'sha256' => hash('sha256', 'oversized')))));
$throws(static fn() => (new ArtifactCompiler())->prepareShared($oversizedReference, $oversizedReads), 'Oversized declared references are rejected before payload hydration.');
$assert(0 === $oversizedReads->reads, 'Oversized declared references invoke no payload reader calls.');

$deepHtml = '<p>Deep receipt leaf</p>';
for ($depth = 0; $depth < 40; ++$depth) $deepHtml = '<div class="depth-' . $depth . '">' . $deepHtml . '</div>';
$deepArtifact = array('entrypoint' => 'index.html', 'files' => array(array('path' => 'index.html', 'content' => $deepHtml)));
$deepCompiler = new ArtifactCompiler();
$deepShared = $deepCompiler->prepareShared($deepArtifact);
$deepReceipt = $deepCompiler->compilePage($deepArtifact, $deepShared, 'index.html');
$deepStaged = $deepCompiler->compose($deepShared, array($deepReceipt))->toArray();
$deepWhole = $deepCompiler->compile($deepArtifact)->toArray();
$assert(($deepWhole['source_reports']['wordpress_site_plan'] ?? null) === ($deepStaged['source_reports']['wordpress_site_plan'] ?? null), 'Compiled receipt digests support deeply nested canonical block trees without weakening runtime declaration depth limits.');

$svgMediaChain = '<svg viewBox="0 0 10 10" aria-label="Mark"><path d="M0 0h10v10H0z"/></svg>';
for ($depth = 0; $depth < 44; ++$depth) $svgMediaChain = '<div class="depth-' . $depth . '">' . $svgMediaChain . '</div>';
$wowMediaChain = '<wow-image class="captured-media"><img src="hero.jpg" alt="Hero"></wow-image>';
for ($depth = 0; $depth < 39; ++$depth) $wowMediaChain = '<div class="depth-' . $depth . '">' . $wowMediaChain . '</div>';
$deepMediaArtifact = array('entrypoint' => 'index.html', 'files' => array(
    array('path' => 'index.html', 'content' => $svgMediaChain),
    array('path' => 'post.html', 'content' => $wowMediaChain),
));
$deepMediaCompiler = new ArtifactCompiler();
$deepMediaWhole = $deepMediaCompiler->compile($deepMediaArtifact)->toArray();
$deepMediaShared = $deepMediaCompiler->prepareShared($deepMediaArtifact);
$deepMediaReceipts = array();
foreach ($deepMediaShared['analysis']['page_ids'] as $pageId) $deepMediaReceipts[] = $deepMediaCompiler->compilePage($deepMediaArtifact, $deepMediaShared, $pageId);
$deepMediaStaged = $deepMediaCompiler->compose($deepMediaShared, array_reverse($deepMediaReceipts))->toArray();
$assert('passed' === ($deepMediaWhole['source_reports']['editability_policy']['status'] ?? null) && 20 >= ($deepMediaWhole['source_reports']['editability_report']['metrics']['max_nesting_depth'] ?? PHP_INT_MAX), 'Depth-44 SVG and depth-39 custom media routes satisfy the unchanged editability nesting policy.');
$assert($canonical($deepMediaWhole) === $canonical($deepMediaStaged), 'Deep media wrapper coalescing preserves direct and staged canonical equivalence.');

$layoutBoundary = '<main class="story">';
for ($depth = 0; $depth < 24; ++$depth) $layoutBoundary .= '<div class="layer-' . $depth . '">';
$layoutBoundary .= '<a href="/story" aria-label="Story"><img src="story.jpg" alt="Story"></a>';
for ($depth = 0; $depth < 24; ++$depth) $layoutBoundary .= '</div>';
$layoutBoundary .= '</main>';
$layoutArtifact = array('entrypoint' => 'index.html', 'files' => array(array('path' => 'index.html', 'content' => $layoutBoundary)));
$layoutCompiler = new ArtifactCompiler();
$layoutWhole = $layoutCompiler->compile($layoutArtifact)->toArray();
$layoutShared = $layoutCompiler->prepareShared($layoutArtifact);
$layoutStaged = $layoutCompiler->compose($layoutShared, array($layoutCompiler->compilePage($layoutArtifact, $layoutShared, 'index.html')))->toArray();
$layoutPage = $layoutWhole['source_reports']['compiled_site']['pages'][0] ?? array();
$assert('passed' === ($layoutWhole['source_reports']['editability_policy']['status'] ?? null) && str_contains((string) ($layoutPage['block_markup'] ?? ''), '<!-- wp:custom/responsive-layout {"content":'), 'A deep semantic media main compiles as one dedicated typed layout boundary under the unchanged editability policy.');
$assert($canonical($layoutWhole) === $canonical($layoutStaged), 'Typed captured layout boundaries preserve direct and staged canonical equivalence.');

fwrite(STDOUT, sprintf("Staged artifact compilation contract passed (50-page receipts: %d compact / %d expanded bytes; large receipt: %d compact / %d expanded bytes)\n", $compactReceiptBytes, $expandedReceiptBytes, $largeCompactBytes, $largeExpandedBytes));
