<?php
declare(strict_types=1);

/**
 * Selector caches share results between elements that look the same.
 *
 * A large site-builder page repeats a few hundred element shapes thousands of
 * times. The candidate-rule cache and the selector-result cache were keyed per
 * element, so a 7,500-element page rebuilt nearly every candidate list and
 * recomputed millions of evicted selector results (#3102).
 *
 * A candidate list depends only on the element's tag, id, class tokens and
 * attribute names, so it is keyed by that signature. A selector that reads only
 * its subject (one compound, no combinator, no structural pseudo-class) is keyed
 * by the subject's tag and full attribute set. Every other selector still reads
 * the element's position and stays keyed per element.
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\Css\CssSelectorMatchCache;
use Automattic\BlocksEngine\PhpTransformer\Css\CssSelectorMatcher;

$assert = static function (bool $condition, string $message, string $detail = ''): void {
    if ( $condition ) {
        return;
    }

    fwrite(STDERR, 'FAIL: ' . $message . ('' !== $detail ? ' - ' . $detail : '') . PHP_EOL);
    exit(1);
};

$load = static function (string $html): DOMDocument {
    $dom = new DOMDocument();
    libxml_use_internal_errors(true);
    $dom->loadHTML($html);
    libxml_clear_errors();
    return $dom;
};

/**
 * Bucket rules by their rightmost compound the way the resolvers do.
 *
 * @param list<string> $selectors
 * @return array{universal: list<array{order: int, rule: array<string, mixed>}>, ids: array<string, list<array{order: int, rule: array<string, mixed>}>>, classes: array<string, list<array{order: int, rule: array<string, mixed>}>>, tags: array<string, list<array{order: int, rule: array<string, mixed>}>>, attributes: array<string, list<array{order: int, rule: array<string, mixed>}>>, total: int}
 */
$buildIndex = static function (array $selectors): array {
    $index = array( 'universal' => array(), 'ids' => array(), 'classes' => array(), 'tags' => array(), 'attributes' => array(), 'total' => count($selectors) );
    foreach ( $selectors as $order => $selector ) {
        $parsed = CssSelectorMatcher::parse($selector);
        $rightmost = $parsed['supported'] ? $parsed['compounds'][array_key_last($parsed['compounds'])] : null;
        $entry = array( 'order' => $order, 'rule' => array( 'selector' => $selector, 'parsed' => $parsed ) );
        if ( is_array($rightmost) && array() !== $rightmost['ids'] ) {
            $index['ids'][$rightmost['ids'][0]][] = $entry;
        } elseif ( is_array($rightmost) && array() !== $rightmost['classes'] ) {
            $index['classes'][$rightmost['classes'][0]][] = $entry;
        } elseif ( is_array($rightmost) && null !== $rightmost['type'] ) {
            $index['tags'][strtolower($rightmost['type'])][] = $entry;
        } elseif ( is_array($rightmost) && array() !== $rightmost['attributes'] ) {
            $index['attributes'][$rightmost['attributes'][0]['name']][] = $entry;
        } else {
            $index['universal'][] = $entry;
        }
    }
    return $index;
};

// Two list items with equal tag, classes and attributes share one candidate
// list and one result for a selector that reads only the subject.
$dom = $load('<!doctype html><ul><li class="item card" data-kind="x">one</li><li class="item card" data-kind="x">two</li><li class="item card" data-kind="y">three</li><li class="card  item" data-kind="x">four</li></ul><div class="a"><span class="b">in</span></div><span class="b">out</span>');
$items = iterator_to_array($dom->getElementsByTagName('li'));
$spans = iterator_to_array($dom->getElementsByTagName('span'));
$index = $buildIndex(array( '.item', 'li:first-child', '.a .b', 'li:not(:first-child)', '[data-kind="x"]', '.card:not(.missing)', 'li' ));

$cache = new CssSelectorMatchCache();
$first = $cache->styleRuleCandidates($items[0], 'rules', $index);
$second = $cache->styleRuleCandidates($items[1], 'rules', $index);
$assert(1 === $cache->candidateRuleMisses && 1 === $cache->candidateRuleHits, 'equal-signature elements share one candidate-list entry', json_encode(array( $cache->candidateRuleMisses, $cache->candidateRuleHits )));
$assert($first === $second, 'a shared candidate list is the list either element would collect');
$cache->styleRuleCandidates($items[2], 'rules', $index);
$assert(1 === $cache->candidateRuleMisses && 2 === $cache->candidateRuleHits, 'attribute values do not split candidate lists, which read only attribute names');
$cache->styleRuleCandidates($items[3], 'rules', $index);
$assert(1 === $cache->candidateRuleMisses && 3 === $cache->candidateRuleHits, 'class token order does not split candidate lists');

$cache = new CssSelectorMatchCache();
foreach ( array( '.item', '[data-kind="x"]', '.card:not(.missing)' ) as $selector ) {
    $before = $cache->matchExecutions;
    $one = $cache->matches($items[0], $selector, CssSelectorMatcher::parse($selector));
    $two = $cache->matches($items[1], $selector, CssSelectorMatcher::parse($selector));
    $assert($one['matches'] && $two['matches'], 'subject-only selector matches both items', $selector);
    $assert(1 === $cache->matchExecutions - $before, 'equal-signature elements share one subject-only match entry', $selector);
}
$assert(3 === $cache->matchHits, 'each shared subject-only result is a cache hit for the second element');
$three = $cache->matches($items[2], '[data-kind="x"]', CssSelectorMatcher::parse('[data-kind="x"]'));
$assert(! $three['matches'] && 4 === $cache->matchExecutions, 'attribute values split subject-only results');
$four = $cache->matches($items[3], '[class="item card"]', CssSelectorMatcher::parse('[class="item card"]'));
$assert(! $four['matches'] && 5 === $cache->matchExecutions, 'raw attribute values, not class tokens, key subject-only results');

$structural = array(
    'li:first-child' => array( $items, array( true, false, false, false ) ),
    'li:not(:first-child)' => array( $items, array( false, true, true, true ) ),
    ':not(:first-child)' => array( $items, array( false, true, true, true ) ),
    '.a .b' => array( $spans, array( true, false ) ),
    'div > .b' => array( $spans, array( true, false ) ),
    ':is(.a .b)' => array( $spans, array( true, false ) ),
    '.b:not(.a .b)' => array( $spans, array( false, true ) ),
);
foreach ( $structural as $selector => list($elements, $expected) ) {
    $assert(! CssSelectorMatcher::dependsOnlyOnSubject(CssSelectorMatcher::parse($selector)), 'structural selector is not subject-only', $selector);
    $before = $cache->matchExecutions;
    foreach ( $elements as $position => $element ) {
        $result = $cache->matches($element, $selector, CssSelectorMatcher::parse($selector));
        $assert($expected[$position] === $result['matches'], 'structural selector still matches per element', $selector . ' #' . $position);
    }
    $assert(count($elements) === $cache->matchExecutions - $before, 'structural selector results stay keyed per element', $selector);
}
foreach ( array( '.item', 'li.item[data-kind]', '.card:not(.missing)', ':is(.item, .card)', ':where(.item)', '.item:hover' ) as $selector ) {
    $assert(CssSelectorMatcher::dependsOnlyOnSubject(CssSelectorMatcher::parse($selector)), 'single-compound selector is subject-only', $selector);
}

$cache->clear();
$items[1]->setAttribute('class', 'other');
$assert(! $cache->matches($items[1], '.item', CssSelectorMatcher::parse('.item'))['matches'], 'clear() drops shared subject results after a mutation');
$assert(! in_array('.item', array_column($cache->styleRuleCandidates($items[1], 'rules', $index), 'selector'), true), 'clear() drops shared candidate lists after a mutation');

// A large deterministic document: thousands of elements built from a few
// hundred shapes, resolved against a few hundred rules.
$tags = array( 'div', 'section', 'p', 'span', 'li', 'a' );
$classCount = 48;
$shapes = array();
for ( $shape = 0; $shape < 300; ++$shape ) {
    $tag = $tags[$shape % count($tags)];
    $classes = array( 'c' . ($shape % $classCount), 'c' . (intdiv($shape, 7) % $classCount) );
    $shapes[] = array( $tag, implode(' ', array_unique($classes)), 0 === $shape % 5 ? 'v' . ($shape % 3) : null );
}
$html = '<!doctype html><body>';
$elements = 0;
for ( $group = 0; $group < 120; ++$group ) {
    $html .= '<div class="group g' . ($group % 4) . '">';
    for ( $member = 0; $member < 50; ++$member ) {
        list($tag, $class, $variant) = $shapes[($group * 37 + $member * 11) % count($shapes)];
        $html .= '<' . $tag . ' class="' . $class . '"' . (null === $variant ? '' : ' data-variant="' . $variant . '"') . '>x</' . $tag . '>';
        ++$elements;
    }
    $html .= '</div>';
}
$html .= '</body>';
$dom = $load($html);
$selectors = array();
for ( $rule = 0; $rule < 320; ++$rule ) {
    $class = 'c' . ($rule % $classCount);
    $selectors[] = match ( $rule % 8 ) {
        0 => '.' . $class,
        1 => $tags[$rule % count($tags)] . '.' . $class,
        2 => '.g' . ($rule % 4) . ' .' . $class,
        3 => '.' . $class . ':first-child',
        4 => '[data-variant="v' . ($rule % 3) . '"]',
        5 => '.' . $class . ':not(.c' . (($rule + 1) % $classCount) . ')',
        6 => $tags[$rule % count($tags)],
        default => '.group > .' . $class . ':nth-child(' . (1 + $rule % 5) . ')',
    // Every rule has its own selector text, as authored stylesheets mostly do.
    } . ':not(.r' . $rule . ')';
}
$index = $buildIndex($selectors);

$documentElements = array();
foreach ( $dom->getElementsByTagName('*') as $element ) {
    $documentElements[] = $element;
}
$signatures = array();
foreach ( $documentElements as $element ) {
    $classes = preg_split('/\s+/', trim($element->getAttribute('class'))) ?: array();
    sort($classes);
    $names = array();
    foreach ( $element->attributes as $attribute ) {
        $names[] = $attribute->nodeName;
    }
    sort($names);
    $signatures[serialize(array( $element->tagName, $classes, $names ))] = true;
}

// The candidate list an element collects without a cache, in cascade order.
$reference = static function (DOMElement $element) use ($index): array {
    $candidates = $index['universal'];
    foreach ( preg_split('/[\x09\x0A\x0C\x0D\x20]+/', trim($element->getAttribute('class'))) ?: array() as $class ) {
        $candidates = array_merge($candidates, $index['classes'][$class] ?? array());
    }
    $candidates = array_merge($candidates, $index['tags'][strtolower($element->tagName)] ?? array());
    foreach ( $element->attributes as $attribute ) {
        $candidates = array_merge($candidates, $index['attributes'][strtolower($attribute->nodeName)] ?? array());
    }
    usort($candidates, static fn (array $left, array $right): int => $left['order'] <=> $right['order']);
    return array_column($candidates, 'rule');
};

$cache = new CssSelectorMatchCache();
$lookups = 0;
$matched = 0;
$started = hrtime(true);
for ( $pass = 0; $pass < 2; ++$pass ) {
    foreach ( $documentElements as $element ) {
        foreach ( $cache->styleRuleCandidates($element, 'rules', $index) as $rule ) {
            ++$lookups;
            $matched += (int) $cache->matches($element, $rule['selector'], $rule['parsed'])['matches'];
        }
    }
}
$seconds = (hrtime(true) - $started) / 1e9;

foreach ( $documentElements as $position => $element ) {
    if ( 0 !== $position % 7 ) {
        continue;
    }
    $rules = $cache->styleRuleCandidates($element, 'rules', $index);
    $assert($reference($element) === $rules, 'shared candidate lists keep cascade order', (string) $position);
    foreach ( $rules as $rule ) {
        $expected = CssSelectorMatcher::matches($element, $rule['parsed']);
        $assert($expected === $cache->matches($element, $rule['selector'], $rule['parsed']), 'shared selector results equal uncached matching', $rule['selector'] . ' #' . $position);
    }
}

$distinct = count($signatures);
$counters = array(
    'elements' => count($documentElements),
    'distinct_signatures' => $distinct,
    'rules' => count($selectors),
    'candidate_misses' => $cache->candidateRuleMisses,
    'candidate_hits' => $cache->candidateRuleHits,
    'candidate_evictions' => $cache->candidateRuleEvictions,
    'match_lookups' => $lookups,
    'match_executions' => $cache->matchExecutions,
    'match_hits' => $cache->matchHits,
    'match_evictions' => $cache->matchEvictions,
    'matched' => $matched,
    'seconds' => round($seconds, 3),
);
$assert($elements >= 6000 && $distinct >= 200 && $distinct <= 400, 'the fixture has thousands of elements and a few hundred signatures', json_encode($counters));
$assert($cache->candidateRuleMisses <= $distinct, 'candidate-list misses fall to the distinct-signature count', json_encode($counters));
$assert(0 === $cache->candidateRuleEvictions, 'every distinct signature fits the candidate bound', json_encode($counters));
$assert($cache->matchExecutions * 3 < $lookups, 'subject-only selector results are shared across equal-signature elements', json_encode($counters));

fwrite(STDOUT, 'Selector match cache signature contract passed ' . json_encode($counters) . PHP_EOL);
