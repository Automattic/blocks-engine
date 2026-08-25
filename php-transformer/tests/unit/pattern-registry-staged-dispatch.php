<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Patterns\NavigationPattern;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Patterns\NavigationPatternContext;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Patterns\DetailsPattern;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Patterns\PatternContext;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Patterns\PatternConversionResult;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Patterns\PatternRecognizerRegistry;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Patterns\PatternRecursiveConverter;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Patterns\SpacerPattern;

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    ++$assertions;
    if (! $condition) throw new RuntimeException($message);
};

$spacerDocument = new DOMDocument();
$spacerDocument->loadHTML('<div class="spacer" style="height:3rem"></div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
$spacerElement = $spacerDocument->documentElement;
if ( ! $spacerElement instanceof DOMElement ) {
    throw new RuntimeException('Spacer fixture did not produce a DOM element.');
}
$spacerContext = new PatternContext(
    static fn (DOMElement $source, array $excluded = array()): array => array( 'style' => array( 'dimensions' => array( 'minHeight' => '1rem' ) ) ),
    static fn (DOMElement $source): string => '',
    static fn (string $name, array $attrs = array(), array $children = array(), ?DOMElement $source = null): array => array( 'blockName' => $name, 'attrs' => $attrs, 'innerBlocks' => $children )
);
$spacerResult = (new PatternRecognizerRegistry(array( new SpacerPattern() )))->firstMatch($spacerElement, $spacerContext, array( SpacerPattern::class ));
$assert('core/spacer' === ($spacerResult?->block()['blockName'] ?? null), 'Spacer is dispatched directly by class through the pattern registry.');
$assert('3rem' === ($spacerResult?->block()['attrs']['height'] ?? null), 'Direct spacer dispatch preserves its native height attribute.');
$assert(! isset($spacerResult?->block()['attrs']['style']), 'Direct spacer dispatch preserves spacer ownership of serialized height.');

$layoutSpacer = (new HtmlTransformer())->transform('<div class="spacer" style="display:flex;height:3rem"></div>')->toArray();
$assert('core/spacer' === ($layoutSpacer['blocks'][0]['blockName'] ?? null), 'Spacer registry recognition remains ahead of generic authored layout lowering.');

$details = (new HtmlTransformer())->transform('<details><summary>More</summary><object data="/more.pdf"></object></details>')->toArray();
$assert('core/details' === ($details['blocks'][0]['blockName'] ?? null), 'Native details remains ahead of generic container lowering.');
$assert('html_unsupported_element' === ($details['fallbacks'][0]['diagnostic_code'] ?? null), 'Details commits child fallback diagnostics through the registry result.');

$overlappingDetails = (new HtmlTransformer())->transform('<details open><summary><button aria-expanded="false" aria-controls="answer">Native question</button></summary><div id="answer"><p>Native answer.</p></div></details>')->toArray();
$assert('core/details' === ($overlappingDetails['blocks'][0]['blockName'] ?? null), 'Native details wins before the overlapping ARIA disclosure shape.');
$assert(str_contains((string) ($overlappingDetails['blocks'][0]['attrs']['summary'] ?? ''), 'Native question'), 'Native details keeps its original summary when the ARIA disclosure shape overlaps.');
$assert(true === ($overlappingDetails['blocks'][0]['attrs']['showContent'] ?? null), 'Native details keeps its open-state attribute rather than taking the ARIA disclosure branch.');

$button = (new HtmlTransformer())->transform('<a href="/go" aria-label="Open" style="display:inline-block;background:#000;color:#fff;padding:1rem">Go</a>')->toArray();
$assert('core/html' === ($button['blocks'][0]['blockName'] ?? null), 'Button recognition remains ahead of generic anchor lowering when its accessible-name fallback wins.');
$assert('html_stylable_button_accessible_name_fallback' === ($button['fallbacks'][0]['diagnostic_code'] ?? null), 'Button fallback is committed by the staged registry dispatcher.');

$buttonContainer = (new HtmlTransformer())->transform('<div><a class="button" style="display:inline-block;background:#000;color:#fff;padding:1rem" href="/one">One</a><a class="button" style="display:inline-block;background:#000;color:#fff;padding:1rem" href="/two">Two</a></div>')->toArray();
$assert('core/buttons' === ($buttonContainer['blocks'][0]['blockName'] ?? null), 'A multi-button container wins before generic inline-wrapper lowering.');
$assert(2 === count($buttonContainer['blocks'][0]['innerBlocks'] ?? array()), 'The direct container recognizer preserves both button children.');

$declinedButtonAnchor = (new HtmlTransformer())->transform('<a href="/ordinary">Ordinary link</a>')->toArray();
$assert('core/paragraph' === ($declinedButtonAnchor['blocks'][0]['blockName'] ?? null), 'An anchor without a button signal declines to ordinary link lowering.');
$assert(array() === ($declinedButtonAnchor['fallbacks'] ?? array()), 'A declined button anchor commits no accessible-name fallback diagnostics.');

$mathOverPlaceholder = (new HtmlTransformer())->transform('<div class="placeholder media math" style="aspect-ratio: 16 / 9">$x$</div>')->toArray();
$assert('core/math' === ($mathOverPlaceholder['blocks'][0]['blockName'] ?? null), 'Math remains ahead of the overlapping placeholder-media recognizer.');
$assert('$x$' === ($mathOverPlaceholder['blocks'][0]['attrs']['content'] ?? null), 'The higher-precedence math recognizer preserves its exact content.');

$declinedSpacer = (new HtmlTransformer())->transform('<div class="spacer" style="height: 24px">Visible content</div>')->toArray();
$assert('core/paragraph' === ($declinedSpacer['blocks'][0]['blockName'] ?? null), 'A spacer candidate with visible content declines to normal element lowering.');
$assert('Visible content' === ($declinedSpacer['blocks'][0]['attrs']['content'] ?? null), 'Declining spacer recognition preserves the lower-priority conversion output.');

$quoteWithNavigation = (new HtmlTransformer())->transform('<blockquote><nav><a href="/one">One</a><a href="/two">Two</a></nav></blockquote>')->toArray();
$assert('core/quote' === ($quoteWithNavigation['blocks'][0]['blockName'] ?? null), 'Quote child lowering does not re-enter unrelated registry recognizers through the navigation probe.');

$figureQuote = (new HtmlTransformer())->transform('<figure><blockquote><p>Quoted.</p><object data="/quote.pdf"></object></blockquote><figcaption class="credit">Ada</figcaption></figure>')->toArray();
$assert('core/quote' === ($figureQuote['blocks'][0]['blockName'] ?? null), 'A figure quote wins before generic figure lowering.');
$assert('<span class="credit">Ada</span>' === ($figureQuote['blocks'][0]['attrs']['citation'] ?? null), 'The direct figure-quote recognizer preserves caption citation markup.');
$assert('html_unsupported_element' === ($figureQuote['fallbacks'][0]['diagnostic_code'] ?? null), 'A winning figure quote commits recursive child fallback diagnostics.');

$declinedQuote = (new HtmlTransformer())->transform('<blockquote><cite>Ada</cite></blockquote>')->toArray();
$assert(array() === ($declinedQuote['blocks'] ?? array()), 'A quote without visible quoted content declines normal lowering.');
$assert(array() === ($declinedQuote['fallbacks'] ?? array()), 'A declined quote commits no recursive fallback diagnostics.');

$accordion = (new HtmlTransformer())->transform('<section class="faq"><div class="faq-item"><button aria-controls="a">A?</button><div id="a"><p>A.</p><object data="/a.pdf"></object></div></div><div class="faq-item"><button aria-controls="b">B?</button><div id="b"><p>B.</p></div></div></section>')->toArray();
$assert('core/accordion' === ($accordion['blocks'][0]['blockName'] ?? null), 'Accordion recognition survives an unsupported panel child.');
$assert('html_unsupported_element' === ($accordion['fallbacks'][0]['diagnostic_code'] ?? null), 'Accordion commits recursive panel diagnostics through its winning result.');

$disclosure = (new HtmlTransformer())->transform('<div><button aria-expanded="false" aria-controls="answer">Question?</button><div id="answer"><p>Answer.</p><object data="/answer.pdf"></object></div></div>')->toArray();
$assert('core/details' === ($disclosure['blocks'][0]['blockName'] ?? null), 'Disclosure recognition survives an unsupported panel child.');
$assert('html_unsupported_element' === ($disclosure['fallbacks'][0]['diagnostic_code'] ?? null), 'Disclosure commits recursive panel diagnostics through its winning result.');

$declinedDisclosure = (new HtmlTransformer())->transform('<div><button aria-expanded="false">Question?</button><p>Ordinary content.</p></div>')->toArray();
$assert('core/details' !== ($declinedDisclosure['blocks'][0]['blockName'] ?? null), 'An ARIA toggle without a bounded panel declines disclosure lowering.');
$assert(array() === ($declinedDisclosure['fallbacks'] ?? array()), 'A declined disclosure commits no recursive fallback diagnostics.');
$assert('Question?' === ($declinedDisclosure['blocks'][0]['innerBlocks'][0]['innerBlocks'][0]['attrs']['text'] ?? null), 'A declined disclosure retains its toggle through lower-priority button lowering.');
$assert('Ordinary content.' === ($declinedDisclosure['blocks'][0]['innerBlocks'][1]['attrs']['content'] ?? null), 'A declined disclosure retains ordinary sibling content through lower-priority conversion.');

$detailsPattern = new DetailsPattern();
$detailsMethod = new ReflectionMethod(DetailsPattern::class, 'match');
$disclosureMethod = new ReflectionMethod(DetailsPattern::class, 'matchDisclosure');
$assert($detailsMethod->isPublic() && $disclosureMethod->isPublic(), 'DetailsPattern retains its released public lowering methods.');

$navigationDocument = new DOMDocument();
$previous = libxml_use_internal_errors(true);
$navigationDocument->loadHTML('<nav><a class="brand" href="/">Brand</a><ul><li><a href="/a">A</a></li><li><a href="/b">B</a></li></ul></nav>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
libxml_clear_errors();
libxml_use_internal_errors($previous);
$navigationElement = $navigationDocument->documentElement;
if ( ! $navigationElement instanceof DOMElement ) {
    throw new RuntimeException('Navigation fixture did not produce a DOM element.');
}
$innerHtml = static function (DOMElement $element): string {
    $html = '';
    foreach ( $element->childNodes as $child ) {
        $html .= $element->ownerDocument?->saveHTML($child) ?: '';
    }
    return $html;
};
$createBlock = static fn (string $name, array $attrs = array(), array $children = array(), ?DOMElement $source = null): array => array( 'blockName' => $name, 'attrs' => $attrs, 'innerBlocks' => $children );
$recursiveCalls = 0;
$navigationConverter = new PatternRecursiveConverter(
    static fn (DOMElement $source, bool $captureUnsupported): PatternConversionResult => new PatternConversionResult(array()),
    static function (DOMElement $source, bool $captureUnsupported) use (&$recursiveCalls): PatternConversionResult {
        ++$recursiveCalls;
        return new PatternConversionResult(
            array( array( 'blockName' => 'core/paragraph', 'attrs' => array( 'content' => 'Brand' ), 'innerBlocks' => array() ) ),
            array( array( 'diagnostic_code' => 'brand_fallback' ) )
        );
    },
    static fn (DOMElement $source, array $excludedTags): PatternConversionResult => new PatternConversionResult(array())
);
$navigationContext = new PatternContext(
    static fn (DOMElement $source, array $excluded = array()): array => array(),
    $innerHtml,
    $createBlock,
    $navigationConverter,
    new NavigationPatternContext(
        null,
        static fn (DOMElement $item, DOMElement $anchor): string => '',
        static fn (DOMElement $source): string => ''
    )
);
$navigationResult = (new NavigationPattern())->recognize($navigationElement, $navigationContext);
$assert('core/group' === ($navigationResult?->block()['blockName'] ?? null), 'Navigation brand carrier wins with a recursively converted brand.');
$assert('brand_fallback' === ($navigationResult?->fallbacks()[0]['diagnostic_code'] ?? null), 'Navigation commits recursive brand diagnostics through its winning carrier.');

$probeContext = new PatternContext(static fn (DOMElement $source): array => array(), $innerHtml, $createBlock);
(new NavigationPattern())->recognize($navigationElement, $probeContext);
$assert(1 === $recursiveCalls, 'Navigation probe context performs no recursive conversion side effects.');

echo "pattern registry staged dispatch passed ({$assertions} assertions)\n";
