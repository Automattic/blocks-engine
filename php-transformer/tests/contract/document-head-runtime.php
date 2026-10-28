<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\RuntimeDependencyParityReport;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\DocumentHeadContext;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\WordPressSitePlan;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\WordPressSitePlanResolver;

$artifact = require dirname(__DIR__) . '/fixtures/document-head-runtime.php';
$result = (new ArtifactCompiler())->compile($artifact)->toArray();
$errors = array_values(array_filter($result['diagnostics'], static fn(array $row): bool => 'error' === ($row['severity'] ?? '')));
if (array() !== $errors || !isset($result['source_reports']['wordpress_site_plan'])) {
    fwrite(STDERR, "FAIL: neutral declared head selector must compile through a materializable site plan\n" . json_encode($errors, JSON_PRETTY_PRINT) . "\n");
    exit(1);
}
$passes = 1;
$assert = static function (bool $condition, string $message) use (&$passes): void {
    if (!$condition) throw new RuntimeException($message);
    ++$passes;
};
$rejects = static function (callable $operation): bool {
    try { $operation(); } catch (InvalidArgumentException) { return true; }
    return false;
};
$plan = $result['source_reports']['wordpress_site_plan'];
$resolved = (new WordPressSitePlanResolver())->resolve($plan, array('theme_uri' => 'https://example.test/theme', 'require_proven_dynamic_client_assets' => true));
$head = DocumentHeadContext::fromPlan($resolved, 'index.html');
$assert(1 === substr_count($head, 'name="viewport"') && str_contains($head, 'data-selected-viewport=""') && str_contains($head, 'content="width=device-width, initial-scale=1"'), 'Emitted contract retains a unique viewport, the target attribute and initial authored content.');
$assert(array('meta', 'meta', 'script', 'style', 'style', 'link', 'script') === array_column($plan['pages'][0]['document_metadata']['head']['elements'], 'tag'), 'The mixed head sequence preserves parser order, including adjacent same-media styles.');
$assert(1 === preg_match('~<link rel="stylesheet" href="\{\{wordpress-site-plan:asset:[^"]+\}\}" data-document-scope="phone" media="not all">~', $head) && !str_contains($head, '<style') && !str_contains($head, '.phone{') && str_contains($head, 'data-source-media="screen"') && str_contains($head, 'media="not all"'), 'Activation media, authored-media provenance and document scope remain distinct.');
$dependencies = $result['source_reports']['runtime_dependency_parity']['dependencies'];
$headDependencies = array_values(array_filter($dependencies, static fn(array $row): bool => 'meta[data-selected-viewport]' === $row['selector']));
$assert(2 === count($headDependencies) && array('declared_document_head') === array_values(array_unique(array_column($headDependencies, 'generated_target_evidence'))), 'Both scripts use actual declared head evidence.');
$report = new RuntimeDependencyParityReport();
$files = array(array('path' => 'app.js', 'kind' => 'js', 'content' => 'document.querySelector("meta[data-selected-viewport]").content="width=320";'));
$missing = $report->fromArtifact($files, $artifact['files']['index.html'], '<meta data-selected-viewport="">', 'index.html');
$assert(1 === count($missing['findings']) && false === $missing['dependencies'][0]['generated_present'], 'Body markup cannot prove an unmaterialized head target.');
$removed = $plan;
unset($removed['pages'][0]['document_metadata']['head']);
$assert($rejects(static fn() => DocumentHeadContext::fromPlan($removed, 'index.html')), 'A head declaration without its matching bootstrap is rejected.');
$writeIndex = array_search('functions.php', array_column($plan['writes'], 'target_path'), true);
$tampered = $plan;
$tampered['writes'][$writeIndex]['payload']['data'] = "<?php\n";
$tampered['writes'][$writeIndex]['payload_hash'] = WordPressSitePlan::contentHash("<?php\n");
$assert($rejects(static fn() => DocumentHeadContext::fromPlan($tampered, 'index.html')), 'A hash-updated but unmaterialized head bootstrap cannot supply evidence.');
$missingResult = $result;
unset($missingResult['source_reports']['compiled_site']['pages'][0]['document_metadata']['head']['elements'][1]['attributes']['data-selected-viewport']);
$missingPlan = (new WordPressSitePlan())->fromCompilerResult($missingResult);
$neutralMissing = $report->fromArtifact($files, $artifact['files']['index.html'], '', 'index.html', wordpressSitePlan: $missingPlan);
$assert(1 === count($neutralMissing['findings']) && false === $neutralMissing['dependencies'][0]['generated_present'], 'A valid materializer that omits the actual source head target still fails parity.');
$bodyMissing = $report->fromArtifact(array(array('path' => 'body.js', 'kind' => 'js', 'content' => 'document.querySelector("[data-body-target]").click();')), '<html><head></head><body><button data-body-target="">Open</button></body></html>', '', 'index.html', wordpressSitePlan: $plan);
$assert(1 === count($bodyMissing['findings']), 'Body target proof stays strict when a head contract exists.');
$sharedFiles = array(array('path' => 'shared.js', 'kind' => 'js', 'content' => 'document.querySelector("[data-selected-viewport]").textContent="selected";'));
$both = $report->fromArtifact($sharedFiles, str_replace('</body>', '<div data-selected-viewport=""></div></body>', $artifact['files']['index.html']), '', 'index.html', wordpressSitePlan: $plan);
$assert(1 === count($both['findings']), 'A selector shared by head and body must retain both regions.');
$bothPresent = $report->fromArtifact($sharedFiles, str_replace('</body>', '<div data-selected-viewport=""></div></body>', $artifact['files']['index.html']), '<div data-selected-viewport=""></div>', 'index.html', wordpressSitePlan: $plan);
$assert(array() === $bothPresent['findings'], 'A shared selector passes when head and body are both emitted.');
$badResolved = $resolved;
$badResolved['pages'][0]['document_metadata']['head']['elements'][2]['resolved_url'] = 'https://example.test/tampered.js';
$assert($rejects(static fn() => WordPressSitePlan::assertValid($badResolved)), 'Strict resolver rejects a tampered head asset destination.');
$unboundScript = $result;
$unboundScript['source_reports']['compiled_site']['pages'][0]['document_metadata']['scripts'] = array();
$assert($rejects(static fn() => (new WordPressSitePlan())->fromCompilerResult($unboundScript)), 'A head script cannot bypass the owning script-loading and dynamic-reference contract.');
$dataArtifact = $artifact;
$dataArtifact['files']['index.html'] = str_replace('</head>', '<script type="application/json" id="config">{"example":"<meta data-phantom=target>"}</script></head>', $dataArtifact['files']['index.html']);
$dataResult = (new ArtifactCompiler())->compile($dataArtifact)->toArray();
$dataPlan = (new WordPressSitePlanResolver())->resolve($dataResult['source_reports']['wordpress_site_plan'], array('theme_uri' => 'https://example.test/theme', 'require_proven_dynamic_client_assets' => true));
$dataHead = DocumentHeadContext::fromPlan($dataPlan, 'index.html');
$assert(str_contains($dataHead, '<script type="application/json" id="config">{"example":"<meta data-phantom=target>"}</script>'), 'Inert head script data retains its actual inline body, bound to metadata hash.');
$phantom = $report->fromArtifact(array(array('path' => 'phantom.js', 'kind' => 'js', 'content' => 'document.querySelector("meta[data-phantom]").content="bad";')), '<html><head><meta data-phantom="target"></head><body></body></html>', '', 'index.html', wordpressSitePlan: $dataPlan);
$assert(1 === count($phantom['findings']), 'Markup examples in emitted script data never supply DOM target proof.');
$rooted = $artifact;
$rooted['entrypoint'] = 'website/index.html';
$rooted['files'] = array_combine(array_map(static fn(string $path): string => 'website/' . $path, array_keys($artifact['files'])), array_values($artifact['files']));
$rooted['files']['website/index.html'] = str_replace('url(icon.svg)', 'url(/icon.svg)', $rooted['files']['website/index.html']);
$rootedResult = (new ArtifactCompiler())->compile($rooted)->toArray();
$rootedPlan = (new WordPressSitePlanResolver())->resolve($rootedResult['source_reports']['wordpress_site_plan'], array('theme_uri' => 'https://example.test/theme', 'require_proven_dynamic_client_assets' => true));
$rootedCss = implode('', array_map(static fn(array $write): string => (string) ($write['payload']['data'] ?? ''), array_filter($rootedResult['source_reports']['wordpress_site_plan']['writes'], static fn(array $write): bool => str_ends_with($write['target_path'], '.css') && str_contains((string) ($write['payload']['data'] ?? ''), 'background-image'))));
$assert(str_contains($rootedCss, 'background-image:url({{wordpress-site-plan:asset:') && !str_contains(DocumentHeadContext::fromPlan($rootedPlan, 'website/index.html'), 'background-image'), 'Packaged website roots canonicalize absolute inline head CSS references in the written stylesheet, which the head links.');
$compiler = new ArtifactCompiler();
$shared = $compiler->prepareShared($artifact);
$staged = $compiler->compose($shared, $compiler->compilePreparedPages($shared, $compiler->preparePages($artifact, $shared)))->toArray();
$assert($plan === $staged['source_reports']['wordpress_site_plan'] && $result['source_reports']['runtime_dependency_parity'] === $staged['source_reports']['runtime_dependency_parity'], 'Whole and staged builds preserve identical head contracts and proof.');
$duplicate = $artifact;
$duplicate['files']['index.html'] = str_replace('<meta charset="utf-8">', '<meta charset="utf-8"><meta name="viewport" content="width=980">', $duplicate['files']['index.html']);
// Two viewport metas are a page-local limit: keep the last, name the drop, import.
$duplicateResult = (new ArtifactCompiler())->compile($duplicate)->toArray();
$duplicatePlan = $duplicateResult['source_reports']['wordpress_site_plan'] ?? null;
$assert(is_array($duplicatePlan), 'Duplicate authored viewports still produce a plan.');
$duplicateViewports = array_values(array_filter($duplicatePlan['pages'][0]['document_metadata']['head']['elements'] ?? array(), static fn(array $row): bool => 'meta' === $row['tag'] && 'viewport' === strtolower($row['attributes']['name'] ?? '')));
$assert(1 === count($duplicateViewports) && isset($duplicateViewports[0]['attributes']['data-selected-viewport']), 'Duplicate authored viewports keep the last one.');
$duplicateFindings = array_values(array_filter(array_merge($duplicateResult['diagnostics'], $duplicatePlan['diagnostics'] ?? array()), static fn(array $row): bool => 'document_head_duplicate_viewport' === ($row['code'] ?? '')));
$assert(1 <= count($duplicateFindings) && 'index.html' === $duplicateFindings[0]['source_path'], 'Duplicate authored viewports record a page-attributed finding.');
$dynamic = $artifact;
$dynamic['files']['index.html'] = str_replace('window.firstHeadState=', 'document.head.appendChild(document.createElement("script"));window.firstHeadState=', $dynamic['files']['index.html']);
$dynamicResult = (new ArtifactCompiler())->compile($dynamic)->toArray();
$assert($rejects(static fn() => (new WordPressSitePlanResolver())->resolve($dynamicResult['source_reports']['wordpress_site_plan'], array('theme_uri' => 'https://example.test/theme', 'require_proven_dynamic_client_assets' => true))), 'The existing dynamic-asset predicate still rejects script injection.');

$sourceOwned = $artifact;
$sourceOwned['files']['index.html'] = str_replace('</head>', '<script type="application/ld+json">{"name":"Neutral"}</script><script data-captured-runtime="true">window.capturedRuntime=true;</script></head>', $sourceOwned['files']['index.html']);
$sourceOwnedResult = (new ArtifactCompiler())->compile($sourceOwned)->toArray();
$sourceOwnedPage = $sourceOwnedResult['source_reports']['compiled_site']['pages'][0];
$sourceOwnedScript = $sourceOwnedPage['document_metadata']['scripts'][3] ?? array();
$sourceOwnedHeadScript = array_values(array_filter($sourceOwnedPage['document_metadata']['head']['elements'] ?? array(), static fn(array $row): bool => 'script' === $row['tag'] && 'true' === ($row['attributes']['data-captured-runtime'] ?? null)));
$assert(is_string($sourceOwnedScript['url'] ?? null) && '' !== $sourceOwnedScript['url'] && ($sourceOwnedScript['url'] ?? null) === ($sourceOwnedHeadScript[0]['url'] ?? null), 'Executable inline loading metadata and source-owned head declarations bind the same normalized occurrence after inert script data.');
$sourceOwnedShared = $compiler->prepareShared($sourceOwned);
$sourceOwnedStaged = $compiler->compose($sourceOwnedShared, $compiler->compilePreparedPages($sourceOwnedShared, $compiler->preparePages($sourceOwned, $sourceOwnedShared)))->toArray();
$assert($sourceOwnedResult['source_reports']['wordpress_site_plan'] === $sourceOwnedStaged['source_reports']['wordpress_site_plan'], 'Source-owned inline head bindings remain identical in staged compilation.');

// Occurrence selectors are document-local: a non-entry route must resolve its
// own normalized script, even when the entry route has the same selector.
$multiRoute = array('entrypoint' => 'index.html', 'files' => array());
foreach (array('index.html' => 'entry', 'other/index.html' => 'other') as $path => $value) {
    $multiRoute['files'][$path] = '<html><head><title>' . $value . '</title><style data-scope="' . $value . '">body{color:black}</style><script type="application/ld+json">{"name":"' . $value . '"}</script><script data-captured-runtime="true">window.capturedRuntime="' . $value . '";</script></head><body><main><h1>' . $value . '</h1></main></body></html>';
}
$multiWhole = $compiler->compile($multiRoute)->toArray();
$assert(isset($multiWhole['source_reports']['wordpress_site_plan']), 'Executable head scripts on non-entry routes produce a materializable plan: ' . json_encode($multiWhole['diagnostics'], JSON_THROW_ON_ERROR));
$scriptUrls = array();
foreach ($multiWhole['source_reports']['compiled_site']['pages'] as $page) {
    $loading = $page['document_metadata']['scripts'][1] ?? array();
    $headScripts = array_values(array_filter($page['document_metadata']['head']['elements'] ?? array(), static fn(array $row): bool => 'script' === $row['tag'] && 'true' === ($row['attributes']['data-captured-runtime'] ?? null)));
    $assert(is_string($loading['url'] ?? null) && $loading['url'] === ($headScripts[0]['url'] ?? null), 'Each route binds its executable head occurrence to the same loading declaration.');
    $scriptUrls[$page['source_path']] = $loading['url'];
}
$assert(2 === count(array_unique($scriptUrls)), 'Repeated occurrence selectors resolve distinct source-owned script assets across routes.');
$roundTrip = static fn(array $value): array => json_decode(json_encode($value, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);
$multiShared = $roundTrip($compiler->prepareShared($multiRoute));
$multiPrepared = $roundTrip($compiler->preparePages($multiRoute, $multiShared));
$multiReceipts = $roundTrip($compiler->compilePreparedPages($multiShared, $multiPrepared));
$multiStaged = $compiler->compose($multiShared, array_reverse($multiReceipts))->toArray();
$assert($multiWhole['source_reports']['wordpress_site_plan'] === ($multiStaged['source_reports']['wordpress_site_plan'] ?? null), 'Rehydrated reversed staged receipts preserve the multi-route head script plan.');

// A data: URL carries its own bytes. A head link that uses one has no write
// to bind, so it keeps the URL as written instead of failing the plan.
$dataIcon = "data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 32 32'%3E%3Crect width='32' height='32' rx='5' fill='%23123456'/%3E%3C/svg%3E";
$dataLink = $artifact;
$dataLink['files']['index.html'] = str_replace('<meta charset="utf-8">', '<meta charset="utf-8"><link rel="icon" href="' . $dataIcon . '">', $dataLink['files']['index.html']);
$dataLinkResult = (new ArtifactCompiler())->compile($dataLink)->toArray();
$dataLinkErrors = array_values(array_filter($dataLinkResult['diagnostics'], static fn(array $row): bool => 'error' === ($row['severity'] ?? '')));
$assert(array() === $dataLinkErrors && isset($dataLinkResult['source_reports']['wordpress_site_plan']), 'A head data: link produces a materializable plan: ' . json_encode($dataLinkErrors, JSON_UNESCAPED_SLASHES));
$dataLinkPlan = (new WordPressSitePlanResolver())->resolve($dataLinkResult['source_reports']['wordpress_site_plan'], array('theme_uri' => 'https://example.test/theme', 'require_proven_dynamic_client_assets' => true));
$dataLinkHead = DocumentHeadContext::fromPlan($dataLinkPlan, 'index.html');
$assert(str_starts_with($dataLinkHead, '<meta charset="utf-8">' . "\n" . '<link rel="icon" href="' . htmlspecialchars($dataIcon, ENT_QUOTES | ENT_HTML5, 'UTF-8') . '">' . "\n"), 'A head data: link keeps its source position and exact URL.');
$oversized = array('schema' => DocumentHeadContext::SCHEMA, 'elements' => array(array('tag' => 'link', 'attributes' => array('rel' => 'icon'), 'url' => 'data:,' . str_repeat('a', 1048576), 'selector' => 'link:nth-of-type(1)')));
$assert($rejects(static fn() => DocumentHeadContext::assertValid($oversized)), 'A head data: link stays within the inline data budget.');
$unboundLink = $artifact;
$unboundLink['files']['index.html'] = str_replace('<meta charset="utf-8">', '<meta charset="utf-8"><link rel="icon" href="missing-icon.png">', $unboundLink['files']['index.html']);
$unboundLinkResult = (new ArtifactCompiler())->compile($unboundLink)->toArray();
$unboundLinkPlan = $unboundLinkResult['source_reports']['wordpress_site_plan'] ?? null;
$unboundLinkFindings = array_values(array_filter($unboundLinkPlan['diagnostics'] ?? array(), static fn(array $row): bool => 'wordpress_site_plan_omitted_link_declaration' === ($row['code'] ?? '')));
$assert(is_array($unboundLinkPlan) && 'failed' !== $unboundLinkResult['status'] && 1 === count($unboundLinkFindings) && 'missing-icon.png' === $unboundLinkFindings[0]['value'] && 'index.html' === $unboundLinkFindings[0]['source_path'] && !str_contains(json_encode($unboundLinkPlan['pages'][0]['document_metadata']['head']), 'missing-icon.png'), 'A local head link without a declared write is omitted from the head and reported instead of failing the plan.');
$unboundHead = array('schema' => DocumentHeadContext::SCHEMA, 'elements' => array(array('tag' => 'link', 'attributes' => array('rel' => 'icon'), 'url' => 'missing-icon.png', 'selector' => 'link:nth-of-type(1)')));
$assert($rejects(static fn() => DocumentHeadContext::assertValid($unboundHead)), 'The strict head validator still rejects a local head link without a declared write.');

// A link with no href, or an empty one, has no URL to bind. Document link
// metadata already skips it, so the head context skips it too instead of
// failing the plan. Example: an image preload that names its image only in
// imagesrcset, which no write or route binds.
$noUrlLinks = $artifact;
$noUrlLinks['files']['index.html'] = str_replace('<meta charset="utf-8">', '<meta charset="utf-8"><link rel="preload" as="image" imagesrcset="hero-1x.png 1x, hero-2x.png 2x"><link rel="preconnect" href="">', $noUrlLinks['files']['index.html']);
$noUrlLinksResult = (new ArtifactCompiler())->compile($noUrlLinks)->toArray();
$noUrlLinksErrors = array_values(array_filter($noUrlLinksResult['diagnostics'], static fn(array $row): bool => 'error' === ($row['severity'] ?? '')));
$assert(array() === $noUrlLinksErrors && isset($noUrlLinksResult['source_reports']['wordpress_site_plan']), 'Head links without a URL produce a materializable plan: ' . json_encode($noUrlLinksErrors, JSON_UNESCAPED_SLASHES));
$noUrlLinksPage = $noUrlLinksResult['source_reports']['wordpress_site_plan']['pages'][0];
$assert(array('meta', 'meta', 'script', 'style', 'style', 'link', 'script') === array_column($noUrlLinksPage['document_metadata']['head']['elements'], 'tag') && array('link:nth-of-type(3)') === array_values(array_filter(array_column($noUrlLinksPage['document_metadata']['head']['elements'], 'selector'))), 'Head links without a URL are skipped and later links keep their source occurrence.');
$assert(array('stylesheet') === array_column($noUrlLinksPage['document_metadata']['links'], 'rel'), 'Document link metadata skips the same links.');
$noUrlLinksHead = DocumentHeadContext::fromPlan((new WordPressSitePlanResolver())->resolve($noUrlLinksResult['source_reports']['wordpress_site_plan'], array('theme_uri' => 'https://example.test/theme', 'require_proven_dynamic_client_assets' => true)), 'index.html');
$assert(3 === substr_count($noUrlLinksHead, '<link ') && 2 === substr_count($noUrlLinksHead, ' data-document-scope=') && !str_contains($noUrlLinksHead, 'imagesrcset') && !str_contains($noUrlLinksHead, 'href=""'), 'The emitted head has no link without a URL.');

// Execute the actual emitted PHP registration and template/head hook path.
// This proves bootstrap behavior, not a claim of a full WordPress installation.
$hooks = array(); $enqueuedStyles = array(); $enqueuedScripts = array();
define('ABSPATH', '/wordpress/');
define('WPINC', 'wp-includes');
function wp_normalize_path(string $path): string { return str_replace('\\', '/', $path); }
function add_action($name, $callback, $priority = 10, $accepted = 1): void { $GLOBALS['hooks'][$name][$priority][] = $callback; }
function add_filter($name, $callback, $priority = 10, $accepted = 1): void { add_action($name, $callback, $priority, $accepted); }
function remove_action($name, $callback, $priority = 10): void { foreach ($GLOBALS['hooks'][$name][$priority] ?? array() as $key => $value) if ($callback === $value) unset($GLOBALS['hooks'][$name][$priority][$key]); }
function is_singular(): bool { return true; }
function is_front_page(): bool { return true; }
function is_page(): bool { return true; }
function get_queried_object_id(): int { return 42; }
function get_post_type($id = 0): string|false { return 42 === (int) $id ? 'page' : false; }
function apply_filters($hook, $value) { return $value; }
function get_post_meta($id, $key, $single): string { return $GLOBALS['plan']['pages'][0]['reconciliation_identity']; }
function get_theme_file_uri($path): string { return 'https://example.test/theme/' . $path; }
function get_page_uri($id): string { return ''; }
function wp_enqueue_style($handle, $src = '', $deps = array(), $ver = null, $media = 'all'): void { $GLOBALS['enqueuedStyles'][$handle] = $src; }
// Like Core: no argument prints the remaining queue; printed handles leave it.
function wp_print_styles($handles = false): void { foreach (false === $handles ? array_keys($GLOBALS['enqueuedStyles']) : (array) $handles as $handle) { print '<link data-printed-handle="' . $handle . '">'; unset($GLOBALS['enqueuedStyles'][$handle]); } }
function wp_dequeue_style($handle): void { unset($GLOBALS['enqueuedStyles'][$handle]); }
function wp_register_script(...$args): void {}
function wp_enqueue_script($handle): void { $GLOBALS['enqueuedScripts'][$handle] = true; }
function _block_template_viewport_meta_tag(): void { print '<meta name="viewport" content="width=device-width, initial-scale=1" />'; }
add_action('wp_head', '_block_template_viewport_meta_tag', 0);
eval(substr($plan['writes'][$writeIndex]['payload']['data'], 5));
$template = 'template-canvas.php';
ksort($hooks['template_include']);
foreach ($hooks['template_include'] as $callbacks) foreach ($callbacks as $callback) $template = $callback($template);
ksort($hooks['wp_enqueue_scripts']);
foreach ($hooks['wp_enqueue_scripts'] as $callbacks) foreach ($callbacks as $callback) $callback();
ob_start();
ksort($hooks['wp_head']);
foreach ($hooks['wp_head'] as $callbacks) foreach ($callbacks as $callback) $callback();
$emitted = ob_get_clean();
$expected = WordPressSitePlanResolver::resolvePayload($head, WordPressSitePlanResolver::references($plan['reference_tokens'], 'https://example.test/theme'));
$headEmitted = preg_replace('/<link data-printed-handle="[^"]+">/', '', $emitted);
$assert($expected === $headEmitted && 1 === substr_count($emitted, 'name="viewport"'), 'Executed theme bootstrap emits exactly the proven head and replaces Core viewport.');
$assert(str_contains($emitted, '<link rel="stylesheet" href="https://example.test/theme/assets/index.inline-1.css" data-document-scope="phone" media="not all">') && !str_contains($emitted, '.phone{') && !str_contains($plan['writes'][$writeIndex]['payload']['data'], '.phone{'), 'A head style links its written stylesheet in place; no CSS body ships in the head or functions.php.');
$assert(str_contains(array_column($resolved['writes'], null, 'target_path')['assets/index.inline-1.css']['payload']['data'], 'background-image:url(icon.svg)'), 'The written head stylesheet resolves its asset URLs against its own target path (assets/icon.svg), not the page route.');
$assert(array() === $enqueuedScripts, 'Head-owned scripts execute only at their parser positions.');
// #2877: a head-owned stylesheet loads once, at its parser position; its
// ordered instance enqueue (#2634) is dequeued.
$headOwnedSources = array();
foreach ($plan['pages'][0]['document_metadata']['head']['elements'] as $row) if (in_array($row['tag'], array('style', 'link'), true)) foreach ($plan['assets'] as $asset) if (WordPressSitePlan::TOKEN_PREFIX . $asset['token'] . '}}' === ($row['asset_reference'] ?? null)) $headOwnedSources[] = 'https://example.test/theme/' . $asset['target_path'];
$assert(3 === count($headOwnedSources), 'The fixture head owns three stylesheets.');
foreach ($headOwnedSources as $source) $assert(!in_array($source, $enqueuedStyles, true), 'Head-owned stylesheet ' . $source . ' has no remaining WordPress enqueue.');

// #2877, #2915: the replay follows WordPress's own queue print, so the order is
// Core block styles, before-author engine support, the replayed author head,
// then after-author support. Author CSS keeps the last word over engine resets,
// and engine restatements of Core block CSS follow that Core CSS.
$ordered = array('entrypoint' => 'index.html', 'files' => array('index.html' => '<!doctype html><html><head><style id="author">.grid{display:grid}.p-8{padding:2rem}.mt-6{margin-top:1.5rem}</style></head><body><nav><ul><li><a href="/a">A</a></li><li><a href="/b">B</a></li></ul></nav><ul class="grid"><li class="p-8"><span>icon</span><h3 class="mt-6">Title</h3><p>Body</p></li></ul></body></html>'));
$plan = (new ArtifactCompiler())->compile($ordered)->toArray()['source_reports']['wordpress_site_plan'];
$hooks = array();
// Block rendering enqueues Core block styles before wp_enqueue_scripts runs.
$enqueuedStyles = array('wp-block-navigation' => 'https://example.test/wp-includes/blocks/navigation/style.min.css');
add_action('wp_head', 'wp_print_styles', 8);
eval(substr($plan['writes'][array_search('functions.php', array_column($plan['writes'], 'target_path'), true)]['payload']['data'], 5));
foreach ($hooks['template_include'] as $callbacks) foreach ($callbacks as $callback) $callback('template-canvas.php');
ksort($hooks['wp_enqueue_scripts']);
foreach ($hooks['wp_enqueue_scripts'] as $callbacks) foreach ($callbacks as $callback) $callback();
ob_start();
ksort($hooks['wp_head']);
foreach ($hooks['wp_head'] as $callbacks) foreach ($callbacks as $callback) $callback();
$orderedEmitted = ob_get_clean();
$beforeAuthor = array_values(array_filter($plan['assets'], static fn(array $asset): bool => 'before-author' === ($asset['stylesheet_placement'] ?? null)));
$assert(array() !== $beforeAuthor, 'The ordering fixture produces before-author engine support.');
$printed = strpos($orderedEmitted, 'data-printed-handle="blocks-engine-' . substr(hash('sha256', $beforeAuthor[0]['target_path']), 0, 12) . '"');
$assert(false !== $printed && false !== strpos($orderedEmitted, ' id="author"') && $printed < strpos($orderedEmitted, ' id="author"'), 'Before-author engine support prints ahead of the replayed head stylesheet.');
$core = strpos($orderedEmitted, 'data-printed-handle="wp-block-navigation"');
$assert(false !== $core && $core < $printed, 'Core block styles print ahead of before-author engine support, so engine restatements of Core CSS win.');
$afterAuthor = array_values(array_filter(WordPressSitePlan::documentStylesheets($plan, 'index.html'), static fn(array $row): bool => str_contains($row['target_path'], 'after-author')));
$assert(array() !== $afterAuthor, 'The ordering fixture produces after-author engine support.');
foreach ($afterAuthor as $row) $assert(1 === substr_count($orderedEmitted, 'data-printed-handle="' . $row['handle'] . '"') && strpos($orderedEmitted, 'data-printed-handle="' . $row['handle'] . '"') > strpos($orderedEmitted, ' id="author"'), 'After-author support ' . $row['handle'] . ' prints once, after the replayed head stylesheet.');
$assert(array() === array_filter($enqueuedStyles, static fn(string $src): bool => str_contains($src, 'inline')), 'The replayed head stylesheet has no remaining WordPress enqueue.');

// Route variants retain source order between replayed stylesheet links.
$companions = array('entrypoint' => 'index.html', 'files' => array(
    'index.html' => '<!doctype html><html><head><link id="a" rel="stylesheet" href="a.css"><meta name="fixture" content="order"><link id="b" rel="stylesheet" href="b.css"></head><body><main><p class="sample">Sample</p></main></body></html>',
    'other.html' => '<!doctype html><html><head><link id="a" rel="stylesheet" href="a.css"><link id="b" rel="stylesheet" href="b.css"></head><body><main><p><span class="sample">Sample</span></p></main></body></html>',
    'a.css' => '.sample{color:red}',
    'b.css' => '.sample{color:blue}',
));
$plan = (new ArtifactCompiler())->compile($companions)->toArray()['source_reports']['wordpress_site_plan'];
// The secondary route's span lowering produces page-specific projections;
// select its identity through the harness's first-page get_post_meta() stub.
$plan['pages'] = array_reverse($plan['pages']);
$sequence = WordPressSitePlan::documentStylesheets($plan, 'other.html');
$authorSequence = array_values(array_filter($sequence, static fn(array $row): bool => 1 === preg_match('~/[ab](?:\\.page-[^.]+)?\\.css$~', $row['target_path'])));
$assert(2 === count($authorSequence), 'The two-source fixture keeps route projections in the two source stylesheets.');
$assert(1 === preg_match('~/a\\.css$~', $authorSequence[0]['target_path']) && 1 === preg_match('~/b\\.css$~', $authorSequence[1]['target_path']), 'The enqueue order remains a.css, b.css without projected companions.');
$hooks = array(); $enqueuedStyles = array();
add_action('wp_head', 'wp_print_styles', 8);
eval(substr(array_column($plan['writes'], null, 'target_path')['functions.php']['payload']['data'], 5));
foreach ($hooks['template_include'] as $callbacks) foreach ($callbacks as $callback) $callback('template-canvas.php');
ksort($hooks['wp_enqueue_scripts']);
foreach ($hooks['wp_enqueue_scripts'] as $callbacks) foreach ($callbacks as $callback) $callback();
ob_start();
ksort($hooks['wp_head']);
foreach ($hooks['wp_head'] as $callbacks) foreach ($callbacks as $callback) $callback();
$companionHead = ob_get_clean();
$positions = array();
foreach ($authorSequence as $index => $stylesheet) {
    $needle = 'href="https://example.test/theme/' . $stylesheet['target_path'] . '"';
    $assert(1 === substr_count($companionHead, $needle), 'Each source stylesheet prints exactly once.');
    $positions[] = strpos($companionHead, $needle);
}
$sortedPositions = $positions;
sort($sortedPositions);
$assert($positions === $sortedPositions, 'Rendered stylesheet order is a.css, b.css.');

$repeated = $artifact;
$repeated['files']['index.html'] = str_replace('</body>', '<script src="check.js"></script></body>', $repeated['files']['index.html']);
$repeatedResult = (new ArtifactCompiler())->compile($repeated)->toArray();
$plan = $repeatedResult['source_reports']['wordpress_site_plan'];
$bootstrap = array_column($plan['writes'], null, 'target_path')['functions.php']['payload']['data'];
$hooks = array(); $enqueuedStyles = array(); $enqueuedScripts = array();
eval(substr($bootstrap, 5));
foreach ($hooks['template_include'] as $callbacks) foreach ($callbacks as $callback) $callback('template-canvas.php');
ksort($hooks['wp_enqueue_scripts']);
foreach ($hooks['wp_enqueue_scripts'] as $callbacks) foreach ($callbacks as $callback) $callback();
$assert(1 === count($enqueuedScripts), 'A body occurrence of a head-owned script URL still enqueues at its declared body position.');

fwrite(STDOUT, "Document head runtime contract passed: {$passes} assertions\n");
