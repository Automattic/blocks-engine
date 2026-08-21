<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';
require dirname(__DIR__, 3) . '/figma-transformer/figma-transformer.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\Contract\TransformerResult;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\WordPressSitePlan;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\WordPressSitePlanResolver;

$assert = static function (bool $condition, string $message): void { if (!$condition) throw new RuntimeException($message); };
$surface = static fn(string $role, string $slug, string $variant = ''): array => array_filter(array('schema' => 'blocks-engine/template-surface/v1', 'role' => $role, 'slug' => $slug, 'logical_surface_id' => $role . ':' . $slug, 'responsive_variant_id' => $variant), static fn(mixed $value): bool => '' !== $value);
$artifact = array('entrypoint' => 'index.html', 'files' => array(
    array('path' => 'index.html', 'content' => '<main><h1>Home</h1></main>'),
    array('path' => 'single.html', 'content' => '<main><article><h1>Article</h1></article></main>', 'metadata' => array('template_surface' => $surface('single', 'single', 'desktop'))),
    array('path' => 'archive.html', 'content' => '<main><h1>News</h1></main>', 'metadata' => array('template_surface' => $surface('archive', 'archive', 'desktop'))),
    array('path' => 'not-found.html', 'content' => '<main><h1>Not found</h1></main>', 'metadata' => array('template_surface' => $surface('404', '404', 'desktop'))),
));
$result = (new ArtifactCompiler())->compile($artifact)->toArray();
$plan = $result['source_reports']['wordpress_site_plan'] ?? array();
$templates = array_column($plan['templates'] ?? array(), null, 'slug');
$operations = array_values(array_filter($plan['operations'] ?? array(), static fn(array $operation): bool => 'create_page' === ($operation['kind'] ?? null)));
$writes = array_column($plan['writes'] ?? array(), null, 'target_path');
$assert(isset($templates['single'], $templates['archive'], $templates['404'], $writes['templates/single.html'], $writes['templates/archive.html'], $writes['templates/404.html']) && 'single.html' === ($templates['single']['source_path'] ?? null) && 'archive.html' === ($templates['archive']['source_path'] ?? null) && '404' === ($templates['404']['template_surface']['role'] ?? null) && 'single.html' === ($templates['single']['template_surface']['source_provenance']['source_path'] ?? null), 'Typed template-surface declarations emit source-provenanced WordPress template writes.');
$assert(array('index.html') === array_column($operations, 'source_path') && isset($result['source_reports']['materialization_plan']['template_surfaces'][0]['template_surface']), 'Declared template surfaces are excluded from page operations and represented by the materialization plan.');
$assert(true === (static function () use ($plan): bool { WordPressSitePlan::assertValid($plan); return true; })(), 'Canonical site-plan validation accepts declared template surfaces.');
$tamperedPlan = $plan; $tamperedPlan['templates'][3]['template_surface']['slug'] = 'detached';
$throws = static function (callable $callback): bool { try { $callback(); } catch (InvalidArgumentException) { return true; } return false; };
$assert($throws(static fn() => WordPressSitePlan::assertValid($tamperedPlan)), 'Canonical site-plan validation rejects detached declared template surface metadata.');
$surfaceTemplateIndex = array_search('single', array_column($plan['templates'], 'slug'), true);
foreach (array(
    'logical_surface_id' => 'other:single',
    'responsive_variant_id' => '../unsafe',
    'declaration_provenance.source_path' => 'archive.html',
    'source_variants' => array(array('source_path' => 'archive.html', 'source_hash' => str_repeat('0', 64), 'source_provenance' => array('source_path' => 'archive.html', 'source' => 'files', 'hash' => str_repeat('0', 64)))),
    'selected_source_path' => 'archive.html',
    'selected_source_hash' => str_repeat('0', 64),
    'source_provenance.hash' => str_repeat('0', 64),
) as $field => $value) {
    $invalid = $plan;
    $target =& $invalid['templates'][$surfaceTemplateIndex]['template_surface'];
    foreach (explode('.', $field) as $segment) $target =& $target[$segment];
    $target = $value;
    unset($target);
    $assert($throws(static fn() => WordPressSitePlan::assertValid($invalid)), 'Canonical site-plan validation rejects tampered template surface ' . $field . '.');
}
$editabilityBySource = array_column($result['source_reports']['editability_report']['documents'] ?? array(), null, 'source_path');
$singleEvidence = $editabilityBySource['single.html']['template_surface_selection'] ?? array();
$assert('single' === ($singleEvidence['role'] ?? null) && 'single.html' === ($singleEvidence['selected_source_path'] ?? null) && 'artifact_metadata' === ($singleEvidence['declaration_provenance']['kind'] ?? null) && 'single.html' === ($singleEvidence['source_provenance']['source_path'] ?? null), 'Editability evidence records selected template role and declaration/source provenance.');
$materializedSurface = $result['source_reports']['materialization_plan']['template_surfaces'][0] ?? array();
$assert('single.html' === ($materializedSurface['provenance']['source_path'] ?? null), 'Materialization template surfaces retain source artifact provenance from page projection.');

$legoShape = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array('index.html' => '<main>Home</main>', 'news.html' => '<main data-template-type="archive" data-template-slug="archive"><h1>News</h1></main>')))->toArray();
$legoTemplates = array_column($legoShape['source_reports']['wordpress_site_plan']['templates'] ?? array(), null, 'slug');
$assert('news.html' === ($legoTemplates['archive']['source_path'] ?? null) && 'html_attributes' === ($legoTemplates['archive']['template_surface']['declaration_provenance']['kind'] ?? null), 'Generic HTML declaration intake preserves the LEGO attribute shape without producer-specific naming rules.');
$legoMulti = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array('index.html' => '<main data-page-title="Home">Home</main>', 'single.html' => '<main class="figma-root" data-template-type="single" data-template-slug="single"><h1>Article</h1></main>', 'archive.html' => '<main class="figma-root" data-template-type="archive" data-template-slug="archive"><h1>News</h1></main>', '404.html' => '<main class="figma-root" data-template-type="404" data-template-slug="404"><h1>Page not found</h1></main>')))->toArray();
$legoMultiPlan = $legoMulti['source_reports']['wordpress_site_plan'] ?? array();
$legoMultiWrites = array_column($legoMultiPlan['writes'] ?? array(), null, 'target_path');
$legoMultiOperations = array_values(array_filter($legoMultiPlan['operations'] ?? array(), static fn(array $operation): bool => 'create_page' === ($operation['kind'] ?? null)));
$assert(isset($legoMultiWrites['templates/single.html'], $legoMultiWrites['templates/archive.html'], $legoMultiWrites['templates/404.html']) && array('index.html') === array_column($legoMultiOperations, 'source_path'), 'LEGO-shaped multi-template HTML compiles through ArtifactCompiler into template writes without template page operations.');
$partialHtmlDeclaration = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array('index.html' => '<main>Home</main>', 'note.html' => '<main data-template-type="archive"><h1>Note</h1></main>')))->toArray()['source_reports']['wordpress_site_plan'] ?? array();
$assert(2 === count($partialHtmlDeclaration['pages'] ?? array()) && !array_filter($partialHtmlDeclaration['templates'] ?? array(), static fn(array $template): bool => 'note.html' === ($template['source_path'] ?? null)), 'Partial or incidental HTML attributes do not declare a template surface.');

$duplicate = $artifact;
$duplicate['files'][] = array('path' => 'single-mobile.html', 'content' => '<main><article><h1>Article</h1></article></main>', 'metadata' => array('template_surface' => $surface('single', 'single', 'mobile')));
$duplicatePlan = (new ArtifactCompiler())->compile($duplicate)->toArray()['source_reports']['wordpress_site_plan'] ?? array();
$duplicateTemplates = array_column($duplicatePlan['templates'] ?? array(), null, 'slug');
$variants = $duplicateTemplates['single']['template_surface']['source_variants'] ?? array();
$assert('single-mobile.html' === ($duplicateTemplates['single']['source_path'] ?? null) && array('single-mobile.html', 'single.html') === array_column($variants, 'source_path'), 'Equivalent responsive declarations reconcile deterministically with source-hash-bound variant records.');
$fabricatedVariant = $duplicatePlan; $fabricatedSurface =& $fabricatedVariant['templates'][array_search('single', array_column($duplicatePlan['templates'], 'slug'), true)]['template_surface']; $fabricatedSurface['source_variants'][] = array('source_path' => 'fabricated.html', 'source_hash' => str_repeat('f', 64), 'source_provenance' => array('source_path' => 'fabricated.html', 'source' => 'files', 'hash' => str_repeat('f', 64))); unset($fabricatedSurface);
$assert($throws(static fn() => WordPressSitePlan::assertValid($fabricatedVariant)), 'Canonical site-plan validation rejects a variant absent from the source evidence catalog.');
$forgedPlan = $duplicatePlan; $forgedSurface =& $forgedPlan['templates'][array_search('single', array_column($duplicatePlan['templates'], 'slug'), true)]['template_surface']; $forgedVariant = array('source_path' => 'variant.html', 'source_hash' => str_repeat('e', 64), 'source_provenance' => array('source_path' => 'variant.html', 'source' => 'files', 'hash' => str_repeat('e', 64))); $forgedSurface['source_variants'][] = $forgedVariant; unset($forgedSurface); $forgedPlan['source']['source_documents'][] = $forgedVariant;
$assert(true === (static function () use ($forgedPlan): bool { WordPressSitePlan::assertValid($forgedPlan); return true; })(), 'Standalone validation is structural when all variant evidence and catalog rows are consistently forged.');
$approvedHash = WordPressSitePlan::canonicalHash($duplicatePlan);
$assert($throws(static fn() => (new WordPressSitePlanResolver())->resolve($forgedPlan, array('theme_uri' => 'https://example.test/theme', 'approved_plan_hash' => $approvedHash))), 'Materialization rejects structurally valid variant/catalog mutations against the originally approved plan hash.');

$ambiguous = $duplicate;
$ambiguous['files'][4]['content'] = '<main><article><h1>Different article</h1></article></main>';
$ambiguousResult = (new ArtifactCompiler())->compile($ambiguous)->toArray();
$ambiguity = $ambiguousResult['source_reports']['wordpress_site_plan_diagnostics'][0] ?? array();
$assert(!isset($ambiguousResult['source_reports']['wordpress_site_plan']) && 'template_surface_ambiguous' === ($ambiguity['reason'] ?? null) && 'template_surface' === ($ambiguity['document_kind'] ?? null), 'Non-equivalent responsive declarations emit a structured ambiguity diagnostic instead of duplicate content records.');

$undeclared = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array('index.html' => '<main>Home</main>', 'article.html' => '<main><article><time datetime="2026-01-01">Date</time></article></main>')))->toArray()['source_reports']['wordpress_site_plan'] ?? array();
$assert(2 === count($undeclared['pages'] ?? array()) && 2 === count(array_filter($undeclared['operations'] ?? array(), static fn(array $operation): bool => 'create_page' === ($operation['kind'] ?? null))), 'Undeclared documents retain existing page/post materialization behavior.');
$legacyUndeclared = $undeclared; unset($legacyUndeclared['source']['source_documents']);
$assert(true === (static function () use ($legacyUndeclared): bool { WordPressSitePlan::assertValid($legacyUndeclared); return true; })(), 'The additive source document catalog preserves v2 validation compatibility for undeclared plans.');
$legacyMaterializationEnvelope = $result; unset($legacyMaterializationEnvelope['source_reports']['materialization_plan']['template_surfaces']);
$assert(true === (static function () use ($legacyMaterializationEnvelope): bool { TransformerResult::assertCanonicalEnvelope($legacyMaterializationEnvelope); return true; })(), 'Legacy materialization-plan v1 envelopes remain valid when template_surfaces is absent.');
$pageAttributes = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array('index.html' => '<main data-template-type="front_page" data-template-slug="front-page">Home</main>', 'about.html' => '<main data-template-type="page" data-template-slug="page">About</main>')))->toArray()['source_reports']['wordpress_site_plan'] ?? array();
$pageOperations = array_values(array_filter($pageAttributes['operations'] ?? array(), static fn(array $operation): bool => 'create_page' === ($operation['kind'] ?? null)));
$assert(2 === count($pageAttributes['pages'] ?? array()) && 2 === count($pageOperations) && 'index.html' === (($pageAttributes['operations'][2]['front_page_source_path'] ?? null)), 'HTML page and front_page attributes remain ordinary content pages with front-page operations.');
$figmaArtifact = blocks_engine_figma_transformer_transform_scenegraph(array('name' => 'Figma template bridge', 'nodes' => array(
    array('id' => 'home', 'type' => 'FRAME', 'name' => 'Home Page Desktop', 'width' => 1440, 'height' => 800, 'children' => array(array('id' => 'home-title', 'type' => 'TEXT', 'name' => 'Heading', 'text' => 'Home', 'fontSize' => 36))),
    array('id' => 'single', 'type' => 'FRAME', 'name' => 'Blog Post Desktop', 'width' => 1440, 'height' => 800, 'children' => array(array('id' => 'single-title', 'type' => 'TEXT', 'name' => 'Heading', 'text' => 'Article', 'fontSize' => 36))),
    array('id' => 'archive', 'type' => 'FRAME', 'name' => 'Archive Desktop', 'width' => 1440, 'height' => 800, 'children' => array(array('id' => 'archive-title', 'type' => 'TEXT', 'name' => 'Heading', 'text' => 'News', 'fontSize' => 36))),
    array('id' => 'not-found', 'type' => 'FRAME', 'name' => '404 Page Desktop', 'width' => 1440, 'height' => 800, 'children' => array(array('id' => 'not-found-title', 'type' => 'TEXT', 'name' => 'Heading', 'text' => 'Not found', 'fontSize' => 36))),
)), array('multi_page' => true, 'max_pages' => 10));
$figmaFiles = array_column($figmaArtifact['files'], null, 'path');
$assert('single' === ($figmaFiles['blog-post-desktop.html']['metadata']['template_surface']['role'] ?? null) && 'archive' === ($figmaFiles['archive-desktop.html']['metadata']['template_surface']['role'] ?? null) && '404' === ($figmaFiles['404-page-desktop.html']['metadata']['template_surface']['role'] ?? null) && !isset($figmaFiles['index.html']['metadata']['template_surface']), 'Figma named template source documents declare artifact metadata while Home remains content.');
$figmaPlan = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => $figmaArtifact['files']))->toArray()['source_reports']['wordpress_site_plan'] ?? array();
$figmaTemplates = array_column($figmaPlan['templates'] ?? array(), null, 'slug');
$figmaOperations = array_values(array_filter($figmaPlan['operations'] ?? array(), static fn(array $operation): bool => 'create_page' === ($operation['kind'] ?? null)));
$assert('blog-post-desktop.html' === ($figmaTemplates['single']['source_path'] ?? null) && 'archive-desktop.html' === ($figmaTemplates['archive']['source_path'] ?? null) && '404-page-desktop.html' === ($figmaTemplates['404']['source_path'] ?? null) && 1 === count($figmaOperations) && 'index.html' === ($figmaOperations[0]['source_path'] ?? null) && 'index.html' === (($figmaPlan['operations'][1]['front_page_source_path'] ?? null)), 'Figma Home Page remains a front-page content record while Figma single, archive, and 404 surfaces become templates.');

fwrite(STDOUT, "Template surface contract tests passed.\n");
