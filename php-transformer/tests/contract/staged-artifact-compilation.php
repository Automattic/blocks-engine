<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\PayloadReader;
use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\RuntimeDeclarations;

$assert = static function (bool $condition, string $message): void { if (!$condition) throw new RuntimeException($message); };
$throws = static function (callable $callback, string $message) use ($assert): void { try { $callback(); } catch (InvalidArgumentException) { return; } $assert(false, $message); };
$artifact = array(
    'entrypoints' => array('index.html'),
    'files' => array(
        array('path' => 'assets/site.css', 'content' => 'main{color:#123}', 'metadata' => array('compilation' => array('scope' => 'shared'))),
        array('path' => 'assets/about.css', 'content' => '.about-grid{display:grid;grid-template-columns:1fr 1fr}', 'metadata' => array('compilation' => array('scope' => 'page', 'id' => 'about.html'))),
        array('path' => 'about.html', 'content' => '<link rel="stylesheet" href="assets/site.css"><link rel="stylesheet" href="assets/about.css"><main class="about-grid"><h1>About</h1></main>'),
        array('path' => 'contact.html', 'content' => '<link rel="stylesheet" href="assets/site.css"><main><h1>Contact</h1></main>'),
        array('path' => 'index.html', 'content' => '<link rel="stylesheet" href="assets/site.css"><main><h1>Home</h1></main>'),
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
$assert(ArtifactCompiler::SHARED_PLAN_SCHEMA === $shared['schema'] && 1 === $shared['summary']['file_count'] && preg_match('/^[a-f0-9]{64}$/', $shared['digest']), 'Shared preparation emits a bounded immutable shared plan and digest.');
// Inline assets expanded out of an unannotated page follow that page, not the
// immutable shared plan: parking page-varying content in the shared plan would
// invalidate every page plan on a page edit.
$inlineArtifact = $artifact;
$inlineArtifact['files'][2]['content'] .= '<style>main{gap:1rem}</style><script>console.log("about");</script>';
$inlineShared = $compiler->prepareShared($inlineArtifact);
$assert(1 === $inlineShared['summary']['file_count'], 'Shared preparation excludes page-owned inline expansions.');
$assert(4 === $compiler->preparePage($inlineArtifact, $inlineShared, 'about.html')['summary']['file_count'], 'Page preparation owns explicit and inline page assets with the page html.');

$pageIds = array('index.html', 'about.html', 'contact.html');
$pages = array();
foreach ($pageIds as $pageId) $pages[$pageId] = $compiler->preparePage($artifact, $shared, $pageId);
$assert(array('index.html', 'about.html', 'contact.html') === array_keys($pages), 'Three independent page plans are addressable by canonical page ownership ids.');

// Simulate interruption/resume and arbitrary parallel completion order.
$resumedShared = json_decode(json_encode($shared, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);
$resumedPages = json_decode(json_encode(array($pages['contact.html'], $pages['index.html'], $pages['about.html']), JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);
$staged = $compiler->compose($resumedShared, $resumedPages)->toArray();
$whole = $compiler->compile($artifact)->toArray();
$assert(($whole['source_reports']['wordpress_site_plan'] ?? array()) === ($staged['source_reports']['wordpress_site_plan'] ?? array()), 'Whole and staged compilation yield byte-for-byte equivalent canonical site plans, including source-operation provenance and hashes.');
$assert(($whole['source_reports']['materialization_plan'] ?? array()) === ($staged['source_reports']['materialization_plan'] ?? array()), 'Whole and staged compilation yield byte-for-byte equivalent materialization receipts.');
$sitePlan = $whole['source_reports']['wordpress_site_plan'] ?? array();
$siteAssets = array_column($sitePlan['assets'] ?? array(), null, 'source_path');
$siteWrites = array_column($sitePlan['writes'] ?? array(), null, 'target_path');
$bootstrap = (string) ($siteWrites['functions.php']['payload']['data'] ?? '');
$assert(array(array('kind' => 'global')) === ($siteAssets['assets/site.css']['scopes'] ?? null), 'Shared stylesheets retain an explicit global runtime scope.');
$assert('about.html' === ($siteAssets['assets/about.css']['scopes'][0]['source_path'] ?? null) && str_contains($bootstrap, "if ( is_page() && 'about' === trim( get_page_uri( get_queried_object_id() ), '/' ) ) wp_enqueue_style"), 'Page-owned stylesheets enqueue only on their canonical WordPress route.');
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
    $content = $file['content'];
    $id = 'payload:' . $file['path'];
    $payloads[$id] = $content;
    unset($file['content']);
    $file['payload_reference'] = array('schema' => 'blocks-engine/payload-reference/v1', 'id' => $id, 'bytes' => strlen($content), 'sha256' => hash('sha256', $content));
}
unset($file);
$reader = new class($payloads) implements PayloadReader {
    public array $reads = array();
    public function __construct(private array $payloads) {}
    public function read(array $reference): string { $this->reads[] = $reference['id']; if (!isset($this->payloads[$reference['id']])) throw new InvalidArgumentException('missing'); return $this->payloads[$reference['id']]; }
};
$referencedShared = $compiler->prepareShared($referencedArtifact, $reader);
$assert(array('payload:assets/site.css') === $reader->reads, 'Shared reference preparation reads only the shared partition.');
$reader->reads = array();
$referencedPages = array();
foreach ($pageIds as $pageId) $referencedPages[] = $compiler->preparePage($referencedArtifact, $referencedShared, $pageId, $reader);
$assert(4 === count($reader->reads) && !in_array('payload:assets/site.css', $reader->reads, true), 'Page reference preparation reads only requested page payloads.');
$assert(!isset($referencedShared['artifact']['files'][0]['content']) && isset($referencedShared['artifact']['files'][0]['payload_reference']), 'Prepared reference plans remain serializable without hydrated payload bytes.');
$reader->reads = array();
$referencedResult = $compiler->compose($referencedShared, array_reverse($referencedPages), $reader)->toArray();
$assert(($whole['blocks'] ?? array()) === ($referencedResult['blocks'] ?? array()) && ($whole['serialized_blocks'] ?? '') === ($referencedResult['serialized_blocks'] ?? '') && ($whole['assets'] ?? array()) === ($referencedResult['assets'] ?? array()) && ($whole['source_reports']['wordpress_site_plan'] ?? array()) === ($referencedResult['source_reports']['wordpress_site_plan'] ?? array()) && ($whole['source_reports']['materialization_plan'] ?? array()) === ($referencedResult['source_reports']['materialization_plan'] ?? array()), 'Referenced staged compilation produces the same deterministic output as inline compilation.');
$assert(5 === count($reader->reads), 'Composition lazily reads each selected referenced payload once.');
$assert($referencedShared['digest'] === $compiler->prepareShared($referencedArtifact, new class($payloads) implements PayloadReader { public function __construct(private array $payloads) {} public function read(array $reference): string { return $this->payloads[$reference['id']]; } } )['digest'], 'Reference-backed shared plan digests are deterministic.');
$corrupt = new class($payloads) implements PayloadReader { public function __construct(private array $payloads) {} public function read(array $reference): string { return 'corrupt'; } };
$throws(static fn() => $compiler->prepareShared($referencedArtifact, $corrupt), 'Reference preparation rejects corrupt payload bytes and sha256.');
$missing = new class implements PayloadReader { public function read(array $reference): string { throw new InvalidArgumentException('missing'); } };
$throws(static fn() => $compiler->compose($referencedShared, $referencedPages, $missing), 'Composition rejects missing referenced payloads.');

fwrite(STDOUT, "Staged artifact compilation contract passed\n");
