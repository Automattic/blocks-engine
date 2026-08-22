<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
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

$compiledPages = array();
foreach ($pageIds as $pageId) $compiledPages[$pageId] = $compiler->compilePage($artifact, $shared, $pageId);
$assert(1 === ($compiledPages['about.html']['work']['compiled_document_count'] ?? null) && isset($compiledPages['about.html']['compiled_documents']['about.html']), 'A compiled page plan persists only its bounded page-owned document receipt.');

// Simulate interruption/resume and arbitrary parallel completion order.
$resumedShared = json_decode(json_encode($shared, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);
$resumedPages = json_decode(json_encode(array($pages['contact.html'], $pages['index.html'], $pages['about.html']), JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);
$staged = $compiler->compose($resumedShared, $resumedPages)->toArray();
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
$preparedPages = array();
foreach ($manyShared['analysis']['page_ids'] as $pageId) $preparedPages[$pageId] = $compiler->preparePage($manyArtifact, $manyShared, $pageId);
$serializedPages = json_decode(json_encode($preparedPages, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);
// Resume from serialized plans in fresh workers. Page compilation receives no
// source artifact, so a worker cannot normalize or retain all fifty pages.
$manyReceipts = array();
$initialWorker = new ArtifactCompiler();
foreach (array_slice(array_keys($serializedPages), 0, 25) as $pageId) $manyReceipts[] = $initialWorker->compilePreparedPage($serializedShared, $serializedPages[$pageId]);
unset($initialWorker, $manyArtifact, $preparedPages);
$resumedWorker = new ArtifactCompiler();
foreach (array_slice(array_keys($serializedPages), 25) as $pageId) $manyReceipts[] = $resumedWorker->compilePreparedPage($serializedShared, $serializedPages[$pageId]);
$serializedReceipts = json_decode(json_encode($manyReceipts, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);
$terminalWorker = new ArtifactCompiler();
$manyStaged = $terminalWorker->compose($serializedShared, array_reverse($serializedReceipts))->toArray();
$canonical = static function (mixed $value) use (&$canonical): mixed {
    if (!is_array($value)) return $value;
    unset($value['transform_duration_ms'], $value['compile_duration_ms'], $value['html_document_transform_count']);
    foreach ($value as $key => $item) $value[$key] = $canonical($item);
    return $value;
};
$assert(0 === ($manyStaged['metrics']['html_document_transform_count'] ?? null), 'Terminal aggregation performs no HTML document transformations after independently serialized page receipts are complete.');
$assert(0 === ($manyStaged['metrics']['normalization_count'] ?? null) && 0 === ($manyStaged['metrics']['analysis_count'] ?? null) && 0 === ($serializedReceipts[0]['work']['normalization_count'] ?? null), 'Serialized page workers and terminal aggregation expose bounded normalization and analysis work, not only HTML transform counts.');
$assert(50 === ($manyInline['metrics']['html_document_transform_count'] ?? null), 'Inline compilation performs bounded work once per page document.');
$assert($canonical($manyInline['source_reports']['wordpress_site_plan'] ?? array()) === $canonical($manyStaged['source_reports']['wordpress_site_plan'] ?? array()) && $canonical($manyInline['diagnostics'] ?? array()) === $canonical($manyStaged['diagnostics'] ?? array()), 'Fifty-page arbitrary-order resume preserves canonical WordPress plans and diagnostics after observational fields are excluded.');
$throws(static fn() => $compiler->compose($manyShared, array_slice($serializedReceipts, 1)), 'Composition rejects a missing compiled page receipt deterministically.');
$sitePlan = $whole['source_reports']['wordpress_site_plan'] ?? array();
$siteAssets = array_column($sitePlan['assets'] ?? array(), null, 'source_path');
$siteWrites = array_column($sitePlan['writes'] ?? array(), null, 'target_path');
$bootstrap = (string) ($siteWrites['functions.php']['payload']['data'] ?? '');
$assert(array(array('kind' => 'global')) === ($siteAssets['assets/site.css']['scopes'] ?? null), 'Shared stylesheets retain an explicit global runtime scope.');
$assert('about.html' === ($siteAssets['assets/about.css']['scopes'][0]['source_path'] ?? null) && str_contains($bootstrap, "if ( is_page() && 'about' === trim( get_page_uri( get_queried_object_id() ), '/' ) ) wp_enqueue_style"), 'Page-owned stylesheets enqueue only on their canonical WordPress route.');
$assert('(min-width: 48rem)' === ($siteAssets['assets/about.css']['media'] ?? null) && str_contains($bootstrap, "array(), null, '(min-width: 48rem)'"), 'Stylesheet media conditions are retained as canonical frontend enqueue arguments.');
$assert(str_contains($bootstrap, "\$css = '@media ' . \$style['media'] . '{' . \$css . '}'"), 'Canonical editor styles preserve their stylesheet media conditions.');
$assert(str_contains($bootstrap, "add_filter( 'block_editor_settings_all'") && str_contains($bootstrap, "blocks-engine-presentation:") && str_contains($bootstrap, "get_theme_file_path( \$style['target_path'] )") && str_contains($bootstrap, "\$context->post") && str_contains($bootstrap, "get_page_uri( \$post )"), 'Canonical bootstrap loads content-addressed route styles into the edited post iframe.');
$inlineEntryArtifact = $inlineArtifact;
$inlineEntryArtifact['entrypoints'] = array('about.html');
$inlineSitePlan = $compiler->compile($inlineEntryArtifact)->toArray()['source_reports']['wordpress_site_plan'] ?? array();
$inlineAssets = array_column($inlineSitePlan['assets'] ?? array(), null, 'source_path');
$assert('about.html' === ($inlineAssets['about.inline.css']['scopes'][0]['source_path'] ?? null) && false === ($inlineAssets['about.inline.css']['scopes'][0]['front_page'] ?? null), 'Inferred inline stylesheet ownership follows its canonical non-root route even when that page is the compiler entrypoint.');
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

$throws(static fn() => $compiler->compose($shared, array($pages['index.html'], $pages['index.html'])), 'Composition rejects more than one page plan for the same page id.');

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
$assert(array('payload:assets/site.css') === $reader->reads, 'Shared reference preparation hydrates only text payloads in the shared partition.');
$reader->reads = array();
$referencedPages = array();
foreach ($pageIds as $pageId) $referencedPages[] = $compiler->preparePage($referencedArtifact, $referencedShared, $pageId, $reader);
$assert(4 === count($reader->reads) && !in_array('payload:assets/site.css', $reader->reads, true), 'Page reference preparation reads only requested page payloads.');
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
$assert(array() === $pagePayloadsRemoved->reads && ($referencedResult['blocks'] ?? array()) === ($receiptResult['blocks'] ?? array()) && ($referencedResult['serialized_blocks'] ?? '') === ($receiptResult['serialized_blocks'] ?? ''), 'Compiled receipts retain hydrated shared and page reductions, so terminal composition reads no page payloads.');
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

fwrite(STDOUT, "Staged artifact compilation contract passed\n");
