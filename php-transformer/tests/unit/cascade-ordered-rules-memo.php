<?php
/** Cascade reads reuse matching candidates across properties and follow invalidation. */
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Classification\SourceElementClassifier;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Diagnostics\SourceElementDescription;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformerAnalysisCache;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Patterns\CommerceStructureRecognizer;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Session\HtmlTransformerSession;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\StyleResolutionContext;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\StyleResolver;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\StylesheetAnalysisComposer;
use Automattic\BlocksEngine\PhpTransformer\WordPress\Runtime;

$css = '';
for ( $i = 0; $i < 36; ++$i ) {
    // Same subject candidates include both matching and nonmatching ancestors.
    $css .= ($i % 2 ? '.absent' : '.scope') . ' .item{display:block;color:rgb(' . $i . ',0,0);font-size:16px;margin-top:' . $i . 'px}';
}
$css .= '@media(max-width:600px){.scope .item{display:flex;font-size:14px}}';
$markup = '<html><body><main class="scope">' . str_repeat('<p class="item">Neutral text</p>', 5) . '</main></body></html>';
$result = (new HtmlTransformer())->transform(str_replace('<body>', '<head><style>' . $css . '</style></head><body>', $markup));
if ( ! str_contains($result->serializedBlocks, 'Neutral text') ) {
    throw new RuntimeException('Neutral page did not compile');
}

$dom = new DOMDocument();
$dom->loadHTML($markup);
$session = new HtmlTransformerSession(new Runtime(), new SourceElementDescription(new SourceElementClassifier(), new CommerceStructureRecognizer()));
$state = $session->sourceStyleResolutionState();
$context = new StyleResolutionContext(
    static fn (string $selector): ?array => $state->parsedSelector($selector),
    static fn (string $className): string => $className,
    static fn (string $url): string => $url
);
$analysisCache = new HtmlTransformerAnalysisCache();
$resolver = new StyleResolver($session, $context, $analysisCache);
$state->installStylesheetAnalysis(array(), (new StylesheetAnalysisComposer($resolver, $analysisCache))->composedStyleAnalysis(array($css)));
$cache = $state->selectorMatchCache;
$counters = static fn (): array => array($cache->candidateRuleChecks, $cache->candidateRuleHits, $cache->matchHits, $cache->matchMisses);
foreach ( $dom->getElementsByTagName('p') as $element ) {
    $first = $resolver->declaredPresentation($element, 'display');
    $before = $counters();
    foreach ( array('color', 'font-size', 'margin-top', 'display') as $property ) {
        $resolver->declaredPresentation($element, $property);
    }
    $resolver->cascade->collapsedViewportDeclarations($element, array('display', 'font-size'));
    if ( $before !== $counters() ) {
        throw new RuntimeException('Repeated cascade reads repeated selector candidate work: ' . json_encode(array($before, $counters())));
    }
    if ( $first != $resolver->declaredPresentation($element, 'display') ) {
        throw new RuntimeException('Reused cascade changed presentation');
    }
}
$element = $dom->getElementsByTagName('p')->item(0);
$element->setAttribute('class', 'other');
$state->invalidateSelectorMatches();
$changed = $resolver->declaredPresentation($element, 'display');
if ( $first == $changed ) {
    throw new RuntimeException('Cascade memo survived selector invalidation');
}
echo "cascade ordered rules memo ok\n";
