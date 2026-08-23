<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Patterns\NavigationPattern;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Patterns\PatternContext;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Patterns\PatternConversionResult;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Patterns\PatternRecursiveConverter;

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    ++$assertions;
    if (! $condition) throw new RuntimeException($message);
};

$details = (new HtmlTransformer())->transform('<details><summary>More</summary><object data="/more.pdf"></object></details>')->toArray();
$assert('core/details' === ($details['blocks'][0]['blockName'] ?? null), 'Native details remains ahead of generic container lowering.');
$assert('html_unsupported_element' === ($details['fallbacks'][0]['diagnostic_code'] ?? null), 'Details commits child fallback diagnostics through the registry result.');

$button = (new HtmlTransformer())->transform('<a href="/go" aria-label="Open" style="display:inline-block;background:#000;color:#fff;padding:1rem">Go</a>')->toArray();
$assert('core/html' === ($button['blocks'][0]['blockName'] ?? null), 'Button recognition remains ahead of generic anchor lowering when its accessible-name fallback wins.');
$assert('html_stylable_button_accessible_name_fallback' === ($button['fallbacks'][0]['diagnostic_code'] ?? null), 'Button fallback is committed by the staged registry dispatcher.');

$quoteWithNavigation = (new HtmlTransformer())->transform('<blockquote><nav><a href="/one">One</a><a href="/two">Two</a></nav></blockquote>')->toArray();
$assert('core/quote' === ($quoteWithNavigation['blocks'][0]['blockName'] ?? null), 'Quote child lowering does not re-enter unrelated registry recognizers through the navigation probe.');

$accordion = (new HtmlTransformer())->transform('<section class="faq"><div class="faq-item"><button aria-controls="a">A?</button><div id="a"><p>A.</p><object data="/a.pdf"></object></div></div><div class="faq-item"><button aria-controls="b">B?</button><div id="b"><p>B.</p></div></div></section>')->toArray();
$assert('core/accordion' === ($accordion['blocks'][0]['blockName'] ?? null), 'Accordion recognition survives an unsupported panel child.');
$assert('html_unsupported_element' === ($accordion['fallbacks'][0]['diagnostic_code'] ?? null), 'Accordion commits recursive panel diagnostics through its winning result.');

$disclosure = (new HtmlTransformer())->transform('<div><button aria-expanded="false" aria-controls="answer">Question?</button><div id="answer"><p>Answer.</p><object data="/answer.pdf"></object></div></div>')->toArray();
$assert('core/details' === ($disclosure['blocks'][0]['blockName'] ?? null), 'Disclosure recognition survives an unsupported panel child.');
$assert('html_unsupported_element' === ($disclosure['fallbacks'][0]['diagnostic_code'] ?? null), 'Disclosure commits recursive panel diagnostics through its winning result.');

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
    null,
    $navigationConverter,
    null,
    static fn (DOMElement $source): string => ''
);
$navigationResult = (new NavigationPattern())->recognize($navigationElement, $navigationContext);
$assert('core/group' === ($navigationResult?->block()['blockName'] ?? null), 'Navigation brand carrier wins with a recursively converted brand.');
$assert('brand_fallback' === ($navigationResult?->fallbacks()[0]['diagnostic_code'] ?? null), 'Navigation commits recursive brand diagnostics through its winning carrier.');

$probeContext = new PatternContext(static fn (DOMElement $source): array => array(), $innerHtml, $createBlock);
(new NavigationPattern())->recognize($navigationElement, $probeContext);
$assert(1 === $recursiveCalls, 'Navigation probe context performs no recursive conversion side effects.');

echo "pattern registry staged dispatch passed ({$assertions} assertions)\n";
