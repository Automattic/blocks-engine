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
$assert(str_contains($head, '<style data-document-scope="phone" media="not all">') && str_contains($head, 'data-source-media="screen"') && str_contains($head, 'media="not all"'), 'Activation media, authored-media provenance and document scope remain distinct.');
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
$assert(str_contains(DocumentHeadContext::fromPlan($rootedPlan, 'website/index.html'), 'background-image:url({{wordpress-site-plan:asset:'), 'Packaged website roots canonicalize absolute inline head CSS references through declared assets.');
$compiler = new ArtifactCompiler();
$shared = $compiler->prepareShared($artifact);
$staged = $compiler->compose($shared, $compiler->compilePreparedPages($shared, $compiler->preparePages($artifact, $shared)))->toArray();
$assert($plan === $staged['source_reports']['wordpress_site_plan'] && $result['source_reports']['runtime_dependency_parity'] === $staged['source_reports']['runtime_dependency_parity'], 'Whole and staged builds preserve identical head contracts and proof.');
$duplicate = $artifact;
$duplicate['files']['index.html'] = str_replace('</head>', '<meta name="viewport" content="width=980"></head>', $duplicate['files']['index.html']);
$assert($rejects(static fn() => (new ArtifactCompiler())->compile($duplicate)), 'Duplicate authored viewports fail closed.');
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
function get_post_meta($id, $key, $single): string { return $GLOBALS['plan']['pages'][0]['reconciliation_identity']; }
function get_theme_file_uri($path): string { return 'https://example.test/theme/' . $path; }
function get_page_uri($id): string { return ''; }
function wp_enqueue_style($handle, ...$args): void { $GLOBALS['enqueuedStyles'][$handle] = true; }
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
$assert($expected === $emitted && 1 === substr_count($emitted, 'name="viewport"'), 'Executed theme bootstrap emits exactly the proven head and replaces Core viewport.');
$assert(str_contains($emitted, 'https://example.test/theme/assets/icon.svg'), 'Inline head CSS resolves its asset URLs against declared writes, not the page route.');
$assert(array() === $enqueuedScripts, 'Head-owned scripts execute only at their parser positions.');
$stylesheetTokens = array();
foreach ($plan['pages'][0]['document_metadata']['head']['elements'] as $row) if (in_array($row['tag'], array('style', 'link'), true)) $stylesheetTokens[] = $row['asset_reference'];
foreach ($plan['assets'] as $asset) if (in_array(WordPressSitePlan::TOKEN_PREFIX . $asset['token'] . '}}', $stylesheetTokens, true)) $assert(!isset($enqueuedStyles['blocks-engine-' . substr(hash('sha256', $asset['target_path']), 0, 12)]), 'Head-owned styles are not duplicated by WordPress enqueue.');

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
