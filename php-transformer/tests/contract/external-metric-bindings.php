<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\RuntimeDeclarations;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\WordPressSitePlan;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\WordPressSitePlanResolver;

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    ++$assertions;
    if (!$condition) throw new RuntimeException($message);
};
$throws = static function (callable $callback, string $message) use ($assert): void {
    try { $callback(); } catch (InvalidArgumentException) { $assert(true, $message); return; }
    $assert(false, $message);
};
$html = '<!doctype html><html><body><main><div class="plugin-row"><span class="icon" aria-hidden="true">★</span><p>73,000+</p><h2>1.2.3</h2><a href="https://wordpress.org/plugins/block-visibility/">Plugin</a></div></main></body></html>';
$plain = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array('index.html' => $html)))->toArray();
$plainPlan = $plain['source_reports']['wordpress_site_plan'];
$plainMarkup = $plainPlan['pages'][0]['canonical_block_markup'];
$paragraph = '<!-- wp:paragraph --><p>73,000+</p><!-- /wp:paragraph -->';
$heading = '<!-- wp:heading {"level":2} --><h2 class="wp-block-heading">1.2.3</h2><!-- /wp:heading -->';
$assert(str_contains($plainMarkup, $paragraph) && str_contains($plainMarkup, $heading), 'source numeric fallbacks lower to native editable Paragraph and Heading blocks');

$fact = static function (string $id, string $metric, string $aggregation, string $fallback, string $markup, string $role, string $block, array $provenance, array $slugs = array('block-visibility')): array {
    return array(
        'id' => $id,
        'provider' => array('schema' => 'generic/external-metric-provider/v1', 'id' => 'wordpress.org', 'source' => 'plugin_information', 'slugs' => $slugs),
        'metric' => $metric,
        'aggregation' => $aggregation,
        'format' => array('locale' => 'en-US', 'grouping' => str_contains($fallback, ','), 'prefix' => 'version' === $metric ? 'v' : '', 'suffix' => 'active_installs' === $metric ? '+' : '', 'decimals' => 0),
        'provenance' => $provenance,
        'fallback' => array('text' => $fallback, 'hash' => hash('sha256', $fallback)),
        'bindings' => array(array('schema' => 'generic/block-binding/v1', 'role' => $role, 'source_path' => 'index.html', 'search_block_markup' => $markup, 'occurrence' => 1, 'leaf' => array('block' => $block, 'attribute' => 'content'))),
    );
};
$sourceProof = array('kind' => 'source_corroboration', 'repository' => 'ndiego/nickdiego.com', 'revision' => '5747c794bbbd0d2b2dfeb999210ab5d4f2e6a3fc', 'source_path' => 'src/components/wp-plugin-card.tsx');
$operatorMapping = array('kind' => 'operator_mapping', 'author' => 'operator:chubes4', 'source_relationship' => 'Maps the visible card version leaf to the source-backed plugin_information.version field; this relationship is operator-authored, not capture-observed.');
$declaration = array('kind' => 'entity_collection', 'type' => 'external_metrics', 'source_path' => 'data/external-metrics.json', 'payload' => array('schema' => 'generic/external-metric/v1', 'entities' => array(
    $fact('nick-projects-five-plugin-installs', 'active_installs', 'sum', '73,000+', $paragraph, 'paragraph', 'core/paragraph', $sourceProof, array('block-visibility', 'icon-block', 'social-sharing-block', 'genesis-featured-page-advanced', 'genesis-columns-advanced')),
    $fact('block-visibility-version', 'version', 'identity', 'v1.2.3', $heading, 'heading', 'core/heading', $operatorMapping),
)));
$artifact = array('entrypoint' => 'index.html', 'runtime_declarations' => array($declaration), 'files' => array('index.html' => $html));
$whole = (new ArtifactCompiler())->compile($artifact)->toArray();
$plan = $whole['source_reports']['wordpress_site_plan'];
WordPressSitePlan::assertValid($plan);
$entityById = array_column($plan['runtime_declarations'][0]['payload']['entities'], null, 'id');
$resolved = (new WordPressSitePlanResolver())->resolve($plan, array('theme_uri' => 'https://example.test/theme'));
$resolvedEntities = array_column($resolved['runtime_declarations'][0]['payload']['entities'], null, 'id');
foreach (array('nick-projects-five-plugin-installs' => $paragraph, 'block-visibility-version' => $heading) as $id => $expectedAnchor) {
    $entity = $entityById[$id] ?? array();
    $binding = $entity['bindings'][0] ?? array();
    $assert($expectedAnchor === ($binding['search_block_markup'] ?? null), "{$id} retains the exact native serialized text-leaf anchor");
    $resolvedBinding = $resolvedEntities[$id]['bindings'][0] ?? array();
    $assert(WordPressSitePlan::bindingPosition($resolvedBinding['position'] ?? null, $resolved['pages'][0]['resolved_block_markup'], $expectedAnchor), "{$id} receives a valid resolved document position");
    $assert(hash('sha256', $entity['fallback']['text'] ?? '') === ($entity['fallback']['hash'] ?? null), "{$id} transports its captured fallback/hash");
}
$assert($plan['pages'][0]['canonical_block_markup'] === $plainMarkup, 'declaring metrics leaves captured Paragraph, Heading, icon, link and layout markup unchanged');
$assert('source_corroboration' === ($entityById['nick-projects-five-plugin-installs']['provenance']['kind'] ?? null) && 'operator_mapping' === ($entityById['block-visibility-version']['provenance']['kind'] ?? null), 'source provenance and explicitly operator-authored mapping survive compiler projection');
$assert($resolved['runtime_declarations'] === $plan['runtime_declarations'] && $resolvedEntities['block-visibility-version']['fallback'] === $entityById['block-visibility-version']['fallback'], 'approved resolution preserves identities, provenance, fallback and binding transport');
$jsonRoundTrip = json_decode(json_encode($resolved, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);
WordPressSitePlan::assertValid($jsonRoundTrip);
$assert($jsonRoundTrip['runtime_declarations'] === $resolved['runtime_declarations'], 'JSON serialization/reimport preserves canonical runtime binding data');

$compiler = new ArtifactCompiler();
$shared = $compiler->prepareShared($artifact);
$receipts = array();
foreach ($shared['analysis']['page_ids'] as $pageId) $receipts[] = $compiler->compilePage($artifact, $shared, $pageId);
$staged = $compiler->compose($shared, $receipts)->toArray();
$assert($staged['source_reports']['wordpress_site_plan'] === $plan, 'staged compilation preserves the full declaration and exact resolved native leaf bindings');

$shellHtml = '<!doctype html><html><body><header class="site-header"><p>73,000+</p><nav><a href="/">Home</a></nav></header><main><h1>Page content</h1></main></body></html>';
$shellFiles = array('index.html' => $shellHtml, 'about.html' => str_replace('Page content', 'About content', $shellHtml));
$shellPlain = (new ArtifactCompiler())->compile(array('entrypoints' => array('index.html', 'about.html'), 'files' => $shellFiles))->toArray();
$shellPage = array_column($shellPlain['source_reports']['compiled_site']['pages'], null, 'source_path')['index.html'];
preg_match('/<!-- wp:paragraph -->.*?<!-- \/wp:paragraph -->/', $shellPage['block_markup'], $shellMatches);
$shellAnchor = $shellMatches[0] ?? '';
$shellMetric = $fact('shared-plugin-installs', 'active_installs', 'sum', '73,000+', $shellAnchor, 'paragraph', 'core/paragraph', $sourceProof);
$shellMetric['bindings'][0]['source_path'] = 'index.html';
$shellArtifact = array('entrypoints' => array('index.html', 'about.html'), 'runtime_declarations' => array(array('kind' => 'entity_collection', 'type' => 'external_metrics', 'source_path' => 'data/external-metrics.json', 'payload' => array('schema' => 'generic/external-metric/v1', 'entities' => array($shellMetric)))), 'files' => $shellFiles);
$shellPlan = (new ArtifactCompiler())->compile($shellArtifact)->toArray()['source_reports']['wordpress_site_plan'];
WordPressSitePlan::assertValid($shellPlan);
$shellCompiler = new ArtifactCompiler();
$shellShared = $shellCompiler->prepareShared($shellArtifact);
$shellReceipts = array();
foreach ($shellShared['analysis']['page_ids'] as $pageId) $shellReceipts[] = $shellCompiler->compilePage($shellArtifact, $shellShared, $pageId);
$shellStaged = $shellCompiler->compose($shellShared, $shellReceipts)->toArray()['source_reports']['wordpress_site_plan'];
$assert($shellStaged === $shellPlan, 'staged compilation preserves shared-shell metric binding reanchoring byte-for-byte');
$shellResolved = (new WordPressSitePlanResolver())->resolve($shellPlan, array('theme_uri' => 'https://example.test/theme'));
$shellBound = $shellResolved['runtime_declarations'][0]['payload']['entities'][0]['bindings'][0] ?? array();
$shellParts = array_values(array_filter($shellPlan['template_parts'], static fn(array $part): bool => 'header' === ($part['area'] ?? null)));
$shellContainer = 1 === count($shellParts) ? $shellParts[0]['canonical_block_markup'] : ($shellResolved['pages'][0]['resolved_block_markup'] ?? '');
$assert(WordPressSitePlan::bindingPosition($shellBound['position'] ?? null, $shellContainer, $shellAnchor), 'shared-shell extraction safely reanchors or retains the native metric leaf in its owning document');
$assert(1 === count($shellParts) ? ($shellParts[0]['source_path'] ?? null) === ($shellBound['source_path'] ?? null) : 'index.html' === ($shellBound['source_path'] ?? null), 'shared-shell extraction updates the selector to the shared part or retains its page owner');
$assert('73,000+' === ($shellResolved['runtime_declarations'][0]['payload']['entities'][0]['fallback']['text'] ?? null), 'shared-shell analysis preserves the exact captured metric fallback');

$bad = $artifact; $bad['runtime_declarations'][0]['payload']['entities'][0]['bindings'][0]['source_path'] = '../outside.html';
$throws(static fn() => (new ArtifactCompiler())->compile($bad), 'unsafe source selectors fail at the artifact boundary');
$bad = $artifact; $bad['runtime_declarations'][0]['payload']['entities'][0]['bindings'][0]['search_block_markup'] = '<!-- wp:html --><p>73,000+</p><!-- /wp:html -->';
$throws(static function () use ($bad): void { $result = (new ArtifactCompiler())->compile($bad)->toArray(); $candidatePlan = $result['source_reports']['wordpress_site_plan'] ?? null; if (!is_array($candidatePlan)) throw new InvalidArgumentException('Compiler declined an opaque/detached metric anchor.'); (new WordPressSitePlanResolver())->resolve($candidatePlan, array('theme_uri' => 'https://example.test/theme')); }, 'opaque HTML bindings are rejected rather than replacing native editable text');
$bad = $artifact; $bad['runtime_declarations'][0]['payload']['entities'][0]['provenance'] = array('kind' => 'captured_html');
$throws(static fn() => (new ArtifactCompiler())->compile($bad), 'ambiguous capture-only source claims are rejected');
$bad = $artifact; $bad['runtime_declarations'][0]['payload']['entities'][1]['format']['prefix'] = '';
$throws(static fn() => (new ArtifactCompiler())->compile($bad), 'format/source contradictions are rejected');

echo 'External metric native binding contract passed: ' . $assertions . " assertions\n";
