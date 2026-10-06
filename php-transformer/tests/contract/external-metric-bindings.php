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
$html = '<!doctype html><html><body><main><div class="plugin-row"><span class="icon" aria-hidden="true">★</span><p>73,000+</p><h2>v1.2.3</h2><a href="https://wordpress.org/plugins/block-visibility/">Plugin</a></div></main></body></html>';
$plain = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array('index.html' => $html)))->toArray();
$plainPlan = $plain['source_reports']['wordpress_site_plan'];
$plainMarkup = $plainPlan['pages'][0]['canonical_block_markup'];
$paragraph = '<!-- wp:paragraph --><p>73,000+</p><!-- /wp:paragraph -->';
$heading = '<!-- wp:heading {"level":2} --><h2 class="wp-block-heading">v1.2.3</h2><!-- /wp:heading -->';
$assert(str_contains($plainMarkup, $paragraph) && str_contains($plainMarkup, $heading), 'source numeric fallbacks lower to native editable Paragraph and Heading blocks');

$source = static function (string $id, string $urlTemplate, array $query, array $queryVariables, array $variableSchemas, array $resources, int $freshness, array $headers = array('Accept' => 'application/json')): array {
    return array(
        'schema' => 'generic/external-metric-source/v1', 'id' => $id, 'intent' => 'external_public_json',
        'request' => array('method' => 'GET', 'url_template' => $urlTemplate, 'query' => $query, 'query_variables' => $queryVariables, 'headers' => $headers, 'response_media_type' => 'application/json', 'max_response_bytes' => 1048576, 'timeout_seconds' => 5),
        'resource_variables' => $variableSchemas, 'resources' => $resources, 'freshness' => array('max_age_seconds' => $freshness),
    );
};
$pluginSource = static function (array $slugs) use ($source): array {
    $slugSchema = array('location' => 'query', 'min_length' => 1, 'max_length' => 100, 'allowed_characters' => 'abcdefghijklmnopqrstuvwxyz0123456789-', 'prohibited_values' => array());
    return $source('wordpress.org.plugin-information', 'https://api.wordpress.org/plugins/info/1.2/', array('action' => 'plugin_information'), array('slug'), array('slug' => $slugSchema), array_map(static fn(string $slug): array => array('slug' => $slug), $slugs), 3600);
};
$fact = static function (string $id, array $source, string $metric, ?array $extraction, string $aggregation, string $fallback, string $markup, string $role, string $block, array $provenance, ?array $format = null): array {
    $fact = array(
        'id' => $id,
        'source' => $source,
        'metric' => $metric,
        'aggregation' => $aggregation,
        'format' => $format ?? array('locale' => 'en-US', 'grouping' => str_contains($fallback, ','), 'prefix' => 'version' === $metric ? 'v' : '', 'suffix' => 'active_installs' === $metric ? '+' : '', 'decimals' => 0),
        'provenance' => $provenance,
        'fallback' => array('text' => $fallback, 'hash' => hash('sha256', $fallback)),
        'bindings' => array(array('schema' => 'generic/block-binding/v1', 'role' => $role, 'source_path' => 'index.html', 'search_block_markup' => $markup, 'occurrence' => 1, 'leaf' => array('block' => $block, 'attribute' => 'content'))),
    );
    if (null !== $extraction) $fact['extraction'] = $extraction;
    return $fact;
};
$sourceProof = array('kind' => 'source_corroboration', 'repository' => 'ndiego/nickdiego.com', 'revision' => '5747c794bbbd0d2b2dfeb999210ab5d4f2e6a3fc', 'source_path' => 'src/components/wp-plugin-card.tsx');
$operatorMapping = array('kind' => 'operator_mapping', 'author' => 'operator:chubes4', 'source_relationship' => 'Maps the visible card version leaf to the source-backed plugin_information.version field; this relationship is operator-authored, not capture-observed.');
$declaration = array('kind' => 'entity_collection', 'type' => 'external_metrics', 'source_path' => 'data/external-metrics.json', 'payload' => array('schema' => 'generic/external-metric/v1', 'entities' => array(
    $fact('nick-projects-five-plugin-installs', $pluginSource(array('block-visibility', 'icon-block', 'social-sharing-block', 'genesis-featured-page-advanced', 'genesis-columns-advanced')), 'active_installs', array('kind' => 'json_pointer', 'pointer' => '/active_installs', 'value_type' => 'nonnegative_integer'), 'sum', '73,000+', $paragraph, 'paragraph', 'core/paragraph', $sourceProof),
    $fact('block-visibility-version', $pluginSource(array('block-visibility')), 'version', array('kind' => 'json_pointer', 'pointer' => '/version', 'value_type' => 'string', 'max_length' => 64), 'identity', 'v1.2.3', $heading, 'heading', 'core/heading', $operatorMapping),
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

$githubRoutes = array('index.html', 'about.html', 'team.html');
$githubHeader = '<header class="github-repo-card"><h2><a href="https://github.com/Automattic/.github">Automattic/.github</a></h2><p>7</p><p>9</p><span aria-hidden="true">★</span></header>';
$asciiAlphaNumeric = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789';
$githubSource = $source('github.repository-information', 'https://api.github.com/repos/{owner}/{repository}', array(), array(), array(
    'owner' => array('location' => 'path', 'min_length' => 1, 'max_length' => 39, 'allowed_characters' => $asciiAlphaNumeric . '-', 'first_characters' => $asciiAlphaNumeric, 'last_characters' => $asciiAlphaNumeric, 'prohibited_values' => array()),
    'repository' => array('location' => 'path', 'min_length' => 1, 'max_length' => 100, 'allowed_characters' => $asciiAlphaNumeric . '._-', 'prohibited_values' => array('.', '..')),
), array(array('owner' => 'Automattic', 'repository' => '.github')), 86400, array('Accept' => 'application/vnd.github+json', 'X-GitHub-Api-Version' => '2022-11-28'));
$githubFiles = array(); $githubFacts = array();
foreach ($githubRoutes as $route) {
    $title = 'index.html' === $route ? 'Home' : ('about.html' === $route ? 'About' : 'Team');
    $githubFiles[$route] = '<!doctype html><html><body>' . $githubHeader . '<main><h1>' . $title . '</h1></main></body></html>';
    foreach (array('stargazers_count' => '7', 'forks_count' => '9') as $metric => $fallback) {
        $githubAnchor = '<!-- wp:paragraph --><p>' . $fallback . '</p><!-- /wp:paragraph -->';
        $githubEntity = $fact('github-' . $metric . '-' . basename($route, '.html'), $githubSource, $metric, array('kind' => 'json_pointer', 'pointer' => '/' . $metric, 'value_type' => 'nonnegative_integer'), 'identity', $fallback, $githubAnchor, 'paragraph', 'core/paragraph', array('kind' => 'source_corroboration', 'repository' => 'ndiego/nickdiego.com', 'revision' => '5747c794bbbd0d2b2dfeb999210ab5d4f2e6a3fc', 'source_path' => 'src/components/gh-repo-card.tsx'), array('locale' => 'en-US', 'grouping' => true, 'prefix' => '', 'suffix' => '', 'decimals' => 0));
        $githubEntity['bindings'][0]['source_path'] = $route;
        $githubEntity['bindings'][0]['search_block_markup'] = $githubAnchor;
        $githubFacts[] = $githubEntity;
    }
}
$githubArtifact = array('entrypoints' => $githubRoutes, 'runtime_declarations' => array(array('kind' => 'entity_collection', 'type' => 'external_metrics', 'source_path' => 'data/external-metrics.json', 'payload' => array('schema' => 'generic/external-metric/v1', 'entities' => $githubFacts))), 'files' => $githubFiles);
$githubPlan = (new ArtifactCompiler())->compile($githubArtifact)->toArray()['source_reports']['wordpress_site_plan'];
WordPressSitePlan::assertValid($githubPlan);
$githubResolved = (new WordPressSitePlanResolver())->resolve($githubPlan, array('theme_uri' => 'https://example.test/theme'));
$githubEntities = $githubResolved['runtime_declarations'][0]['payload']['entities'] ?? array();
$githubByMetric = array_column($githubEntities, null, 'metric');
$githubHeaderParts = array_values(array_filter($githubResolved['template_parts'], static fn(array $part): bool => 'header' === ($part['area'] ?? null)));
$assert(2 === count($githubByMetric) && 1 === count($githubHeaderParts), 'GitHub stars and forks coalesce into one shared header template-part declaration (facts=' . count($githubByMetric) . ', headers=' . count($githubHeaderParts) . ', metrics=' . implode(',', array_keys($githubByMetric)) . ')');
foreach (array('stargazers_count' => '7', 'forks_count' => '9') as $metric => $fallback) {
    $githubEntity = $githubByMetric[$metric] ?? array();
    $githubBinding = $githubEntity['bindings'][0] ?? array();
    $githubAnchor = '<!-- wp:paragraph --><p>' . $fallback . '</p><!-- /wp:paragraph -->';
    $assert(($githubEntity['source']['id'] ?? null) === 'github.repository-information' && ($githubEntity['source']['resources'][0]['repository'] ?? null) === '.github' && ($githubEntity['fallback']['text'] ?? null) === $fallback && ($githubEntity['fallback']['hash'] ?? null) === hash('sha256', $fallback), "{$metric} transports the generic source recipe and real plain-number repository fallback/hash");
    $assert(($githubEntity['provenance']['kind'] ?? null) === 'source_corroboration' && ($githubEntity['provenance']['source_path'] ?? null) === 'src/components/gh-repo-card.tsx', "{$metric} retains explicit source provenance");
    $assert(($githubBinding['source_path'] ?? null) === ($githubHeaderParts[0]['source_path'] ?? null) && WordPressSitePlan::bindingPosition($githubBinding['position'] ?? null, $githubHeaderParts[0]['resolved_block_markup'], $githubAnchor), "{$metric} binding reanchors to the shared native Paragraph leaf");
}
$githubHeaderMarkup = $githubHeaderParts[0]['resolved_block_markup'];
$assert(str_contains($githubHeaderMarkup, 'href="https://github.com/Automattic/.github"') && str_contains($githubHeaderMarkup, '★'), 'GitHub metric declarations preserve the native repository link and authored icon');
$githubCompiler = new ArtifactCompiler();
$githubShared = $githubCompiler->prepareShared($githubArtifact);
$githubReceipts = array(); foreach ($githubShared['analysis']['page_ids'] as $pageId) $githubReceipts[] = $githubCompiler->compilePage($githubArtifact, $githubShared, $pageId);
$githubStaged = $githubCompiler->compose($githubShared, $githubReceipts)->toArray()['source_reports']['wordpress_site_plan'];
$assert($githubStaged === $githubPlan, 'staged compilation preserves both GitHub fields and shared-shell binding transport');

$neutralRoutes = array('index.html', 'about.html', 'team.html');
$neutralAnchor = '<!-- wp:paragraph --><p>31</p><!-- /wp:paragraph -->';
$neutralHeader = '<header class="neutral-metric"><h2><a href="https://metrics.example.com/v1/records/sample">Neutral source</a></h2><p>31</p></header>';
$neutralSource = $source('neutral.example-records', 'https://metrics.example.com/v1/records/{record}', array(), array(), array('record' => array('location' => 'path', 'min_length' => 1, 'max_length' => 64, 'allowed_characters' => 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789-_', 'prohibited_values' => array('.', '..'))), array(array('record' => 'sample')), 600);
$neutralFiles = array(); $neutralFacts = array();
foreach ($neutralRoutes as $route) {
    $title = 'index.html' === $route ? 'Home' : ('about.html' === $route ? 'About' : 'Team');
    $neutralFiles[$route] = '<!doctype html><html><body>' . $neutralHeader . '<main><h1>' . $title . '</h1></main></body></html>';
    $neutralFact = $fact('neutral-score-' . basename($route, '.html'), $neutralSource, 'score', array('kind' => 'json_pointer', 'pointer' => '/measurements/score', 'value_type' => 'nonnegative_integer'), 'identity', '31', $neutralAnchor, 'paragraph', 'core/paragraph', array('kind' => 'operator_mapping', 'author' => 'operator:chubes4', 'source_relationship' => 'The captured neutral source score leaf is explicitly mapped to the configured JSON Pointer score resource.'));
    $neutralFact['bindings'][0]['source_path'] = $route;
    $neutralFacts[] = $neutralFact;
}
$neutralArtifact = array('entrypoints' => $neutralRoutes, 'runtime_declarations' => array(array('kind' => 'entity_collection', 'type' => 'external_metrics', 'source_path' => 'data/external-metrics.json', 'payload' => array('schema' => 'generic/external-metric/v1', 'entities' => $neutralFacts))), 'files' => $neutralFiles);
$neutralCompiler = new ArtifactCompiler();
$neutralPlan = $neutralCompiler->compile($neutralArtifact)->toArray()['source_reports']['wordpress_site_plan'];
WordPressSitePlan::assertValid($neutralPlan);
$neutralShared = $neutralCompiler->prepareShared($neutralArtifact);
$neutralReceipts = array(); foreach ($neutralShared['analysis']['page_ids'] as $pageId) $neutralReceipts[] = $neutralCompiler->compilePage($neutralArtifact, $neutralShared, $pageId);
$neutralStaged = $neutralCompiler->compose($neutralShared, $neutralReceipts)->toArray()['source_reports']['wordpress_site_plan'];
$assert($neutralStaged === $neutralPlan, 'third neutral HTTP/JSON recipe is unchanged by staged compilation');
$neutralResolved = (new WordPressSitePlanResolver())->resolve($neutralStaged, array('theme_uri' => 'https://example.test/theme'));
$neutralEntities = $neutralResolved['runtime_declarations'][0]['payload']['entities'] ?? array();
$neutralParts = array_values(array_filter($neutralResolved['template_parts'], static fn(array $part): bool => 'header' === ($part['area'] ?? null)));
$neutralEntity = $neutralEntities[0] ?? array(); $neutralBinding = $neutralEntity['bindings'][0] ?? array();
$assert(1 === count($neutralEntities) && 1 === count($neutralParts) && ($neutralEntity['source']['id'] ?? null) === 'neutral.example-records' && ($neutralEntity['extraction']['pointer'] ?? null) === '/measurements/score' && ($neutralEntity['extraction']['value_type'] ?? null) === 'nonnegative_integer', 'third neutral source recipe and extraction survive full compilation and shared-shell extraction');
$assert(($neutralEntity['provenance']['kind'] ?? null) === 'operator_mapping' && ($neutralEntity['fallback']['text'] ?? null) === '31' && ($neutralEntity['fallback']['hash'] ?? null) === hash('sha256', '31'), 'third neutral source preserves operator mapping and its plain numeric fallback/hash');
$assert(($neutralBinding['source_path'] ?? null) === ($neutralParts[0]['source_path'] ?? null) && WordPressSitePlan::bindingPosition($neutralBinding['position'] ?? null, $neutralParts[0]['resolved_block_markup'], $neutralAnchor), 'third neutral source selector reanchors to the shared native text leaf');

$compiler = new ArtifactCompiler();
$shared = $compiler->prepareShared($artifact);
$receipts = array();
foreach ($shared['analysis']['page_ids'] as $pageId) $receipts[] = $compiler->compilePage($artifact, $shared, $pageId);
$staged = $compiler->compose($shared, $receipts)->toArray();
$assert($staged['source_reports']['wordpress_site_plan'] === $plan, 'staged compilation preserves the full declaration and exact resolved native leaf bindings');

$shellHtml = '<!doctype html><html><body><header class="site-header"><p>73,000+</p><nav><a href="https://example.org/">Home</a></nav></header><main><h1>Page content</h1></main></body></html>';
$shellFiles = array('index.html' => $shellHtml, 'about.html' => str_replace('Page content', 'About content', $shellHtml), 'team.html' => str_replace('Page content', 'Team content', $shellHtml));
$shellPlain = (new ArtifactCompiler())->compile(array('entrypoints' => array_keys($shellFiles), 'files' => $shellFiles))->toArray();
$shellPage = array_column($shellPlain['source_reports']['compiled_site']['pages'], null, 'source_path')['index.html'];
preg_match('/<!-- wp:paragraph -->.*?<!-- \/wp:paragraph -->/', $shellPage['block_markup'], $shellMatches);
$shellAnchor = $shellMatches[0] ?? '';
$shellMetrics = array();
foreach (array_keys($shellFiles) as $source) {
    $shellMetric = $fact('shared-plugin-installs-' . basename($source, '.html'), $pluginSource(array('block-visibility')), 'active_installs', array('kind' => 'json_pointer', 'pointer' => '/active_installs', 'value_type' => 'nonnegative_integer'), 'sum', '73,000+', $shellAnchor, 'paragraph', 'core/paragraph', $sourceProof);
    $shellMetric['bindings'][0]['source_path'] = $source;
    $shellMetrics[] = $shellMetric;
}
$shellArtifact = array('entrypoints' => array_keys($shellFiles), 'runtime_declarations' => array(array('kind' => 'entity_collection', 'type' => 'external_metrics', 'source_path' => 'data/external-metrics.json', 'payload' => array('schema' => 'generic/external-metric/v1', 'entities' => $shellMetrics))), 'files' => $shellFiles);
$shellPlan = (new ArtifactCompiler())->compile($shellArtifact)->toArray()['source_reports']['wordpress_site_plan'];
WordPressSitePlan::assertValid($shellPlan);
$shellCompiler = new ArtifactCompiler();
$shellShared = $shellCompiler->prepareShared($shellArtifact);
$shellReceipts = array();
foreach ($shellShared['analysis']['page_ids'] as $pageId) $shellReceipts[] = $shellCompiler->compilePage($shellArtifact, $shellShared, $pageId);
$shellStaged = $shellCompiler->compose($shellShared, $shellReceipts)->toArray()['source_reports']['wordpress_site_plan'];
$assert($shellStaged === $shellPlan, 'staged compilation preserves shared-shell metric binding reanchoring byte-for-byte');
$shellResolved = (new WordPressSitePlanResolver())->resolve($shellPlan, array('theme_uri' => 'https://example.test/theme'));
$shellEntities = $shellResolved['runtime_declarations'][0]['payload']['entities'] ?? array();
$shellBound = $shellEntities[0]['bindings'][0] ?? array();
$shellDeclarationIdentity = $shellPlan['runtime_declarations'][0]['reconciliation_identity'] ?? null;
$shellParts = array_values(array_filter($shellPlan['template_parts'], static fn(array $part): bool => 'header' === ($part['area'] ?? null)));
$shellContainer = 1 === count($shellParts) ? $shellParts[0]['canonical_block_markup'] : ($shellResolved['pages'][0]['resolved_block_markup'] ?? '');
$assert(1 === count($shellParts) && 1 === count($shellEntities) && WordPressSitePlan::bindingPosition($shellBound['position'] ?? null, $shellContainer, $shellAnchor), 'shared-shell extraction coalesces equivalent per-route facts and reanchors one native leaf in the shared template part');
$assert(($shellParts[0]['source_path'] ?? null) === ($shellBound['source_path'] ?? null), 'shared-shell extraction rewrites the binding selector to the shared template-part source path');
$assert($shellDeclarationIdentity === ($shellResolved['runtime_declarations'][0]['reconciliation_identity'] ?? null), 'shared-shell fact coalescing preserves the declaration reconciliation identity');
$assert(in_array($shellEntities[0]['id'] ?? null, array_column($shellMetrics, 'id'), true), 'shared-shell hoisting keeps one original route owner');
$assert(RuntimeDeclarations::canonicalJson($operatorMapping) === RuntimeDeclarations::canonicalJson($resolvedEntities['block-visibility-version']['provenance'] ?? null), 'source operator mapping remains intact after shell extraction');
$assert('73,000+' === ($shellResolved['runtime_declarations'][0]['payload']['entities'][0]['fallback']['text'] ?? null), 'shared-shell analysis preserves the exact captured metric fallback');
$provenanceVariant = $shellArtifact;
$provenanceVariant['runtime_declarations'][0]['payload']['entities'][1]['provenance'] = array('kind' => 'operator_mapping', 'author' => 'operator:chubes4', 'source_relationship' => 'Operator-authored mapping for this route; no captured HTML provenance is claimed.');
$provenanceVariantPlan = (new ArtifactCompiler())->compile($provenanceVariant)->toArray()['source_reports']['wordpress_site_plan'];
$assert(array() === array_values(array_filter($provenanceVariantPlan['template_parts'], static fn(array $part): bool => 'header' === ($part['area'] ?? null))) && 3 === count($provenanceVariantPlan['runtime_declarations'][0]['payload']['entities']), 'different source evidence/operator mappings prevent cross-route shell coalescing and retain every source fact');

$bad = $artifact; $bad['runtime_declarations'][0]['payload']['entities'][0]['bindings'][0]['source_path'] = '../outside.html';
$throws(static fn() => (new ArtifactCompiler())->compile($bad), 'unsafe source selectors fail at the artifact boundary');
$bad = $artifact; $bad['runtime_declarations'][0]['payload']['entities'][0]['bindings'][0]['search_block_markup'] = '<!-- wp:html --><p>73,000+</p><!-- /wp:html -->';
$throws(static function () use ($bad): void { $result = (new ArtifactCompiler())->compile($bad)->toArray(); $candidatePlan = $result['source_reports']['wordpress_site_plan'] ?? null; if (!is_array($candidatePlan)) throw new InvalidArgumentException('Compiler declined an opaque/detached metric anchor.'); (new WordPressSitePlanResolver())->resolve($candidatePlan, array('theme_uri' => 'https://example.test/theme')); }, 'opaque HTML bindings are rejected rather than replacing native editable text');
$bad = $artifact; $bad['runtime_declarations'][0]['payload']['entities'][0]['provenance'] = array('kind' => 'captured_html');
$throws(static fn() => (new ArtifactCompiler())->compile($bad), 'ambiguous capture-only source claims are rejected');
$bad = $artifact; $bad['runtime_declarations'][0]['payload']['entities'][0]['fallback']['text'] = '73,001+'; $bad['runtime_declarations'][0]['payload']['entities'][0]['fallback']['hash'] = hash('sha256', '73,001+');
$throws(static fn() => (new ArtifactCompiler())->compile($bad), 'a rehashed but non-matching captured fallback cannot replace the native leaf text');
$bad = $artifact; $bad['runtime_declarations'][0]['payload']['entities'][0]['bindings'][0]['role'] = 'heading'; $bad['runtime_declarations'][0]['payload']['entities'][0]['bindings'][0]['leaf'] = array('block' => 'core/heading', 'attribute' => 'content');
$throws(static fn() => (new ArtifactCompiler())->compile($bad), 'native anchor block name must match its declared Paragraph or Heading leaf');
$bad = $artifact; $bad['runtime_declarations'][0]['payload']['entities'][1]['source']['request']['url_template'] = 'http://attacker.invalid/';
$throws(static fn() => (new ArtifactCompiler())->compile($bad), 'unsupported non-HTTPS sources are rejected at the artifact boundary');

echo 'External metric native binding contract passed: ' . $assertions . " assertions\n";
