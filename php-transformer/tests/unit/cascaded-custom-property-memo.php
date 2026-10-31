<?php
/**
 * Cascaded custom properties are computed once per source element and reused
 * by every descendant. A reused entry must still follow the live source: a
 * sibling's own declaration must not leak, and a node restyled in place or
 * replaced at the same path is recomputed.
 */
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformerAnalysisCache;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Session\HtmlTransformerSession;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\StyleResolutionContext;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\StyleResolver;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\StylesheetAnalysisComposer;
use Automattic\BlocksEngine\PhpTransformer\WordPress\Runtime;

$failures = 0;
$assert = static function (bool $condition, string $message) use (&$failures): void {
    if ( ! $condition ) {
        ++$failures;
        fwrite(STDERR, "FAIL: {$message}\n");
    }
};

$dom = new DOMDocument();
libxml_use_internal_errors(true);
$dom->loadHTML('<?xml encoding="utf-8" ?><html><body><main class="scope"><p id="a">A</p><p id="b" class="own">B</p></main></body></html>');
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
$context->sourceStyles()->installStylesheetAnalysis(
    array(),
    ( new StylesheetAnalysisComposer($resolver, $analysisCache) )->composedStyleAnalysis(array( '.scope{--tone:red}.own{--tone:blue}' ))
);
$tone = static fn (DOMElement $element): string => $resolver->cascadedCustomProperties($element)['--tone'] ?? '';

$a = $dom->getElementById('a');
$b = $dom->getElementById('b');
$assert('blue' === $tone($b), 'an element resolves its own declaration');
$assert('red' === $tone($a), 'a sibling does not inherit that declaration');

$replacement = $dom->createElement('main');
$replacement->setAttribute('class', 'own');
$replacement->appendChild($dom->createElement('p'));
$a->parentNode->parentNode->replaceChild($replacement, $a->parentNode);
$assert('blue' === $tone($replacement->firstChild), 'a node replaced at a remembered path is recomputed');

$replacement->setAttribute('style', '--tone:green');
$assert('green' === $tone($replacement->firstChild), 'an ancestor restyled in place is recomputed');

if ( $failures > 0 ) {
    exit(1);
}
echo "cascaded custom property memo ok\n";
