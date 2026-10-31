<?php
/**
 * Whether an interaction-state rule whose resting selector matches an element
 * declares a property family. The families are found once per element through
 * the state rules' resting-selector index, so every family question about the
 * element must still see each matching rule and no other.
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
$dom->loadHTML('<?xml encoding="utf-8" ?><html><body><nav class="menu"><a id="link" class="item" href="#">Link</a><button id="cta" class="btn">Go</button><span id="plain">x</span></nav></body></html>');
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
$css = '.menu .item:hover{color:red}'
    . 'nav > .item:focus-visible{background-color:#000}'
    . '#cta:active{font-size:20px}'
    . '.btn:hover{display:flex}'
    . '[id="plain"]:hover{border-radius:4px}';
$context->sourceStyles()->installStylesheetAnalysis(array(), ( new StylesheetAnalysisComposer($resolver, $analysisCache) )->composedStyleAnalysis(array( $css )));

$link = $dom->getElementById('link');
$cta = $dom->getElementById('cta');
$plain = $dom->getElementById('plain');
$assert($resolver->hasStateStyleFamily($link, 'color'), 'a descendant-combinator class rule reaches its subject');
$assert($resolver->hasStateStyleFamily($link, 'background'), 'a second rule on the same element adds its family');
$assert(! $resolver->hasStateStyleFamily($link, 'font-size'), 'a rule for another element adds nothing');
$assert($resolver->hasStateStyleFamily($cta, 'font-size'), 'an id rule reaches its subject');
$assert($resolver->hasStateStyleFamily($cta, 'layout'), 'a declaration counts under its responsive family');
$assert(! $resolver->hasStateStyleFamily($cta, 'color'), 'a sibling rule does not leak to another element');
$assert($resolver->hasStateStyleFamily($plain, 'border-radius'), 'an attribute rule reaches its subject');

$replacement = $dom->createElement('button');
$replacement->setAttribute('class', 'item');
$cta->parentNode->replaceChild($replacement, $cta);
$assert($resolver->hasStateStyleFamily($replacement, 'color') && ! $resolver->hasStateStyleFamily($replacement, 'font-size'), 'a node replaced at a remembered path is recomputed');

if ( $failures > 0 ) {
    exit(1);
}
echo "state style family ok\n";
