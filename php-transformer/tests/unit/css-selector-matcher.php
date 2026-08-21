<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\CssSelectorMatcher;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\CssSelectorMatchCache;

$failures = 0;
$passes = 0;
$assert = static function (bool $condition, string $message) use (&$failures, &$passes): void {
    if ( $condition ) {
        ++$passes;
        return;
    }
    ++$failures;
    fwrite(STDERR, "FAIL: {$message}\n");
};

$dom = new DOMDocument();
libxml_use_internal_errors(true);
$dom->loadHTML('<!doctype html><div id="root"><section class="outer"><p id="one" class="item a" DATA-VALUE="alpha beta" DATA-LANG="en-US" DATA-START="prefix-value" DATA-END="value-suffix" DATA-CONTAINS="x-mid-y" DATA-QUOTED="a, b c">one</p><!-- note --><p id="two" class="item 10">two</p><input id="control" TYPE="text"><span id="target" class="final">target</span></section></div>');
libxml_clear_errors();
$byId = static fn (string $id): DOMElement => $dom->getElementById($id);
$match = static fn (string $selector, DOMElement $element, bool $suffix = false): array => CssSelectorMatcher::matches($element, CssSelectorMatcher::parse($selector), $suffix);

foreach ( array( 'p.item#one', '*#one' ) as $selector ) {
    $result = $match($selector, $byId('one'));
    $assert($result['supported'] && $result['matches'], "matches compound {$selector}");
}
$result = $match('P.outer', $byId('one'));
$assert($result['supported'] && ! $result['matches'], 'matches HTML tag names case-insensitively');

foreach ( array( '[data-value]', '[DATA-VALUE="alpha beta"]', '[DaTa-VaLuE~=beta]', '[DATA-LANG|=en]', '[data-start^=prefix]', '[DATA-END$=suffix]', '[data-contains*=mid]', '[DATA-QUOTED="a, b c"]', '[data-lang="EN-us" i]' ) as $selector ) {
    $result = $match($selector, $byId('one'));
    $assert($result['supported'] && $result['matches'], "matches case-insensitive HTML attribute name {$selector}");
}
$result = $match('[\\44 ATA-VALUE="alpha beta"]', $byId('one'));
$assert($result['supported'] && $result['matches'], 'decodes and normalizes escaped HTML attribute names');

$result = $match('div section > p + p', $byId('two'));
$assert($result['supported'] && $result['matches'], 'matches adjacent sibling through a comment');
foreach ( array( 'div>section>p~span', 'div section .final' ) as $selector ) {
    $result = $match($selector, $byId('target'));
    $assert($result['supported'] && $result['matches'], "matches combinators {$selector}");
}

foreach ( array( '.\\31 0', '#\\74 wo', '\\64 iv', '--name', '-name', '.\\31 0', '[data-quoted="a\\2c  b c"]' ) as $selector ) {
    $element = '#\\74 wo' === $selector || '.\\31 0' === $selector ? $byId('two') : ('\\64 iv' === $selector ? $byId('root') : $byId('one'));
    $result = $match($selector, $element);
    $assert($result['supported'], "accepts valid identifier grammar {$selector}");
}
$result = $match('\\64 iv', $byId('root'));
$assert($result['supported'] && $result['matches'], 'decodes an escaped type-selector start');
$result = $match('.\\31 0', $byId('two'));
$assert($result['matches'], 'decodes escaped class identifier');
$result = $match('[data-quoted="a\\2c  b c"]', $byId('one'));
$assert($result['matches'], 'decodes escaped attribute value');

$parsed = CssSelectorMatcher::parse('.final:hover:focus:active');
$assert($parsed['supported'] && array( 'start' => 6, 'end' => 25 ) === $parsed['pseudo_state_suffix_span'] && 6 === $parsed['rightmost_rewrite_end'], 'exposes dynamic pseudo suffix span and rewrite boundary');
$assert(! CssSelectorMatcher::matches($byId('target'), $parsed)['supported'], 'dynamic suffix requires caller acknowledgement');
$assert(CssSelectorMatcher::matches($byId('target'), $parsed, true)['matches'], 'caller acknowledgement permits structural dynamic-pseudo match');

foreach ( array( 'p:first-child' => 'one', 'p:nth-child(2)' => 'two', 'span:last-child' => 'target' ) as $selector => $id ) {
    $result = $match($selector, $byId($id));
    $assert($result['supported'] && $result['matches'], "matches structural pseudo-class {$selector}");
}
$assert(! $match('p:nth-child(1)', $byId('two'))['matches'], 'rejects a non-matching structural child index');
$assert($match('section > p:not(.a)', $byId('two'))['matches'] && ! $match('section > p:not(.a)', $byId('one'))['matches'], 'matches simple negated compounds through direct-child selectors');
$assert($match(':is(#root) [data-value]', $byId('one'))['matches'], 'matches a single-simple-selector is() ancestry compound');
$assert($match(':is(p.item)[data-value]', $byId('one'))['matches'], 'matches a single-simple-selector is() rightmost compound');
$assert($match(':where(#root) [data-value]', $byId('one'))['matches'], 'matches a single-simple-selector where() ancestry compound');
$assert($match(':where(p.item)[data-value]', $byId('one'))['matches'], 'matches a single-simple-selector where() rightmost compound');

foreach ( array( ':disabled', ':is(.x,.y)', ':where(.x,.y)', ':has(.x)', ':nth-child(0)', ':nth-child(2n+1)', ':nth-child()', 'p::before', 'svg|a', '.a||.b', '.a, .b', '.a[', '.a >', '-', '.-', '#-', '.10', '\\', ".a\\\n", ".a\\\r", ".a\\\r\n", "[data-value=\"a\nb\"]", "[data-value=\"a\\\nb\"]", "[data-value=\"a\\\r\nb\"]", "[data-value=\"a\\\"]" ) as $selector ) {
    $assert(! CssSelectorMatcher::parse($selector)['supported'], "rejects unsupported or malformed {$selector}");
}

foreach ( array( '[type=text]', '[TYPE="TEXT"]' ) as $selector ) {
    $result = $match($selector, $byId('control'));
    $assert(! $result['supported'], "declines unmodeled HTML enumerated value semantics {$selector}");
}
$result = $match('[TYPE="TEXT" I]', $byId('control'));
$assert($result['supported'] && $result['matches'], 'explicit ASCII-insensitive attribute flag is matched');
$result = $match('[TYPE="TEXT" s]', $byId('control'));
$assert($result['supported'] && ! $result['matches'], 'explicit case-sensitive attribute flag is matched');

$invalidUtf8 = ".\xff";
$assert(! CssSelectorMatcher::parse($invalidUtf8)['supported'], 'rejects malformed UTF-8');
$parsed = CssSelectorMatcher::parse('/*x*/ div > .final:hover');
$assert($parsed['supported'] && array( 'start' => 12, 'end' => 24 ) === $parsed['rightmost_compound_span'] && array( 'start' => 18, 'end' => 24 ) === $parsed['pseudo_state_suffix_span'], 'preserves original source spans around comments and whitespace');

$candidateCache = new CssSelectorMatchCache();
$candidateIndex = array(
    'universal' => array(
        array( 'order' => 4, 'rule' => array( 'selector' => ':not(.excluded)' ) ),
    ),
    'ids' => array( 'target' => array( array( 'order' => 1, 'rule' => array( 'selector' => '#target' ) ) ) ),
    'classes' => array( 'final' => array( array( 'order' => 2, 'rule' => array( 'selector' => '.final' ) ) ) ),
    'tags' => array( 'span' => array( array( 'order' => 3, 'rule' => array( 'selector' => 'span' ) ), array( 'order' => 5, 'rule' => array( 'selector' => 'span[data-value]' ) ) ) ),
    'attributes' => array( 'data-value' => array( array( 'order' => 0, 'rule' => array( 'selector' => '[data-value]' ) ) ) ),
    'total' => 6,
);
$candidateSelectors = array_column($candidateCache->styleRuleCandidates($byId('target'), 'test', $candidateIndex), 'selector');
$assert(array( '#target', '.final', 'span', ':not(.excluded)', 'span[data-value]' ) === $candidateSelectors, 'candidate index merges id, class, tag, and universal rules in source order while type wins over direct attributes');
$candidateSelectors = array_column($candidateCache->styleRuleCandidates($byId('one'), 'test-attributes', $candidateIndex), 'selector');
$assert(array( '[data-value]', ':not(.excluded)' ) === $candidateSelectors, 'direct attribute-presence buckets select only elements carrying the attribute');
$assert($candidateCache->matches($byId('target'), '.final', CssSelectorMatcher::parse('.final'))['matches'], 'selector result cache matches the initial class');
$byId('target')->setAttribute('class', 'changed');
$candidateCache->clear();
$assert(! $candidateCache->matches($byId('target'), '.final', CssSelectorMatcher::parse('.final'))['matches'], 'clearing the immutable revision cache observes class mutations');
$candidateSelectors = array_column($candidateCache->styleRuleCandidates($byId('target'), 'test', $candidateIndex), 'selector');
$assert(array( '#target', 'span', ':not(.excluded)', 'span[data-value]' ) === $candidateSelectors, 'clearing the immutable revision cache rebuilds class candidates after mutation');
$assert(4 === $candidateCache->candidateRulesRetained, 'candidate cache accounts for retained rule references');
$largeUniversalRules = array();
for ( $index = 0; $index < 2048; ++$index ) {
    $largeUniversalRules[] = array( 'order' => $index, 'rule' => array( 'selector' => '*' ) );
}
$largeCandidateIndex = array(
    'universal' => $largeUniversalRules,
    'ids' => array(),
    'classes' => array(),
    'tags' => array(),
    'total' => count($largeUniversalRules),
);
$candidateCache->styleRuleCandidates($byId('one'), 'large', $largeCandidateIndex);
$candidateCache->styleRuleCandidates($byId('two'), 'large', $largeCandidateIndex);
$candidateCache->styleRuleCandidates($byId('target'), 'large', $largeCandidateIndex);
$assert(2048 === $candidateCache->candidateRulesRetained, 'candidate cache evicts prior element lists before retained rule references exceed the bound');
$oversizedUniversalRules = array();
for ( $index = 0; $index < 4097; ++$index ) {
    $oversizedUniversalRules[] = array( 'order' => $index, 'rule' => array( 'selector' => '*' ) );
}
$candidateCache->styleRuleCandidates($byId('control'), 'oversized', array(
    'universal' => $oversizedUniversalRules,
    'ids' => array(),
    'classes' => array(),
    'tags' => array(),
    'total' => count($oversizedUniversalRules),
));
$assert(2048 === $candidateCache->candidateRulesRetained, 'a single oversized candidate list is returned without retention');
$candidateCache->clear();
$assert(0 === $candidateCache->candidateRulesRetained, 'clearing the immutable revision cache resets retained candidate accounting');

if ( $failures > 0 ) {
    fwrite(STDERR, "CssSelectorMatcher unit tests: {$failures} failed, {$passes} passed\n");
    exit(1);
}
fwrite(STDOUT, "CssSelectorMatcher unit tests: {$passes} passed\n");
