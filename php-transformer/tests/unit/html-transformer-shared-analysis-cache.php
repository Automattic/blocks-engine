<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformerAnalysisCache;
use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

$assert = static function (bool $condition, string $message): void {
    if ( ! $condition ) {
        throw new RuntimeException($message);
    }
};
$withoutDurations = static function (array $value) use (&$withoutDurations): array {
    foreach ( $value as $key => &$item ) {
        if ( 'transform_duration_ms' === $key ) {
            unset($value[$key]);
            continue;
        }
        if ( is_array($item) ) {
            $item = $withoutDurations($item);
        }
    }
    unset($item);

    return $value;
};
$withoutObservationalMetrics = static function (array $value) use (&$withoutObservationalMetrics): array {
    foreach ( $value as $key => &$item ) {
        if ( 'transform_duration_ms' === $key || str_contains((string) $key, '_cache_') ) {
            unset($value[$key]);
            continue;
        }
        if ( is_array($item) ) {
            $item = $withoutObservationalMetrics($item);
        }
    }
    unset($item);

    return $value;
};

$css = ':root{--brand:#123456}.card{display:grid;color:var(--brand)}.card img{aspect-ratio:4/3;object-fit:cover}';
$options = array('static_css' => $css, 'skip_author_stylesheet_materialization' => true);
$pages = array();
for ( $index = 0; $index < 54; ++$index ) {
    $pages[] = '<style>.page-' . $index . '{padding:' . $index . 'px}</style><main class="card page-' . $index . '"><h1>Page ' . $index . '</h1><img src="page-' . $index . '.jpg" alt="Page ' . $index . '"></main>';
}
$cache = new HtmlTransformerAnalysisCache();
$startedAt = hrtime(true);

foreach ( $pages as $html ) {
    $shared = (new HtmlTransformer(analysisCache: $cache))->transform($html, $options)->toArray();
    $isolated = (new HtmlTransformer())->transform($html, $options)->toArray();
    $assert(
        $withoutDurations($isolated) === $withoutDurations($shared),
        'Shared stylesheet analysis must preserve the isolated transform output.'
    );
}

$elapsedMs = (hrtime(true) - $startedAt) / 1_000_000;
$assert(55 === $cache->styleBuilds && 53 === $cache->styleHits, '54 pages with one shared payload must analyze 55 unique payloads and hit the shared payload 53 times.');
$assert(55 === $cache->authorSelectorBuilds && 53 === $cache->authorSelectorHits, 'Author selector analysis must reuse the shared payload independently of page-local CSS.');
$assert(55 === $cache->authorStyleRuleBuilds, 'Author stylesheet rules and declaration maps must be built once per immutable payload.');
$assert(16 === count($cache->styles) && 16 === count($cache->authorSelectorAnalyses), 'Payload analysis caches retain at most sixteen parsed graphs.');
$assert(39 === $cache->styleEvictions && 39 === $cache->authorSelectorEvictions, 'Route-local payloads must evict only least-recently-used payload analyses.');
$assert($cache->styleBytes > 0 && $cache->authorSelectorBytes > 0, 'Retained analysis byte estimates are observable.');

$semanticCases = array(
    array('html' => '<style>.card{color:blue}</style><main class="card">Cascade</main>', 'options' => array('static_css' => '.card{color:red}', 'stylesheet_payloads' => array(array('content' => '.card{color:red}')))),
    array('html' => '<style>.scope{--tone:scoped}</style><main class="card scope">Variables</main>', 'options' => array('static_css' => ':root{--tone:root}.card{color:var(--tone)}', 'stylesheet_payloads' => array(array('content' => ':root{--tone:root}'), array('content' => '.card{color:var(--tone)}')))),
    array('html' => '<style>@media (min-width:1px){.card{color:blue}}.card{color:green}</style><main class="card">Media</main>', 'options' => array('static_css' => '.card{color:red}', 'stylesheet_payloads' => array(array('content' => '.card{color:red}')))),
    array('html' => '<style>.card{color:blue}</style><main class="card">Duplicate</main>', 'options' => array('static_css' => '.card{color:red}.card{color:purple}', 'stylesheet_payloads' => array(array('content' => '.card{color:red}'), array('content' => '.card{color:purple}')))),
    array('html' => '<style>color:blue}</style><main class="card">Malformed</main>', 'options' => array('static_css' => '.card{', 'stylesheet_payloads' => array(array('content' => '.card{')))),
);
foreach ( $semanticCases as $case ) {
    $caseCache = new HtmlTransformerAnalysisCache();
    $cached = (new HtmlTransformer(analysisCache: $caseCache))->transform($case['html'], $case['options'])->toArray();
    $isolated = (new HtmlTransformer())->transform($case['html'], $case['options'])->toArray();
    $assert($withoutDurations($isolated) === $withoutDurations($cached), 'Payload composition must preserve cascade, variables, conditions, duplicate rules, provenance, and malformed-stream recovery.');
}

$manyPayloads = array();
for ( $payloadIndex = 0; $payloadIndex < 17; ++$payloadIndex ) {
    $manyPayloads[] = array('content' => '.many-' . $payloadIndex . '{padding:' . $payloadIndex . 'px}');
}
$manyPayloadHtml = '<main class="many-16">Consolidated</main>';
$manyPayloadOptions = array('static_css' => implode("\n", array_column($manyPayloads, 'content')), 'stylesheet_payloads' => $manyPayloads, 'skip_author_stylesheet_materialization' => true);
$manyPayloadCache = new HtmlTransformerAnalysisCache();
$manyPayloadShared = (new HtmlTransformer(analysisCache: $manyPayloadCache))->transform($manyPayloadHtml, $manyPayloadOptions)->toArray();
$manyPayloadIsolated = (new HtmlTransformer())->transform($manyPayloadHtml, array('static_css' => $manyPayloadOptions['static_css'], 'skip_author_stylesheet_materialization' => true))->toArray();
$assert($withoutDurations($manyPayloadIsolated) === $withoutDurations($manyPayloadShared), 'A safe stylesheet stream above the bounded payload window preserves byte-identical blocks and diagnostics.');
$assert(1 === $manyPayloadCache->styleBuilds && 0 === $manyPayloadCache->styleEvictions && 1 === count($manyPayloadCache->styles), 'A large safe stylesheet set is analyzed once as one bounded presentation stream.');

$artifactFiles = array('shared.css' => ':root{--brand:#123456}.card{display:grid;color:var(--brand)}@media (min-width:1px){.card{padding:1px}}');
for ( $index = 0; $index < 54; ++$index ) {
    $path = 0 === $index ? 'index.html' : 'pages/' . $index . '.html';
    $artifactFiles[$path] = '<link rel="stylesheet" href="' . (0 === $index ? 'shared.css' : '../shared.css') . '"><style>.page-' . $index . '{padding:' . $index . 'px}</style><main class="card page-' . $index . '"><h1>Page ' . $index . '</h1></main>';
}
$artifact = array('entrypoint' => 'index.html', 'files' => $artifactFiles);
$cachedCompiler = new ArtifactCompiler();
$cachedPlan = $cachedCompiler->compile($artifact)->toArray();
$isolatedPlan = (new ArtifactCompiler(cacheHtmlAnalysis: false))->compile($artifact)->toArray();
$cachedSitePlan = $cachedPlan['source_reports']['wordpress_site_plan'] ?? array();
$isolatedSitePlan = $isolatedPlan['source_reports']['wordpress_site_plan'] ?? array();
$assert(array() !== $cachedSitePlan && array() !== $isolatedSitePlan, 'The repeated-CSS artifact must produce canonical WordPress site plans.');
$assert($withoutDurations($isolatedSitePlan) === $withoutDurations($cachedSitePlan), 'A 54-page artifact must produce the same canonical WordPress site plan with cached and isolated stylesheet analysis.');
$artifactMetrics = $cachedCompiler->htmlAnalysisCacheMetrics();
$assert(($artifactMetrics['style_builds'] ?? 0) === 55 && ($artifactMetrics['style_hits'] ?? 0) >= 53 && ($artifactMetrics['style_bytes'] ?? 0) > 0, 'Artifact compiler exposes bounded source-payload cache build, hit, and byte counters.');
$assert(55 === ($artifactMetrics['stylesheet_asset_discoveries'] ?? null), 'A 54-page repeated-CSS artifact discovers each document stylesheet set once, plus one canonical site-ordering pass.');

$byteBudgetCache = new HtmlTransformerAnalysisCache();
$byteBudgetPayloads = array();
for ( $payloadIndex = 0; $payloadIndex < 8; ++$payloadIndex ) {
    $rules = array();
    for ( $ruleIndex = 0; $ruleIndex < 600; ++$ruleIndex ) {
        $rules[] = '.budget-' . $payloadIndex . '-noise-' . $ruleIndex . '{color:#123456;padding:1px;margin:2px}';
    }
    $rules[] = '.budget-' . $payloadIndex . '-target{color:blue}';
    $byteBudgetPayloads[] = implode('', $rules);
}
foreach ( $byteBudgetPayloads as $payloadIndex => $payload ) {
    (new HtmlTransformer(analysisCache: $byteBudgetCache))->transform('<main class="budget-' . $payloadIndex . '-target">Budget</main>', array('static_css' => $payload, 'skip_author_stylesheet_materialization' => true));
}
$rebuild = (new HtmlTransformer(analysisCache: $byteBudgetCache))->transform('<main class="budget-0-target">Budget</main>', array('static_css' => $byteBudgetPayloads[0], 'skip_author_stylesheet_materialization' => true))->toArray();
$isolatedRebuild = (new HtmlTransformer())->transform('<main class="budget-0-target">Budget</main>', array('static_css' => $byteBudgetPayloads[0], 'skip_author_stylesheet_materialization' => true))->toArray();
$assert($withoutDurations($isolatedRebuild) === $withoutDurations($rebuild), 'Byte-budget eviction rebuilds must preserve isolated canonical output.');
$styleBuildsBeforeReuse = $byteBudgetCache->styleBuilds;
$authorBuildsBeforeReuse = $byteBudgetCache->authorSelectorBuilds;
for ( $pageIndex = 1; $pageIndex < 2; ++$pageIndex ) {
    (new HtmlTransformer(analysisCache: $byteBudgetCache))->transform('<main class="budget-0-target">Budget ' . $pageIndex . '</main>', array('static_css' => $byteBudgetPayloads[0], 'skip_author_stylesheet_materialization' => true));
}
$assert($styleBuildsBeforeReuse === $byteBudgetCache->styleBuilds && $authorBuildsBeforeReuse === $byteBudgetCache->authorSelectorBuilds, 'A repeated oversized payload must hit its retained analysis instead of rebuilding it.');
$assert(1 === $byteBudgetCache->styleHits && 1 === $byteBudgetCache->authorSelectorHits, 'Oversized analysis reuse exposes the expected cross-page cache hits.');
$assert(9 === $byteBudgetCache->styleBuilds && $byteBudgetCache->styleEvictions > 0, 'The byte-bound style LRU evicts the oldest payload and deterministically rebuilds it on a later miss.');
$assert($byteBudgetCache->styleBytes <= 17825792 && $byteBudgetCache->authorSelectorBytes <= 17825792, 'Each cache retains at most one 16 MiB oversized analysis plus the 1 MiB route-local budget.');
$assert($byteBudgetCache->styleBytes + $byteBudgetCache->styleEvictedBytes > 1048576 && $byteBudgetCache->authorSelectorEvictedBytes > 0, 'Eviction counters report analysis graphs beyond the retained 1 MiB byte budget.');

$selectorCache = new HtmlTransformerAnalysisCache();
$selectorHtml = '<style>.card{color:red}.card.featured[data-state="ready"]{color:green}.card .title{font-weight:700}.card.featured .title{color:blue}</style><section class="card featured" data-state="ready"><h2 class="title">One</h2></section><section class="card featured" data-state="ready"><h2 class="title">Two</h2></section>';
(new HtmlTransformer(analysisCache: $selectorCache))->transform($selectorHtml);
$assert(4 === $selectorCache->authorSelectorClassTokenBuilds, 'Author selector matching tokenizes each immutable source element class list once.');
$assert(8 === $selectorCache->authorSelectorClassTokenHits, 'Author selector matching reuses class tokens across repeated selector checks.');
$assert(6 === $selectorCache->authorSelectorAttributeReads, 'Author selector matching records cached common-attribute reads deterministically.');
$assert(4 === $selectorCache->authorSelectorMatchResultBuilds && $selectorCache->authorSelectorMatchResultHits >= 12, 'Author selector result lists are built once and reused by later discovery passes.');

$sourceSelectorCache = new HtmlTransformerAnalysisCache();
$sourceSelectorHtml = '<style>.card{display:grid;color:red}.card.primary{gap:1rem}.card[data-kind="primary"]{padding:1rem}</style><section class="card primary" data-kind="primary"><p>Repeated source selector matching</p></section>';
$sourceSelectorResult = (new HtmlTransformer(analysisCache: $sourceSelectorCache))->transform($sourceSelectorHtml)->toArray();
$assert(6 === $sourceSelectorCache->sourceSelectorMatchExecutions && 4 === $sourceSelectorCache->sourceSelectorMatchHits, 'Indexed general style resolution executes six matcher calls and reuses four repeated element-selector results.');
$assert(5 === $sourceSelectorCache->sourceSelectorClassTokenBuilds && 10 === $sourceSelectorCache->sourceSelectorClassTokenHits && 12 === $sourceSelectorCache->sourceSelectorAttributeReads, 'General style resolution reuses immutable class and common-attribute inputs.');
$assert(4 === $sourceSelectorCache->sourceStructuralDeclarationBuilds && 8 === $sourceSelectorCache->sourceStructuralDeclarationHits, 'Structural declaration resolution builds each source state once and reuses repeated element results.');
$assert(4 === ($sourceSelectorResult['metrics']['selector_match_cache_hits'] ?? null) && 6 === ($sourceSelectorResult['metrics']['selector_match_cache_misses'] ?? null) && 0 === ($sourceSelectorResult['metrics']['selector_match_cache_evictions'] ?? null) && 3 === ($sourceSelectorResult['metrics']['selector_match_cache_peak_entries'] ?? null) && 2 === ($sourceSelectorResult['metrics']['style_rule_candidate_cache_hits'] ?? null) && 9 === ($sourceSelectorResult['metrics']['style_rule_candidate_cache_misses'] ?? null), 'Transform metrics expose selector and candidate-cache hit, miss, eviction, and peak counters without changing canonical blocks.');

$candidateCache = new HtmlTransformerAnalysisCache();
$noiseRules = array();
for ( $index = 0; $index < 100; ++$index ) {
    $noiseRules[] = '.noise-' . $index . '{color:#111}';
}
$candidateHtml = '<style>' . implode('', array_merge($noiseRules, array( '.target{color:red}', 'article{padding:1px}', '.target{color:blue}' ) )) . '</style><section class="target">Indexed target</section>';
$candidateResult = (new HtmlTransformer(analysisCache: $candidateCache))->transform($candidateHtml)->toArray();
$assert('blue' === ($candidateResult['blocks'][0]['attrs']['style']['color']['text'] ?? ''), 'Rightmost class candidates preserve duplicate matching-key cascade order.');
$assert(2 === $candidateCache->sourceStyleCandidateRuleChecks && 204 === $candidateCache->sourceStyleCandidateRulesSkipped, 'Indexed collection checks two relevant rule candidates while deterministically skipping 204 irrelevant candidates.');

$hiddenStateCache = new HtmlTransformerAnalysisCache();
$hiddenStateNoise = array();
for ( $index = 0; $index < 200; ++$index ) {
    $hiddenStateNoise[] = '.hidden-noise-' . $index . '{display:none}';
}
$hiddenStateResult = (new HtmlTransformer(analysisCache: $hiddenStateCache))->transform(
    '<main><section class="hidden-target">Retained content</section></main>',
    array('static_css' => implode('', $hiddenStateNoise) . '.hidden-target{opacity:0}')
)->toArray();
$hiddenStateFindings = $hiddenStateResult['source_reports']['html']['frozen_hidden_state'] ?? array();
$assert(array() !== $hiddenStateFindings && in_array('opacity:0', $hiddenStateFindings[0]['declarations'] ?? array(), true), 'Indexed hidden-state collection preserves canonical frozen-state findings.');
$assert(1001 === $hiddenStateCache->sourceStyleCandidateRulesSkipped && 1 === $hiddenStateCache->sourceSelectorMatchExecutions, 'Hidden-state collection skips irrelevant rightmost-selector candidates instead of matching every rule against every element.');

// One repeated hot rule across 4,097 elements forces both bounded result caches
// past capacity while later style-resolution passes refresh the same entries.
$pressureElementCount = 4097;
$pressureHtml = '<main class="pressure">' . str_repeat('<p class="pressure">cache pressure</p>', $pressureElementCount) . '</main>';
$pressureOptions = array('static_css' => '.pressure{color:#123456}', 'skip_author_stylesheet_materialization' => true);
$pressureCache = new HtmlTransformerAnalysisCache();
$pressureResult = (new HtmlTransformer(analysisCache: $pressureCache))->transform($pressureHtml, $pressureOptions)->toArray();
$pressureIsolatedResult = (new HtmlTransformer())->transform($pressureHtml, $pressureOptions)->toArray();
$pressureMetrics = $pressureResult['metrics'];
$assert(
    $withoutObservationalMetrics($pressureResult) === $withoutObservationalMetrics($pressureIsolatedResult),
    'Selector cache pressure preserves the full isolated transform envelope after observational metrics are excluded.'
);
$assert(
    $pressureResult['blocks'] === $pressureIsolatedResult['blocks']
    && $pressureResult['serialized_blocks'] === $pressureIsolatedResult['serialized_blocks']
    && $pressureResult['diagnostics'] === $pressureIsolatedResult['diagnostics'],
    'Selector cache pressure preserves canonical blocks, serialized output, and diagnostics.'
);
$assert(
    4098 === ($pressureMetrics['selector_match_cache_misses'] ?? null)
    && 1 === ($pressureMetrics['selector_match_cache_hits'] ?? null)
    && 2 === ($pressureMetrics['selector_match_cache_evictions'] ?? null)
    && 4096 === ($pressureMetrics['selector_match_cache_peak_entries'] ?? null)
    && 4099 === $pressureCache->sourceStructuralDeclarationBuilds
    && 8201 === $pressureCache->sourceStructuralDeclarationHits,
    'Read-only conversion retains selector and structural declaration results across repeated passes.'
);
$assert(
    12297 === ($pressureMetrics['style_rule_candidate_cache_misses'] ?? null)
    && 3 === ($pressureMetrics['style_rule_candidate_cache_hits'] ?? null)
    && 8201 === ($pressureMetrics['style_rule_candidate_cache_evictions'] ?? null)
    && 4096 === ($pressureMetrics['style_rule_candidate_cache_peak_entries'] ?? null)
    && 4096 === ($pressureMetrics['style_rule_candidate_cache_peak_rule_references'] ?? null),
    'Repeated hot candidate lists remain bounded while the transform exceeds both candidate-list and rule-reference capacities.'
);

fwrite(STDOUT, sprintf("HTML transformer shared analysis cache passed: 54 pages, %.1fms, style builds=%d hits=%d evictions=%d entries=%d bytes=%d; author builds=%d hits=%d evictions=%d entries=%d bytes=%d; byte budget style builds=%d evictions=%d retained=%d evicted=%d author builds=%d evictions=%d retained=%d evicted=%d\n", $elapsedMs, $cache->styleBuilds, $cache->styleHits, $cache->styleEvictions, count($cache->styles), $cache->styleBytes, $cache->authorSelectorBuilds, $cache->authorSelectorHits, $cache->authorSelectorEvictions, count($cache->authorSelectorAnalyses), $cache->authorSelectorBytes, $byteBudgetCache->styleBuilds, $byteBudgetCache->styleEvictions, $byteBudgetCache->styleBytes, $byteBudgetCache->styleEvictedBytes, $byteBudgetCache->authorSelectorBuilds, $byteBudgetCache->authorSelectorEvictions, $byteBudgetCache->authorSelectorBytes, $byteBudgetCache->authorSelectorEvictedBytes));
