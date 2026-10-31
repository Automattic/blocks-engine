<?php
/**
 * An element's matching rules are found once per collection and reused. A
 * reused list must still follow the live source: a sibling's rules do not
 * leak, a node replaced at a remembered path is recomputed, invalidating
 * selector matches after a class change drops the list, and a detached clone
 * that has been released does not poison the next clone at its path.
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
$dom->loadHTML('<?xml encoding="utf-8" ?><html><body><main><p class="plain">A</p><p id="b" class="plain strong">B</p></main></body></html>');
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
    ( new StylesheetAnalysisComposer($resolver, $analysisCache) )->composedStyleAnalysis(array( '.plain{color:red}#b.strong{color:blue}' ))
);
$specificity = static fn (DOMElement $element): array => $resolver->authorNormalDeclarationSpecificity($element, array( 'color' ));

$paragraphs = $dom->getElementsByTagName('p');
$a = $paragraphs->item(0);
$b = $paragraphs->item(1);
$assert(array( 1, 1, 0 ) === $specificity($b), 'an element sees its own most specific rule');
$assert(array( 0, 1, 0 ) === $specificity($a), 'a sibling does not see that rule');

$replacement = $dom->createElement('p');
$replacement->setAttribute('id', 'b');
$replacement->setAttribute('class', 'strong');
$a->parentNode->replaceChild($replacement, $a);
$assert(array( 1, 1, 0 ) === $specificity($replacement), 'a node replaced at a remembered path is recomputed');

$b->setAttribute('class', 'other');
$context->sourceStyles()->invalidateSelectorMatches();
$assert(array( 0, 0, 0 ) === $specificity($b), 'a class change followed by invalidation is recomputed');

$cloneSpecificity = static function () use ($dom, $specificity): array {
    $clone = $dom->getElementById('b')->cloneNode(true);
    $clone->appendChild($dom->createElement('span'))->setAttribute('class', 'plain');
    return $specificity($clone->firstChild->nextSibling);
};
$assert(array( 0, 1, 0 ) === $cloneSpecificity(), 'a detached clone resolves its own rules');
$assert(array( 0, 1, 0 ) === $cloneSpecificity(), 'a released clone does not stand in for the next one');

if ( $failures > 0 ) {
    exit(1);
}
echo "matched style rules memo ok\n";
