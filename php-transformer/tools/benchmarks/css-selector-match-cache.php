<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\Css\CssSelectorMatchCache;
use Automattic\BlocksEngine\PhpTransformer\Css\CssSelectorMatcher;

const HOT_SELECTORS = 1024;
const COLD_SELECTORS = 8192;
// Hold a quarter of the distinct selectors. Each is one one-result row.
const BOUND_ROWS = 2304;
const ROW_BYTES = CssSelectorMatchCache::ROW_OVERHEAD_BYTES + 16;

$dom = new DOMDocument();
$dom->loadHTML('<!doctype html><div id="target" class="hot"></div>');
$element = $dom->getElementById('target');
$selectors = array();
for ( $index = 0; $index < HOT_SELECTORS; ++$index ) {
    $selectors[] = '.hot-' . $index;
}

$workload = array();
for ( $index = 0; $index < COLD_SELECTORS; ++$index ) {
    $workload[] = '.cold-' . $index;
    $workload[] = $selectors[$index % HOT_SELECTORS];
}

$legacyResults = array();
$legacyExecutions = 0;
foreach ( $workload as $selector ) {
    if ( isset($legacyResults[$selector]) ) {
        continue;
    }
    if ( count($legacyResults) >= BOUND_ROWS ) {
        $legacyResults = array();
    }
    $legacyResults[$selector] = CssSelectorMatcher::matches($element, CssSelectorMatcher::parse($selector));
    ++$legacyExecutions;
}

$cache = new CssSelectorMatchCache(BOUND_ROWS * ROW_BYTES);
foreach ( $workload as $selector ) {
    $cache->matches($element, $selector, CssSelectorMatcher::parse($selector));
}

if ( $cache->matchExecutions >= $legacyExecutions || $cache->matchPeakBytes > BOUND_ROWS * ROW_BYTES ) {
    throw new RuntimeException('Bounded cache did not reduce matcher executions while preserving its byte bound.');
}

fwrite(STDOUT, json_encode(array(
    'workload_accesses' => count($workload),
    'legacy_clear_at_capacity' => array('matcher_executions' => $legacyExecutions),
    'bounded_clock' => array(
        'matcher_executions' => $cache->matchExecutions,
        'cache_misses' => $cache->matchMisses,
        'cache_hits' => $cache->matchHits,
        'evictions' => $cache->matchEvictions,
        'row_evictions' => $cache->matchRowEvictions,
        'peak_entries' => $cache->matchPeakEntries,
        'peak_bytes' => $cache->matchPeakBytes,
    ),
), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
