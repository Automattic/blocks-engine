<?php
/**
 * A functional-pseudo rule (`:is`, `:where`, `:not`, `:has`) that names an
 * element's id or one of its classes supplies its declaration, the last one
 * in source order winning. The rules are found through an index of the names
 * their selectors spell, so every spelling the element answers to, and only
 * those, must still be found.
 */
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformerAnalysisCache;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Session\HtmlTransformerSession;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\StyleResolutionContext;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\StyleResolver;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\StylesheetAnalysisComposer;
use Automattic\BlocksEngine\PhpTransformer\WordPress\Runtime;

$dom = new DOMDocument();
libxml_use_internal_errors(true);
$dom->loadHTML('<?xml encoding="utf-8" ?><html><body><nav>'
    . '<a id="home" class="item lead" href="#">Home</a>'
    . '<a class="item" href="#">Other</a>'
    . '<a class="item-wide" href="#">Wide</a>'
    . '<a class="1st" href="#">Digit</a>'
    . '<a id="other" href="#">Id</a>'
    . '</nav></body></html>');
libxml_clear_errors();

$session = new HtmlTransformerSession(new Runtime(), static fn (DOMElement $node): array => array());
$context = null;
$context = new StyleResolutionContext(
    $session,
    static fn (DOMElement $node): int => 0,
    static fn (string $value): string => $value,
    static function (string $selector) use (&$context): array {
        return $context->sourceStyles()->parsedSelector($selector);
    },
    static fn (string $className): string => $className,
    static fn (string $url): string => $url,
    static fn (DOMElement $node): bool => false
);
$analysisCache = new HtmlTransformerAnalysisCache();
$resolver = new StyleResolver($context, $analysisCache);
$css = 'nav :is(.item){color:red}'
    . '.item:not(.x){color:blue}'
    . ':where(nav) .lead{color:green}'
    . ':is(.item-wide){color:navy}'
    . ':is(.\31 st){color:teal}'
    . ':where(nav) #other{color:gray}'
    . '.item{color:black}'
    . '@media (max-width:600px){:is(#home){color:orange}}';
$context->sourceStyles()->installStylesheetAnalysis(array(), ( new StylesheetAnalysisComposer($resolver, $analysisCache) )->composedStyleAnalysis(array( $css )));

$expected = array(
    // Static rules in source order, then the conditional rule naming the id.
    'Home' => 'orange',
    // A plain rule (no functional pseudo) never answers.
    'Other' => 'blue',
    // `.item` is not named by `.item-wide`, nor the reverse.
    'Wide' => 'navy',
    // A class CSS spells escaped is found in its escaped spelling.
    'Digit' => 'teal',
    'Id' => 'gray',
);
$failures = 0;
foreach ( $dom->getElementsByTagName('a') as $anchor ) {
    $actual = $resolver->unsupportedSelectorDeclaration($anchor, 'color');
    if ( $expected[ $anchor->textContent ] !== $actual ) {
        ++$failures;
        fwrite(STDERR, "FAIL: {$anchor->textContent}: expected {$expected[ $anchor->textContent ]}, got {$actual}\n");
    }
}
if ( $failures > 0 ) {
    exit(1);
}
echo "unsupported selector declaration ok\n";
