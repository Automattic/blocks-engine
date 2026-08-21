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
$pages = array(
    '<main class="card"><h1>First</h1><img src="first.jpg" alt="First"></main>',
    '<main class="card"><h1>Second</h1><img src="second.jpg" alt="Second"></main>',
    '<main class="card"><h1>Third</h1><img src="third.jpg" alt="Third"></main>',
);
$cache = new HtmlTransformerAnalysisCache();

foreach ( $pages as $html ) {
    $shared = (new HtmlTransformer(analysisCache: $cache))->transform($html, $options)->toArray();
    $isolated = (new HtmlTransformer())->transform($html, $options)->toArray();
    $assert(
        $withoutDurations($isolated) === $withoutDurations($shared),
        'Shared stylesheet analysis must preserve the isolated transform output.'
    );
}

$assert(1 === $cache->styleBuilds, 'Identical static and inline CSS must be analyzed once across fresh page transformers.');
$assert(1 === $cache->authorSelectorBuilds, 'Identical author selectors must be parsed once across fresh page transformers.');
$assert(1 === $cache->authorStyleRuleBuilds, 'Author stylesheet rules and declaration maps must be built once rather than traversed per element.');
$assert(2 === $cache->styleHits, 'Repeated stylesheet inputs must hit the shared analysis cache for every later document.');
$assert(2 === $cache->authorSelectorHits, 'Repeated author stylesheet inputs must hit the shared selector cache for every later document.');

$alternateCss = '.alternate{color:rebeccapurple}';
(new HtmlTransformer(analysisCache: $cache))->transform('<main class="alternate">Alternate</main>', array('static_css' => $alternateCss, 'skip_author_stylesheet_materialization' => true));
(new HtmlTransformer(analysisCache: $cache))->transform('<main class="card">Again</main>', $options);
$assert(2 === $cache->styleBuilds && 3 === $cache->styleHits, 'A previously analyzed stylesheet must remain reusable after a different document stylesheet.');
$assert(2 === $cache->authorSelectorBuilds && 3 === $cache->authorSelectorHits, 'A previously parsed author stylesheet must remain reusable after a different document stylesheet.');

for ( $index = 0; $index < 7; ++$index ) {
    $class = 'eviction-' . $index;
    (new HtmlTransformer(analysisCache: $cache))->transform('<main class="' . $class . '">Eviction</main>', array('static_css' => '.' . $class . '{color:#' . $index . $index . $index . '}', 'skip_author_stylesheet_materialization' => true));
}
$evicted = (new HtmlTransformer(analysisCache: $cache))->transform('<main class="card">Evicted</main>', $options)->toArray();
$isolated = (new HtmlTransformer())->transform('<main class="card">Evicted</main>', $options)->toArray();
$assert($withoutDurations($isolated) === $withoutDurations($evicted), 'Rebuilt stylesheet analysis after eviction must preserve isolated transform output.');
$assert(8 === count($cache->styles) && 8 === count($cache->authorSelectorAnalyses), 'Shared analysis caches retain at most eight stylesheet entries.');
$assert(10 === $cache->styleBuilds && 3 === $cache->styleHits, 'The oldest stylesheet analysis is evicted after the eight-entry bound is reached.');
$assert(10 === $cache->authorSelectorBuilds && 3 === $cache->authorSelectorHits, 'The oldest author selector analysis is evicted after the eight-entry bound is reached.');

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

fwrite(STDOUT, "HTML transformer shared analysis cache passed\n");
