<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';
require __DIR__ . '/support/ResolvedPlanProjection.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\WordPressSitePlan;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\WordPressSitePlanResolver;

$assert = static function (bool $condition, string $message): void { if (! $condition) throw new RuntimeException($message); };
$throws = static function (callable $callback, string $message) use ($assert): void { try { $callback(); } catch (InvalidArgumentException) { return; } $assert(false, $message); };
$writeMap = static function (array $writes): array { $map = array(); foreach ($writes as $write) $map[$write['target_path']] = $write; return $map; };

$artifact = array(
    'entrypoint' => 'index.html',
    'files' => array(
        'index.html' => '<!doctype html><html><head><title>Home title</title><meta CHARSET=utf-8><meta NAME=viewport CONTENT="width=device-width, initial-scale=1"><link REL=stylesheet HREF=assets/site.css integrity=sha256-test crossorigin="" referrerpolicy=no-referrer fetchpriority=high><link rel=preconnect href=https://cdn.example.test/path?x=1&y=2></head><body><header><p>Entry Header</p></header><main><img src="assets/logo.svg" srcset="assets/logo.svg 1x, assets/logo.svg 2x"><h1>Home</h1></main><footer><p>Entry Footer</p></footer><script  SRC=assets/async.js ASYNC crossorigin referrerpolicy="" fetchpriority=""></script><script src=assets/defer.js defer></script><script src=assets/both.js async defer></script><script src=assets/module.js TYPE=module></script><script src=assets/legacy.js nomodule></script><script>window.inline = true;</script></body></html>',
        'nested/about.html' => '<!doctype html><html><head><title>About title</title><link rel="stylesheet" href="../assets/site.css" as="style"></head><body><header><p>About Chrome</p></header><main><img src="../assets/logo.svg"><h1>About</h1></main><script src="https://cdn.example.test/external.js" type="module"></script></body></html>',
        'parts/sidebar.html' => '<aside><p>Unbound Sidebar</p></aside>',
        'assets/site.css' => '@font-face{font-family:test;src:url(font.woff2)}main{background:url("logo.svg")}',
        'assets/async.js' => 'window.asyncAsset=true;',
        'assets/defer.js' => 'window.deferAsset=true;',
        'assets/both.js' => 'window.bothAsset=true;',
        'assets/module.js' => 'window.moduleAsset=true;',
        'assets/legacy.js' => 'window.legacyAsset=true;',
        'assets/logo.svg' => '<svg xmlns="http://www.w3.org/2000/svg"/>',
        'assets/font.woff2' => 'font-data',
    ),
);
$first = (new ArtifactCompiler())->compile($artifact)->toArray();
$second = (new ArtifactCompiler())->compile($artifact)->toArray();
$plan = $first['source_reports']['wordpress_site_plan'] ?? array();
$assert(array() !== $plan, 'Compiler emits a complete site plan: ' . json_encode(array($first['source_reports']['wordpress_site_plan_diagnostics'] ?? array(), $first['source_reports']['compiled_site']['template_parts'] ?? array())));
$writes = $writeMap($plan['writes']);

$assert(WordPressSitePlan::SCHEMA === ($plan['schema'] ?? null), 'Compiler projects the v2 canonical WordPress site plan.');
$assert(isset($writes['style.css'], $writes['theme.json'], $writes['functions.php'], $writes['templates/index.html'], $writes['templates/page.html'], $writes['templates/front-page.html'], $writes['parts/header.html'], $writes['parts/footer.html'], $writes['parts/sidebar.html']), 'Plan declares the complete block-theme scaffold.');
$assert(str_contains((string) $writes['style.css']['payload']['data'], 'Theme Name:'), 'Theme stylesheet has a recognition header.');
$assert(3 === (json_decode((string) $writes['theme.json']['payload']['data'], true)['version'] ?? null), 'Theme configuration is parseable and supported.');
$assert(str_contains((string) $writes['templates/front-page.html']['payload']['data'], '"slug":"header"') && str_contains((string) $writes['templates/front-page.html']['payload']['data'], '"slug":"footer"'), 'Front-page template references extracted header and footer parts.');
$assert(!str_contains((string) $writes['templates/page.html']['payload']['data'], '"slug":"header"') && !str_contains((string) $writes['templates/front-page.html']['payload']['data'], '"slug":"sidebar"'), 'Templates do not bind unproven or unbound parts.');
$pagesBySource = array(); foreach ($plan['pages'] as $page) $pagesBySource[$page['source_path']] = $page;
$assert(!str_contains((string) ($pagesBySource['index.html']['canonical_block_markup'] ?? ''), 'Entry Header') && str_contains((string) ($pagesBySource['nested/about.html']['canonical_block_markup'] ?? ''), 'About Chrome'), 'Only extracted entry shell content is removed from page markup: ' . json_encode($pagesBySource));
$assert('site_reading' === ($plan['operations'][0]['kind'] ?? null) && 'index.html' === ($plan['operations'][0]['front_page_source_path'] ?? null), 'Plan declares deterministic front-page desired state.');
$assert(str_contains((string) ($plan['pages'][0]['canonical_block_markup'] ?? ''), '{{wordpress-site-plan:asset:'), 'Canonical page markup uses declared destination-independent references.');
$assert(!isset($plan['pages'][0]['resolved_block_markup']), 'Canonical markup is explicitly distinct from resolved markup.');
$assert(count($plan['reference_tokens']) === count($plan['assets']), 'Every asset has one deterministic resolver token.');
$assert(true === ($plan['reference_semantics']['dynamic_client_assets']['materializer_may_reject'] ?? null), 'Plan exposes dynamic client asset capability limits.');
$assert($plan === ($second['source_reports']['wordpress_site_plan'] ?? null), 'Canonical WordPress site plans are deterministic.');
$home = $pagesBySource['index.html'];
$assert('Home title' === ($home['document_metadata']['title'] ?? null) && 'head' === ($home['document_metadata']['title_declaration']['placement'] ?? null) && 'utf-8' === ($home['document_metadata']['meta'][0]['charset'] ?? null) && 'viewport' === ($home['document_metadata']['meta'][1]['name'] ?? null), 'Plan projects title, source context, charset, and viewport metadata from the compiler document report.');
$assert(str_starts_with((string) ($home['document_metadata']['links'][0]['asset_reference'] ?? ''), WordPressSitePlan::TOKEN_PREFIX) && 'sha256-test' === ($home['document_metadata']['links'][0]['integrity'] ?? null) && 'anonymous' === ($home['document_metadata']['links'][0]['crossorigin'] ?? null) && 'https://cdn.example.test/path?x=1&y=2' === ($home['document_metadata']['links'][1]['url'] ?? null), 'Plan preserves unquoted, mixed-case local and external link declarations with safe URL punctuation and anonymous CORS semantics.');
$scripts = $home['document_metadata']['scripts'];
$assert(true === ($scripts[0]['async'] ?? null) && false === ($scripts[0]['defer'] ?? null) && 'async' === ($scripts[0]['effective_loading'] ?? null) && 'anonymous' === ($scripts[0]['crossorigin'] ?? null) && '' === ($scripts[0]['referrerpolicy'] ?? null) && '' === ($scripts[0]['fetchpriority'] ?? null) && false === ($scripts[1]['async'] ?? null) && true === ($scripts[1]['defer'] ?? null) && 'defer' === ($scripts[1]['effective_loading'] ?? null) && true === ($scripts[2]['async'] ?? null) && true === ($scripts[2]['defer'] ?? null) && 'async' === ($scripts[2]['effective_loading'] ?? null) && true === ($scripts[3]['module'] ?? null) && 'defer' === ($scripts[3]['effective_loading'] ?? null) && true === ($scripts[4]['nomodule'] ?? null) && 'inline' === ($scripts[5]['source_kind'] ?? null), 'Plan preserves async, defer, async plus defer, module, nomodule, inline, and empty-valued standard attribute semantics independently.');
$assert(2 === ($plan['reporting']['metrics']['source_document_count'] ?? null) && 2 === ($plan['reporting']['metrics']['block_document_count'] ?? null) && is_array($plan['reporting']['diagnostic_codes'] ?? null), 'Plan carries generic compiler reporting summaries and diagnostic linkage.');
$bootstrap = (string) $writes['functions.php']['payload']['data'];
$assert(str_contains($bootstrap, "wp_register_script") && str_contains($bootstrap, "get_theme_file_uri(") && str_contains($bootstrap, "https://cdn.example.test/external.js") && str_contains($bootstrap, "'strategy' => 'async'") && str_contains($bootstrap, "script_loader_tag") && str_contains($bootstrap, "'nomodule' => true") && str_contains($bootstrap, "'type' => 'module'") && str_contains($bootstrap, 'is_front_page()') && str_contains($bootstrap, "'nested/about' === trim( get_page_uri( get_queried_object_id() ), '/' )") && !str_contains($bootstrap, "array (\n  0 => 'blocks-engine-script-"), 'Canonical functions.php registers dependency-free local and external scripts with exact attributes and canonical front-page/page URI scope conditions.');

$unsupportedScripts = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array('index.html' => '<!doctype html><html><head><script src="https://cdn.example.test/conflict.js" type="module" nomodule></script></head><body><main>Unsupported scripts</main><script>window.inline = true;</script></body></html>', 'assets/unused.js' => 'window.unused=true;')))->toArray();
$unsupportedPlan = $unsupportedScripts['source_reports']['wordpress_site_plan'] ?? array();
$unsupportedBootstrap = $writeMap($unsupportedPlan['writes'] ?? array())['functions.php']['payload']['data'] ?? '';
$assert('not_proven' === ($unsupportedPlan['reference_semantics']['dynamic_client_assets']['status'] ?? null) && true === ($unsupportedPlan['reference_semantics']['dynamic_client_assets']['materializer_may_reject'] ?? null) && in_array('wordpress_site_plan_script_module_nomodule_conflict', $unsupportedPlan['reporting']['diagnostic_codes'] ?? array(), true) && in_array('wordpress_site_plan_script_inline_unsupported', $unsupportedPlan['reporting']['diagnostic_codes'] ?? array(), true) && !str_contains((string) $unsupportedBootstrap, 'conflict.js'), 'Contradictory and inline script declarations are diagnosed, remain unproven, and are never silently emitted.');
$noScripts = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array('index.html' => '<main>No scripts</main>')))->toArray();
$assert('proven' === ($noScripts['source_reports']['wordpress_site_plan']['reference_semantics']['dynamic_client_assets']['status'] ?? null), 'Static plans without scripts are proven by the canonical scaffold capability.');
$staticScripts = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array('index.html' => '<!doctype html><html><body><main>Static script</main><script src="assets/static.js" defer></script></body></html>', 'assets/static.js' => 'window.staticAsset = true;')))->toArray();
$staticPlan = $staticScripts['source_reports']['wordpress_site_plan'];
$assert('proven' === ($staticPlan['reference_semantics']['dynamic_script_references'] ?? null) && 'proven' === ($staticPlan['reference_semantics']['dynamic_client_assets']['status'] ?? null) && false === ($staticPlan['reference_semantics']['dynamic_client_assets']['materializer_may_reject'] ?? null), 'A declared static script with materialized loading semantics is proven.');
$dynamicScripts = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array('index.html' => '<!doctype html><html><body><main>Dynamic script</main><script src="assets/dynamic.js"></script></body></html>', 'assets/dynamic.js' => 'import("./chunk.js");')))->toArray();
$dynamicPlan = $dynamicScripts['source_reports']['wordpress_site_plan'];
$assert('not_proven' === ($dynamicPlan['reference_semantics']['dynamic_script_references'] ?? null) && 'not_proven' === ($dynamicPlan['reference_semantics']['dynamic_client_assets']['status'] ?? null) && true === ($dynamicPlan['reference_semantics']['dynamic_client_assets']['materializer_may_reject'] ?? null) && in_array('wordpress_site_plan_script_dynamic_references', $dynamicPlan['reporting']['diagnostic_codes'] ?? array(), true), 'Dynamic imports leave both script-reference and aggregate asset capability unproven with a diagnostic.');
$externalScripts = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array('index.html' => '<!doctype html><html><body><main>External script</main><script src="https://cdn.example.test/library.js" defer></script></body></html>')))->toArray();
$externalPlan = $externalScripts['source_reports']['wordpress_site_plan'];
$externalBootstrap = $writeMap($externalPlan['writes'])['functions.php']['payload']['data'] ?? '';
$assert('not_proven' === ($externalPlan['reference_semantics']['dynamic_script_references'] ?? null) && 'not_proven' === ($externalPlan['reference_semantics']['dynamic_client_assets']['status'] ?? null) && true === ($externalPlan['reference_semantics']['dynamic_client_assets']['materializer_may_reject'] ?? null) && in_array('wordpress_site_plan_script_external_unproven', $externalPlan['reporting']['diagnostic_codes'] ?? array(), true) && str_contains((string) $externalBootstrap, 'https://cdn.example.test/library.js'), 'External scripts remain emitted but are explicitly unproven without a declared local artifact.');
$routeScope = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array('index.html' => '<main>Home</main>', 'about.html' => '<main>About</main><script src="assets/root-about.js"></script>', 'nested/about.html' => '<main>Nested About</main><script src="assets/nested-about.js"></script>', 'nested/deep/about.html' => '<main>Deep About</main><script src="assets/deep-about.js"></script>', 'assets/root-about.js' => 'window.rootAbout=true;', 'assets/nested-about.js' => 'window.nestedAbout=true;', 'assets/deep-about.js' => 'window.deepAbout=true;')))->toArray();
$routeBootstrap = $writeMap($routeScope['source_reports']['wordpress_site_plan']['writes'])['functions.php']['payload']['data'] ?? '';
$assert(str_contains((string) $routeBootstrap, "'about' === trim( get_page_uri( get_queried_object_id() ), '/' )") && str_contains((string) $routeBootstrap, "'nested/about' === trim( get_page_uri( get_queried_object_id() ), '/' )") && str_contains((string) $routeBootstrap, "'nested/deep/about' === trim( get_page_uri( get_queried_object_id() ), '/' )"), 'Duplicate leaf slugs use exact canonical hierarchical page URI conditions.');
$duplicates = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array('index.html' => '<!doctype html><html><body><main>Duplicate scripts</main><script src="https://cdn.example.test/duplicate.js"></script><script src="https://cdn.example.test/duplicate.js"></script></body></html>')))->toArray();
$duplicateBootstrap = $writeMap($duplicates['source_reports']['wordpress_site_plan']['writes'] ?? array())['functions.php']['payload']['data'] ?? '';
$assert(2 === substr_count((string) $duplicateBootstrap, 'https://cdn.example.test/duplicate.js') && 2 === substr_count((string) $duplicateBootstrap, 'wp_register_script'), 'Repeated declarations retain their deterministic distinct registrations rather than colliding or silently deduplicating execution.');

$malformed = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array('index.html' => '<head><link rel=stylesheet href=assets/site.css broken="unterminated><link rel=stylesheet href=assets/site.css media=""></head><body><main><h1>Malformed</h1></main><script src=assets/app.js type=module defer></script></body>', 'assets/site.css' => 'body{}', 'assets/app.js' => 'window.app=true;')))->toArray();
$malformedPlan = $malformed['source_reports']['wordpress_site_plan'] ?? array();
$malformedPage = $malformedPlan['pages'][0] ?? array();
$assert(2 === count($malformedPage['document_metadata']['links'] ?? array()) && str_starts_with((string) ($malformedPage['document_metadata']['links'][1]['asset_reference'] ?? ''), WordPressSitePlan::TOKEN_PREFIX) && true === ($malformedPage['document_metadata']['scripts'][0]['module'] ?? null) && true === ($malformedPage['document_metadata']['scripts'][0]['defer'] ?? null), 'Malformed attributes retain bounded declarations while later unquoted declarations and module defer semantics remain intact.');

$rootRelative = (new ArtifactCompiler())->compile(array('entrypoint' => 'nested/index.html', 'files' => array(
    'nested/index.html' => '<!doctype html><head><link rel="stylesheet" href="/assets/site.css?theme=1#main"><script src="/assets/app.js?build=2#run"></script></head><body><img src="/assets/logo.svg?width=40#hero" srcset="/assets/logo.svg?one=1#one 1x, /assets/logo.svg?two=2#two 2x" poster="/assets/poster.jpg"><a href="/application-route#anchor">Route</a><a href="#local">Local</a><a href="https://example.test/external">External</a><a href="//cdn.example.test/library.js">Protocol</a><a href="mailto:test@example.test">Mail</a><a href="tel:+15551212">Tel</a><img src="data:image/svg+xml,svg"><img src="blob:https://example.test/blob"><img src="/assets%2flogo.svg"><img src="/../assets/logo.svg"></body>',
    'assets/site.css' => '@import "/assets/import.css?version=3#import"; @font-face{src:url("/assets/font.woff2?font=1#face")} main{background:url(/assets/logo.svg?background=1#image)} .quoted{background:url("/assets/logo(1).svg?quoted=1#image")} .unquoted{background:url(/assets/logo.svg?unquoted=1#image)} .escaped{background:url("/assets/logo\\(1\\).svg?escaped=1#image")} .malformed{background:url("/assets/logo(1).svg?broken=1#image"}',
    'assets/import.css' => 'main{background:url(/assets/logo.svg)}',
    'assets/app.js' => 'window.app=true;',
    'assets/logo.svg' => '<svg xmlns="http://www.w3.org/2000/svg"/>',
    'assets/logo(1).svg' => '<svg xmlns="http://www.w3.org/2000/svg"/>',
    'assets/poster.jpg' => 'poster',
    'assets/font.woff2' => 'font',
)))->toArray();
$rootPlan = $rootRelative['source_reports']['wordpress_site_plan'] ?? array();
$assert(array() !== $rootPlan && !isset($rootRelative['source_reports']['wordpress_site_plan_diagnostics']), 'SSI WebsiteArtifact document-metadata smoke fixture emits a self-contained WordPress site plan.');
$rootPage = $rootPlan['pages'][0] ?? array();
$rootCss = $writeMap($rootPlan['writes'])['assets/assets/site.css']['payload']['data'] ?? '';
$assert(str_contains((string) $rootCss, WordPressSitePlan::TOKEN_PREFIX) && str_contains((string) $rootCss, '?version=3#import') && str_contains((string) $rootCss, '?font=1#face') && str_contains((string) $rootCss, '?background=1#image') && str_contains((string) $rootCss, '}}?quoted=1#image') && str_contains((string) $rootCss, '}}?unquoted=1#image') && str_contains((string) $rootCss, '}}?escaped=1#image') && str_contains((string) $rootCss, '/assets/logo(1).svg?broken=1#image'), 'Bounded CSS URL parsing canonicalizes quoted, unquoted, and escaped assets while preserving malformed calls and suffixes.');
$rootResolved = (new WordPressSitePlanResolver())->resolve($rootPlan, array('theme_uri' => 'https://example.test/wp-content/themes/root'));
$rootResolvedPage = $rootResolved['pages'][0] ?? array();
$assert('https://example.test/wp-content/themes/root/assets/assets/site.css?theme=1#main' === ($rootResolvedPage['document_metadata']['links'][0]['resolved_url'] ?? null) && 'https://example.test/wp-content/themes/root/assets/assets/app.js?build=2#run' === ($rootResolvedPage['document_metadata']['scripts'][0]['resolved_url'] ?? null), 'Resolved metadata URLs use the declared write URL and preserve query and fragment suffixes.');
$rootResolvedCss = $writeMap($rootResolved['writes'])['assets/assets/site.css']['payload']['data'] ?? '';
$assert(str_contains((string) $rootResolvedCss, 'https://example.test/wp-content/themes/root/assets/assets/logo(1).svg?quoted=1#image') && str_contains((string) $rootResolvedCss, 'https://example.test/wp-content/themes/root/assets/assets/logo(1).svg?escaped=1#image'), 'Quoted and escaped parenthesized CSS URLs resolve through declared asset writes.');
$rootBootstrap = $writeMap($rootPlan['writes'])['functions.php']['payload']['data'] ?? '';
$assert(str_contains((string) $rootBootstrap, "get_theme_file_uri( 'assets/assets/app.js' )") && str_contains((string) $rootBootstrap, "'?build=2#run'"), 'Canonical script writes preserve root-relative local source identity and query/fragment suffixes without resolver-specific URLs.');
$canonicalizer = new \Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\AssetReferenceCanonicalizer($rootPlan['reference_tokens']);
$rootLogo = $canonicalizer->reference('/assets/logo.svg?width=40#hero', 'nested/index.html');
$markupReferences = $canonicalizer->content('<img src="/assets/logo.svg?width=40#hero" srcset="/assets/logo.svg?one=1#one 1x, /assets/logo.svg?two=2#two 2x" poster="/assets/poster.jpg"><a href="/application-route#anchor">Route</a>', 'nested/index.html');
$assert(is_string($rootLogo) && str_ends_with($rootLogo, '?width=40#hero') && str_contains($markupReferences, WordPressSitePlan::TOKEN_PREFIX) && str_contains($markupReferences, '?two=2#two') && str_contains($markupReferences, '/application-route#anchor') && null !== $canonicalizer->reference('../assets/logo.svg', 'nested/index.html') && null === $canonicalizer->reference('/application-route#anchor', 'nested/index.html') && null === $canonicalizer->reference('#local', 'nested/index.html') && null === $canonicalizer->reference('https://example.test/external', 'nested/index.html') && null === $canonicalizer->reference('//cdn.example.test/library.js', 'nested/index.html') && null === $canonicalizer->reference('data:image/svg+xml,svg', 'nested/index.html') && null === $canonicalizer->reference('blob:https://example.test/blob', 'nested/index.html') && null === $canonicalizer->reference('mailto:test@example.test', 'nested/index.html') && null === $canonicalizer->reference('tel:+15551212', 'nested/index.html') && null === $canonicalizer->reference('/assets%2flogo.svg', 'nested/index.html') && null === $canonicalizer->reference('/../assets/logo.svg', 'nested/index.html'), 'Canonical matching resolves root-relative and nested markup asset identities only, preserving browser routes, anchors, external schemes, encoded separators, and traversal references.');
$throws(static fn() => new \Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\AssetReferenceCanonicalizer(array(array('source_path' => 'assets/logo.svg', 'token' => 'asset-0000000000000000'), array('source_path' => 'assets\\logo.svg', 'token' => 'asset-1111111111111111'))), 'Canonical asset source identities reject normalized-separator collisions.');

$resolver = new WordPressSitePlanResolver();
$resolved = $resolver->resolve($plan, array('theme_uri' => 'https://example.test/wp-content/themes/site'));
$resolvedAgain = $resolver->resolve($plan, array('theme_uri' => 'https://example.test/wp-content/themes/site/'));
$assert($resolved === $resolvedAgain, 'Resolution is deterministic after theme URI normalization.');
$about = (string) $resolved['pages'][0]['resolved_block_markup'];
$assert(str_contains($about, 'https://example.test/wp-content/themes/site/assets/assets/logo.svg'), 'Nested page markup resolves declared assets to the explicit theme URI.');
$resolvedWrites = $writeMap($resolved['writes']);
$assert(str_contains((string) $resolvedWrites['assets/assets/site.css']['payload']['data'], 'https://example.test/wp-content/themes/site/assets/assets/logo.svg'), 'Stylesheet references resolve through the same declared token.');
$resolvedPages = array(); foreach ($resolved['pages'] as $page) $resolvedPages[$page['source_path']] = $page;
$assert('https://example.test/wp-content/themes/site/assets/assets/site.css' === ($resolvedPages['index.html']['document_metadata']['links'][0]['resolved_url'] ?? null) && 'https://example.test/wp-content/themes/site/assets/assets/async.js' === ($resolvedPages['index.html']['document_metadata']['scripts'][0]['resolved_url'] ?? null) && 'https://cdn.example.test/external.js' === ($resolvedPages['nested/about.html']['document_metadata']['scripts'][0]['url'] ?? null), 'Resolver resolves local document metadata references only through declared writes and preserves external URLs.');

// A downstream consumer needs only this public plan and its own receipt to project a stable report.
$receipt = array('writes' => array_map(static fn(array $write): array => array('target_path' => $write['target_path'], 'status' => 'written'), $resolved['writes']), 'pages' => array_map(static fn(array $page): array => array('reconciliation_identity' => $page['reconciliation_identity'], 'status' => 'written'), $resolved['pages']));
$projection = ResolvedPlanProjection::fromPlanAndReceipt($resolved, $receipt);
$assert('Home title' === ($projection['documents'][0]['title'] ?? null) && 2 === ($projection['reporting']['metrics']['source_document_count'] ?? null) && count($resolved['writes']) === $projection['write_count'], 'An independent consumer derives document/report content from only the resolved plan and synthetic receipt.');
$missingReceiptPage = $receipt; array_pop($missingReceiptPage['pages']);
$throws(static fn() => ResolvedPlanProjection::fromPlanAndReceipt($resolved, $missingReceiptPage), 'Independent projection rejects receipts that omit resolved pages.');
$extraReceiptWrite = $receipt; $extraReceiptWrite['writes'][] = array('target_path' => 'outside-plan.json', 'status' => 'written');
$throws(static fn() => ResolvedPlanProjection::fromPlanAndReceipt($resolved, $extraReceiptWrite), 'Independent projection rejects receipts that add undeclared writes.');

$destination = sys_get_temp_dir() . '/blocks-engine-site-plan-' . bin2hex(random_bytes(6));
foreach ($resolved['writes'] as $write) {
    $path = $destination . '/' . $write['target_path'];
    if (!is_dir(dirname($path))) mkdir(dirname($path), 0777, true);
    file_put_contents($path, 'base64' === $write['payload']['encoding'] ? base64_decode($write['payload']['data'], true) : $write['payload']['data']);
}
foreach (array('style.css', 'theme.json', 'functions.php', 'templates/index.html', 'templates/page.html', 'templates/front-page.html', 'parts/header.html', 'parts/footer.html', 'parts/sidebar.html', 'assets/assets/site.css', 'assets/assets/async.js', 'assets/assets/module.js', 'assets/assets/logo.svg', 'assets/assets/font.woff2') as $required) $assert(is_file($destination . '/' . $required), "Materialization writes {$required}.");
$assert(false === str_contains((string) file_get_contents($destination . '/assets/assets/site.css'), WordPressSitePlan::TOKEN_PREFIX), 'Materialized assets contain no unresolved resolver tokens.');
$runtime = array('pages' => array(), 'front_page' => null);
foreach ($resolved['pages'] as $page) $runtime['pages'][$page['reconciliation_identity']] = $page;
foreach ($resolved['operations'] as $operation) if ('site_reading' === $operation['kind']) $runtime['front_page'] = $runtime['pages'][$operation['front_page_reconciliation_identity']] ?? null;
$assert('index.html' === ($runtime['front_page']['source_path'] ?? null), 'Operations apply verbatim to the deterministic runtime harness.');

$throws(static fn() => $resolver->resolve($plan, array()), 'Resolution rejects missing destination context.');
$throws(static fn() => $resolver->resolve($plan, array('theme_uri' => '/themes/site')), 'Resolution rejects relative destination context.');
$throws(static fn() => $resolver->resolve($plan, array('theme_uri' => 'https://example.test/theme', 'require_proven_dynamic_client_assets' => true)), 'Materializers can reject unproven dynamic client assets.');
$assert($resolver->resolve($noScripts['source_reports']['wordpress_site_plan'], array('theme_uri' => 'https://example.test/theme', 'require_proven_dynamic_client_assets' => true))['resolution']['theme_uri'] === 'https://example.test/theme', 'Materializers accept a plan whose static client asset semantics are proven.');
$assert($resolver->resolve($staticPlan, array('theme_uri' => 'https://example.test/theme', 'require_proven_dynamic_client_assets' => true))['resolution']['theme_uri'] === 'https://example.test/theme', 'Resolver accepts a supported static script plan when proof is required.');
$throws(static fn() => $resolver->resolve($dynamicPlan, array('theme_uri' => 'https://example.test/theme', 'require_proven_dynamic_client_assets' => true)), 'Resolver rejects genuinely unproven dynamic script references.');
$throws(static fn() => $resolver->resolve($externalPlan, array('theme_uri' => 'https://example.test/theme', 'require_proven_dynamic_client_assets' => true)), 'Resolver rejects external script plans whose contents cannot be proven.');
foreach (array('https://example.test/theme?x=1', 'https://example.test/theme#x', 'https://user@example.test/theme', 'ftp://example.test/theme', 'https:///theme', 'https://example.test/a/../theme', "https://example.test/theme\n") as $uri) $throws(static fn() => $resolver->resolve($plan, array('theme_uri' => $uri)), 'Resolution rejects ambiguous runtime context.');
$undeclared = $plan; $undeclared['pages'][0]['canonical_block_markup'] .= '{{wordpress-site-plan:asset:asset-0000000000000000}}';
$throws(static fn() => WordPressSitePlan::assertValid($undeclared), 'Validation rejects undeclared tokens.');
$traversal = $plan; $traversal['writes'][0]['target_path'] = '../escape.css';
$throws(static fn() => WordPressSitePlan::assertValid($traversal), 'Validation rejects traversal writes.');
$collision = $plan; $collision['writes'][1]['target_path'] = $collision['writes'][0]['target_path'];
$throws(static fn() => WordPressSitePlan::assertValid($collision), 'Validation rejects colliding writes.');
$caseCollision = $plan; $caseCollision['writes'][1]['target_path'] = 'STYLE.css';
$throws(static fn() => WordPressSitePlan::assertValid($caseCollision), 'Validation rejects case-folded collisions.');
$invalidScaffold = $plan; $invalidScaffold['writes'][0]['kind'] = 'theme_asset';
$throws(static fn() => WordPressSitePlan::assertValid($invalidScaffold), 'Validation rejects malformed scaffold writes.');
$unresolvedLocal = $plan; $unresolvedLocal['pages'][0]['canonical_block_markup'] .= '<img src="images/missing.svg">';
$throws(static fn() => WordPressSitePlan::assertValid($unresolvedLocal), 'Validation rejects unresolved local browser references.');
$invalidMetadata = $plan; $invalidMetadata['pages'][0]['document_metadata']['scripts'][0]['asset_reference'] = '{{wordpress-site-plan:asset:asset-0000000000000000}}';
$throws(static fn() => WordPressSitePlan::assertValid($invalidMetadata), 'Validation rejects undeclared document metadata references.');
$invalidLoad = $plan; $invalidLoad['pages'][0]['document_metadata']['scripts'][0]['load'] = 'later';
$throws(static fn() => WordPressSitePlan::assertValid($invalidLoad), 'Validation rejects invalid document script load semantics.');
$invalidOrder = $plan; $invalidOrder['pages'][0]['document_metadata']['links'][0]['order'] = 1;
$throws(static fn() => WordPressSitePlan::assertValid($invalidOrder), 'Validation rejects non-deterministic document metadata ordering.');
$localMetadataUrl = $plan; $localMetadataUrl['pages'][0]['document_metadata']['links'][0]['asset_reference'] = null; $localMetadataUrl['pages'][0]['document_metadata']['links'][0]['url'] = 'assets/site.css';
$throws(static fn() => WordPressSitePlan::assertValid($localMetadataUrl), 'Validation rejects local metadata URLs without canonical references.');
$invalidCompiledAsset = $first; $invalidCompiledAsset['source_reports']['compiled_site']['assets'][0]['target_path'] = 'C:\\theme\\site.css';
$throws(static fn() => (new WordPressSitePlan())->fromResult($invalidCompiledAsset), 'Projection rejects unsafe compiled asset targets.');

foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($destination, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $item) $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
rmdir($destination);
fwrite(STDOUT, "wordpress-site-plan contract passed\n");
