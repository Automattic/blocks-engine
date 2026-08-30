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
$pathCache = new CssSelectorMatchCache();
$pathCachedElement = $byId('target');
$pathCache->attribute($pathCachedElement, 'id');
$pathCache->attribute($pathCachedElement, 'id');
$pathCache->classTokens($pathCachedElement);
$pathCache->attributeNames($pathCachedElement);
$assert(1 === $pathCache->connectedElementKeyBuilds && 4 === $pathCache->connectedElementKeyHits, 'connected element wrappers compute their document path once per immutable cache revision');
$candidateSelectors = array_column($candidateCache->styleRuleCandidates($byId('one'), 'test-attributes', $candidateIndex), 'selector');
$assert(array( '[data-value]', ':not(.excluded)' ) === $candidateSelectors, 'direct attribute-presence buckets select only elements carrying the attribute');
$assert($candidateCache->matches($byId('target'), '.final', CssSelectorMatcher::parse('.final'))['matches'], 'selector result cache matches the initial class');
$byId('target')->setAttribute('class', 'changed');
$candidateCache->clear();
$assert(! $candidateCache->matches($byId('target'), '.final', CssSelectorMatcher::parse('.final'))['matches'], 'clearing the immutable revision cache observes class mutations');
$candidateSelectors = array_column($candidateCache->styleRuleCandidates($byId('target'), 'test', $candidateIndex), 'selector');
$assert(array( '#target', 'span', ':not(.excluded)', 'span[data-value]' ) === $candidateSelectors, 'clearing the immutable revision cache rebuilds class candidates after mutation');
$assert(4 === $candidateCache->candidateRulesRetained, 'candidate cache accounts for retained rule references');

// DOM nodes are native libxml objects exposed through temporary PHP wrappers.
// Once a wrapper is released, PHP can immediately reuse its spl_object_id() for
// a wrapper around a different node. A cache keyed by that bare integer then
// returns the first node's classes, attributes, selector result, and candidate
// rules for the second node.
$identityDom = new DOMDocument();
$identityDom->loadHTML('<!doctype html><div><i id="identity-first" class="first" data-state="first" data-first="yes"></i><b id="identity-second" class="second" data-state="second" data-second="yes"></b></div>');
$identityCache = new CssSelectorMatchCache();
$identityIndex = array(
    'universal' => array(),
    'ids' => array(),
    'classes' => array(
        'first' => array( array( 'order' => 0, 'rule' => array( 'selector' => '.first' ) ) ),
        'second' => array( array( 'order' => 1, 'rule' => array( 'selector' => '.second' ) ) ),
    ),
    'tags' => array(),
    'attributes' => array(),
    'total' => 2,
);
$identityFirst = $identityDom->getElementById('identity-first');
if ( ! $identityFirst instanceof DOMElement ) {
    throw new RuntimeException('Selector-cache identity fixture did not produce the first element.');
}
$firstWrapperId = spl_object_id($identityFirst);
$identityCache->classTokens($identityFirst);
$identityCache->attribute($identityFirst, 'data-state');
$identityCache->attributeNames($identityFirst);
$identityCache->matches($identityFirst, '.first', CssSelectorMatcher::parse('.first'));
$identityCache->styleRuleCandidates($identityFirst, 'identity', $identityIndex);
unset($identityFirst);

$identitySecond = $identityDom->getElementById('identity-second');
if ( ! $identitySecond instanceof DOMElement ) {
    throw new RuntimeException('Selector-cache identity fixture did not produce the second element.');
}
$assert($firstWrapperId === spl_object_id($identitySecond), 'identity regression fixture recycles the released DOMElement wrapper ID');
$assert(array( 'second' ) === $identityCache->classTokens($identitySecond), 'class-token cache does not alias a distinct element with a recycled wrapper ID');
$assert('second' === $identityCache->attribute($identitySecond, 'data-state'), 'attribute cache does not alias a distinct element with a recycled wrapper ID');
$assert(array( 'id', 'class', 'data-state', 'data-second' ) === $identityCache->attributeNames($identitySecond), 'attribute-name cache does not alias a distinct element with a recycled wrapper ID');
$assert(! $identityCache->matches($identitySecond, '.first', CssSelectorMatcher::parse('.first'))['matches'], 'selector-result cache does not alias a distinct element with a recycled wrapper ID');
$identitySelectors = array_column($identityCache->styleRuleCandidates($identitySecond, 'identity', $identityIndex), 'selector');
$assert(array( '.second' ) === $identitySelectors, 'candidate-rule cache does not alias a distinct element with a recycled wrapper ID');

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
$assert(4096 === $candidateCache->candidateRulesRetained, 'candidate cache retains rule references up to the bound after pressure');
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
$assert(4096 === $candidateCache->candidateRulesRetained, 'a single oversized candidate list is returned without retention');
$candidateCache->clear();
$assert(0 === $candidateCache->candidateRulesRetained, 'clearing the immutable revision cache resets retained candidate accounting');

$pressureCache = new CssSelectorMatchCache();
$hotSelector = '.cache-0';
for ( $index = 0; $index < CssSelectorMatchCache::MAX_MATCHES; ++$index ) {
    $selector = '.cache-' . $index;
    $pressureCache->matches($byId('target'), $selector, CssSelectorMatcher::parse($selector));
}
$pressureCache->matches($byId('target'), $hotSelector, CssSelectorMatcher::parse($hotSelector));
$pressureCache->matches($byId('target'), '.cache-overflow', CssSelectorMatcher::parse('.cache-overflow'));
$pressureCache->matches($byId('target'), $hotSelector, CssSelectorMatcher::parse($hotSelector));
$assert(4097 === $pressureCache->matchExecutions && 2 === $pressureCache->matchHits && 1 === $pressureCache->matchEvictions && CssSelectorMatchCache::MAX_MATCHES === $pressureCache->matchPeakEntries, 'hot selector results survive deterministic capacity pressure while the oldest cold result is evicted');

$candidatePressureDom = new DOMDocument();
$candidatePressureDom->loadHTML('<!doctype html><div id="candidate-pressure"></div>');
$candidatePressureRoot = $candidatePressureDom->getElementById('candidate-pressure');
$candidatePressureElements = array();
for ( $index = 0; $index <= CssSelectorMatchCache::MAX_CANDIDATE_LISTS; ++$index ) {
    $element = $candidatePressureDom->createElement('i');
    $candidatePressureRoot->appendChild($element);
    $candidatePressureElements[] = $element;
}
$candidatePressureCache = new CssSelectorMatchCache();
$emptyCandidateIndex = array('universal' => array(), 'ids' => array(), 'classes' => array(), 'tags' => array(), 'attributes' => array(), 'total' => 0);
for ( $index = 0; $index < CssSelectorMatchCache::MAX_CANDIDATE_LISTS; ++$index ) {
    $candidatePressureCache->styleRuleCandidates($candidatePressureElements[$index], 'pressure', $emptyCandidateIndex);
}
$candidatePressureCache->styleRuleCandidates($candidatePressureElements[0], 'pressure', $emptyCandidateIndex);
$candidatePressureCache->styleRuleCandidates($candidatePressureElements[CssSelectorMatchCache::MAX_CANDIDATE_LISTS], 'pressure', $emptyCandidateIndex);
$candidatePressureCache->styleRuleCandidates($candidatePressureElements[0], 'pressure', $emptyCandidateIndex);
$assert(4097 === $candidatePressureCache->candidateRuleMisses && 2 === $candidatePressureCache->candidateRuleHits && 1 === $candidatePressureCache->candidateRuleEvictions && CssSelectorMatchCache::MAX_CANDIDATE_LISTS === $candidatePressureCache->candidateRulePeakEntries && 0 === $candidatePressureCache->candidateRulePeakRetained, 'zero-rule candidate lists remain bounded and hot lists survive capacity pressure');

$lazyClassCache = new CssSelectorMatchCache();
$attributeOnly = $candidatePressureDom->createElement('div');
$attributeOnly->setAttribute('data-ready', 'yes');
$lazyClassCache->matches($attributeOnly, 'div[data-ready]', CssSelectorMatcher::parse('div[data-ready]'));
$assert(0 === $lazyClassCache->classTokenBuilds, 'selectors without class requirements do not tokenize source classes');
$lazyClassCache->matches($attributeOnly, '.ready', CssSelectorMatcher::parse('.ready'));
$lazyClassCache->matches($attributeOnly, '.ready.active', CssSelectorMatcher::parse('.ready.active'));
$assert(1 === $lazyClassCache->classTokenBuilds, 'class membership uses one token set across selector matches for an element');

if ( $failures > 0 ) {
    fwrite(STDERR, "CssSelectorMatcher unit tests: {$failures} failed, {$passes} passed\n");
    exit(1);
}
fwrite(STDOUT, "CssSelectorMatcher unit tests: {$passes} passed\n");
