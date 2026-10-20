<?php
declare(strict_types=1);

/**
 * Selector results are stored compactly enough that a large page keeps all of them.
 *
 * A large site-builder page produces millions of distinct selector results,
 * almost all from descendant and compound selectors that must be keyed per
 * element. Stored as string-keyed arrays they cost about 350 bytes each, so
 * the entry-bounded cache evicted and recomputed them, and that cost about
 * half of compile CPU (Automattic/blocks-engine#3126).
 *
 * The cache interns element keys and (selector text, pseudo-suffix flag) pairs
 * to ints and keeps each result as two bits in a per-selector row indexed by
 * element id, under a byte bound. This contract proves that:
 *
 * (a) a deterministic large document retains every result with no eviction;
 * (b) a retained result costs less than BYTES_PER_RESULT_LIMIT bytes;
 * (c) at a tiny bound, eviction still returns the uncached matcher's results;
 * (d) clear() resets results and interning.
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\Css\CssSelectorMatchCache;
use Automattic\BlocksEngine\PhpTransformer\Css\CssSelectorMatcher;

/**
 * Bytes one retained result may cost in the result store: packed rows plus the
 * element and selector intern tables, measured as memory_get_usage() growth
 * once the per-element selector inputs (element keys, class tokens and
 * attributes, which predate this store) are warm, divided by the results
 * retained. Packed rows cost about a quarter byte per result and the intern
 * tables add a few dozen bytes per element. The string-keyed store this
 * replaces cost about 350 bytes per result.
 */
const BYTES_PER_RESULT_LIMIT = 2;

/**
 * The same measure for the whole cache, including the per-element selector
 * inputs the matcher reads through it, which cost about a kilobyte per element.
 */
const CACHE_BYTES_PER_RESULT_LIMIT = 16;

$assert = static function (bool $condition, string $message, string $detail = ''): void {
    if ( $condition ) {
        return;
    }

    fwrite(STDERR, 'FAIL: ' . $message . ('' !== $detail ? ' - ' . $detail : '') . PHP_EOL);
    exit(1);
};

// A deterministic page: 60 sections of 12 cards, each card a heading, a
// paragraph with a link and a footer span. About 3,700 elements.
$html = '<!doctype html><html><body><main class="page">';
for ( $section = 0; $section < 60; ++$section ) {
    $html .= '<section class="s' . ($section % 10) . '" data-band="' . ($section % 3) . '">';
    for ( $card = 0; $card < 12; ++$card ) {
        $tone = ($section * 12 + $card) % 7;
        $html .= '<article class="card a' . $tone . '">'
            . '<h2 class="t' . ($card % 5) . '">Title</h2>'
            . '<p class="t' . (($card + 1) % 5) . '">Body <a class="t' . (($card + 2) % 5) . '" href="#c' . $card . '">more</a></p>'
            . '<span class="t' . (($card + 3) % 5) . '">note</span>'
            . '</article>';
    }
    $html .= '</section>';
}
$html .= '</main></body></html>';
$dom = new DOMDocument();
libxml_use_internal_errors(true);
$dom->loadHTML($html);
libxml_clear_errors();
$elements = array();
foreach ( $dom->getElementsByTagName('*') as $element ) {
    $elements[] = $element;
}

// Two hundred descendant and compound selectors, plus a few subject-only ones
// that share results through the element's subject signature.
$selectors = array();
for ( $index = 0; $index < 200; ++$index ) {
    $variant = intdiv($index, 5);
    $section = $variant % 10;
    $tone = $variant % 7;
    $text = $variant % 5;
    $selectors[] = match ( $index % 5 ) {
        0 => '.s' . $section . ' .a' . $tone . ' .t' . $text,
        1 => 'section.s' . $section . ' > article.card.a' . $tone . ' p.t' . $text,
        2 => '.page .s' . $section . ' .a' . $tone . ':not(.t' . $text . ') a',
        3 => '.s' . $section . ' article.a' . $tone . ' > h2 ~ span.t' . $text,
        default => '[data-band="' . ($variant % 3) . '"] .card.a' . $tone . ' .t' . $text . ':hover',
    };
}
for ( $index = 0; $index < 10; ++$index ) {
    $selectors[] = '.t' . ($index % 5) . ($index >= 5 ? ':not(.a' . $index . ')' : '');
}
$assert(count($selectors) === count(array_unique($selectors)), 'fixture selectors are distinct');
$parsed = array();
foreach ( $selectors as $selector ) {
    $parsed[$selector] = CssSelectorMatcher::parse($selector);
    $assert($parsed[$selector]['supported'], 'fixture selector parses', $selector);
}

// (a) Every result is retained: four passes over every element and selector,
// alternating the pseudo-suffix flag on the selectors that carry one.
$cache = new CssSelectorMatchCache();
$passes = 4;
$lookups = 0;
$matched = 0;
$started = hrtime(true);
$memoryCold = memory_get_usage();
// Warm the per-element selector inputs without storing any result.
foreach ( $elements as $element ) {
    foreach ( $parsed as $selectorParse ) {
        CssSelectorMatcher::matches($element, $selectorParse, true, $cache);
    }
}
$assert(0 === $cache->matchExecutions && 0 === $cache->matchBytes, 'warming selector inputs stores no result');
$memoryBefore = memory_get_usage();
foreach ( $elements as $element ) {
    foreach ( $parsed as $selector => $selectorParse ) {
        $result = $cache->matches($element, $selector, $selectorParse, true);
        ++$lookups;
        $matched += $result['matches'] ? 1 : 0;
    }
}
$memoryAfterFill = memory_get_usage();
$retained = $cache->matchExecutions;
for ( $pass = 1; $pass < $passes; ++$pass ) {
    $passMemory = memory_get_usage();
    foreach ( $elements as $element ) {
        foreach ( $parsed as $selector => $selectorParse ) {
            $result = $cache->matches($element, $selector, $selectorParse, true);
            ++$lookups;
        }
    }
    // Hits return shared constant arrays and only flip a row's reference bit.
    $assert(memory_get_usage() - $passMemory < 1024, 'a pass of cache hits allocates nothing', (string) (memory_get_usage() - $passMemory));
}
$seconds = (hrtime(true) - $started) / 1e9;
$assert($lookups >= 3000000, 'the workload performs millions of lookups', (string) $lookups);
$assert(count($elements) >= 3500 && $retained >= 500000, 'the workload retains hundreds of thousands of distinct results', (string) $retained);
$assert(0 === $cache->matchEvictions, 'the production bound retains every result of a large document', (string) $cache->matchEvictions);
$assert($retained === $cache->matchPeakEntries && $retained === $cache->matchMisses, 'every distinct result is computed once and retained', json_encode(array( $retained, $cache->matchPeakEntries, $cache->matchMisses )));
$assert($lookups - $retained === $cache->matchHits, 'every repeated lookup is a hit');
$assert($cache->subjectMatchLookups > 0 && $retained < count($elements) * count($parsed), 'subject-only selectors share results through the subject signature');
$assert($cache->matchBytes <= CssSelectorMatchCache::MAX_MATCH_BYTES, 'retained results stay under the byte bound');

// (b) Bytes per retained result.
$bytesPerResult = ($memoryAfterFill - $memoryBefore) / $retained;
$cacheBytesPerResult = ($memoryAfterFill - $memoryCold) / $retained;
$assert($bytesPerResult < BYTES_PER_RESULT_LIMIT, 'a retained result costs less than ' . BYTES_PER_RESULT_LIMIT . ' bytes in the result store', sprintf('%.2f bytes', $bytesPerResult));
$assert($cacheBytesPerResult < CACHE_BYTES_PER_RESULT_LIMIT, 'a retained result costs less than ' . CACHE_BYTES_PER_RESULT_LIMIT . ' bytes of the whole cache', sprintf('%.2f bytes', $cacheBytesPerResult));

// Cached results equal the uncached matcher on a deterministic sample, with
// and without the pseudo-suffix flag (which interns a separate selector id).
$checked = 0;
foreach ( $elements as $elementIndex => $element ) {
    foreach ( $selectors as $selectorIndex => $selector ) {
        if ( 0 !== ($elementIndex * 31 + $selectorIndex) % 89 ) {
            continue;
        }
        foreach ( array( true, false ) as $flag ) {
            $expected = CssSelectorMatcher::matches($element, $parsed[$selector], $flag);
            $assert($expected === $cache->matches($element, $selector, $parsed[$selector], $flag), 'a cached result equals the uncached matcher', $selector);
            ++$checked;
        }
    }
}

// (c) A bound of a few rows evicts constantly and stays correct.
$tiny = new CssSelectorMatchCache(4096);
$sample = array_slice($elements, 0, 600);
$order = array();
foreach ( $sample as $elementIndex => $element ) {
    foreach ( $selectors as $selectorIndex => $selector ) {
        if ( 0 === ($elementIndex * 7 + $selectorIndex) % 5 ) {
            $order[] = array( $elementIndex, $selector, 0 === $selectorIndex % 2 );
        }
    }
}
mt_srand(3126);
shuffle($order);
foreach ( array( 0, 1 ) as $round ) {
    foreach ( $order as [$elementIndex, $selector, $flag] ) {
        $expected = CssSelectorMatcher::matches($sample[$elementIndex], $parsed[$selector], $flag);
        $assert($expected === $tiny->matches($sample[$elementIndex], $selector, $parsed[$selector], $flag), 'a result under eviction pressure equals the uncached matcher', $selector . ' round ' . $round);
    }
}
$assert($tiny->matchEvictions > 0 && $tiny->matchRowEvictions > 0 && $tiny->matchHits > 0, 'the tiny bound evicts rows and still serves hits', json_encode(array( $tiny->matchEvictions, $tiny->matchRowEvictions, $tiny->matchHits )));
$assert($tiny->matchPeakBytes <= 4096 + CssSelectorMatchCache::ROW_OVERHEAD_BYTES + 1024, 'the tiny bound is respected beyond the row being written', (string) $tiny->matchPeakBytes);

// (d) clear() drops every result and the interning behind it.
$probe = $elements[10];
$probeSelector = '.page .t9';
$probeParse = CssSelectorMatcher::parse($probeSelector);
$assert(! $cache->matches($probe, $probeSelector, $probeParse)['matches'], 'the probe does not match before mutation');
$cache->matches($probe, $probeSelector, $probeParse);
$probe->setAttribute('class', 't9');
$assert(! $cache->matches($probe, $probeSelector, $probeParse)['matches'], 'a retained result survives until clear()');
$cache->clear();
$assert(0 === $cache->matchBytes, 'clear() releases every row');
$executions = $cache->matchExecutions;
$assert($cache->matches($probe, $probeSelector, $probeParse)['matches'], 'clear() observes the source-DOM mutation');
$assert($executions + 1 === $cache->matchExecutions, 'the first lookup after clear() recomputes');
$assert($cache->matchBytes === CssSelectorMatchCache::ROW_OVERHEAD_BYTES + 16, 'one result after clear() costs one fresh row');
$interning = array();
foreach ( array( 'selectorIds', 'suffixSelectorIds', 'matchElementIds', 'subjectSignatures', 'matchRows' ) as $property ) {
    $interning[$property] = count((new ReflectionProperty(CssSelectorMatchCache::class, $property))->getValue($cache));
}
$assert(array( 'selectorIds' => 1, 'suffixSelectorIds' => 0, 'matchElementIds' => 1, 'subjectSignatures' => 0, 'matchRows' => 1 ) === $interning, 'clear() resets selector and element interning', json_encode($interning));

fwrite(STDOUT, 'Selector match cache compact contract passed ' . json_encode(array(
    'elements' => count($elements),
    'selectors' => count($selectors),
    'lookups' => $lookups,
    'retained_results' => $retained,
    'match_bytes' => $cache->matchPeakBytes,
    'bytes_per_result' => round($bytesPerResult, 3),
    'cache_bytes_per_result' => round($cacheBytesPerResult, 3),
    'uncached_checks' => $checked,
    'tiny_evictions' => $tiny->matchEvictions,
    'seconds' => round($seconds, 2),
)) . PHP_EOL);
