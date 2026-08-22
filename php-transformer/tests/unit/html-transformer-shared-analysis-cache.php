<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformerAnalysisCache;

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

$selectorCache = new HtmlTransformerAnalysisCache();
$selectorHtml = '<style>.card{color:red}.card.featured[data-state="ready"]{color:green}.card .title{font-weight:700}.card.featured .title{color:blue}</style><section class="card featured" data-state="ready"><h2 class="title">One</h2></section><section class="card featured" data-state="ready"><h2 class="title">Two</h2></section>';
(new HtmlTransformer(analysisCache: $selectorCache))->transform($selectorHtml);
$assert(4 === $selectorCache->authorSelectorClassTokenBuilds, 'Author selector matching tokenizes each immutable source element class list once.');
$assert(8 === $selectorCache->authorSelectorClassTokenHits, 'Author selector matching reuses class tokens across repeated selector checks.');
$assert(6 === $selectorCache->authorSelectorAttributeReads, 'Author selector matching records cached common-attribute reads deterministically.');
$assert(4 === $selectorCache->authorSelectorMatchResultBuilds && $selectorCache->authorSelectorMatchResultHits >= 12, 'Author selector result lists are built once and reused by later discovery passes.');

$sourceSelectorCache = new HtmlTransformerAnalysisCache();
$sourceSelectorHtml = '<style>.card{display:grid;color:red}.card.primary{gap:1rem}.card[data-kind="primary"]{padding:1rem}</style><section class="card primary" data-kind="primary"><p>Repeated source selector matching</p></section>';
(new HtmlTransformer(analysisCache: $sourceSelectorCache))->transform($sourceSelectorHtml);
$assert(12 === $sourceSelectorCache->sourceSelectorMatchExecutions && 18 === $sourceSelectorCache->sourceSelectorMatchHits, 'Indexed general style resolution executes 12 matcher calls and reuses 18 repeated element-selector results.');
$assert(9 === $sourceSelectorCache->sourceSelectorClassTokenBuilds && 14 === $sourceSelectorCache->sourceSelectorClassTokenHits && 18 === $sourceSelectorCache->sourceSelectorAttributeReads, 'General style resolution reuses immutable class and common-attribute inputs.');

$candidateCache = new HtmlTransformerAnalysisCache();
$noiseRules = array();
for ( $index = 0; $index < 100; ++$index ) {
    $noiseRules[] = '.noise-' . $index . '{color:#111}';
}
$candidateHtml = '<style>' . implode('', array_merge($noiseRules, array( '.target{color:red}', 'article{padding:1px}', '.target{color:blue}' ) )) . '</style><section class="target">Indexed target</section>';
$candidateResult = (new HtmlTransformer(analysisCache: $candidateCache))->transform($candidateHtml)->toArray();
$assert('blue' === ($candidateResult['blocks'][0]['attrs']['style']['color']['text'] ?? ''), 'Rightmost class candidates preserve duplicate matching-key cascade order.');
$assert(4 === $candidateCache->sourceStyleCandidateRuleChecks && 305 === $candidateCache->sourceStyleCandidateRulesSkipped, 'Indexed collection walks check four relevant rule candidates while deterministically skipping 305 irrelevant candidates.');

fwrite(STDOUT, sprintf("HTML transformer shared analysis cache passed: 54 pages, %.1fms, style builds=%d hits=%d evictions=%d entries=%d; author builds=%d hits=%d evictions=%d entries=%d\n", $elapsedMs, $cache->styleBuilds, $cache->styleHits, $cache->styleEvictions, count($cache->styles), $cache->authorSelectorBuilds, $cache->authorSelectorHits, $cache->authorSelectorEvictions, count($cache->authorSelectorAnalyses)));
