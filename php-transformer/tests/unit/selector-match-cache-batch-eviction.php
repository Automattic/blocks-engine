<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\Css\CssSelectorMatchCache;
use Automattic\BlocksEngine\PhpTransformer\Css\CssSelectorMatcher;

$count = 0;
$assert = static function (bool $condition, string $message) use (&$count): void {
    ++$count;
    if ( ! $condition ) {
        throw new RuntimeException($message);
    }
};

$document = new DOMDocument();
$document->loadHTML('<html><body><div id="a" class="card hero"></div><div id="b" class="card"></div></body></html>', LIBXML_NOERROR);
$element = $document->getElementById('a');
$other = $document->getElementById('b');
$selectors = array();
for ( $i = 0; $i < 64; $i++ ) {
    $selectors[] = 0 === $i % 2 ? '.card' . str_repeat(':not(.x' . $i . ')', 1) : '.missing' . $i;
}
$parsed = static fn (string $selector): array => CssSelectorMatcher::parse($selector);
// One selector checked against one element is one row of the smallest size.
$rowBytes = CssSelectorMatchCache::ROW_OVERHEAD_BYTES + 16;

// Results live in per-selector rows, and eviction drops whole rows until the
// cache is an eighth below its byte bound.
$cache = new CssSelectorMatchCache(16 * $rowBytes);
foreach ( $selectors as $selector ) {
    $result = $cache->matches($element, $selector, $parsed($selector));
    $assert(( str_starts_with($selector, '.card') ) === $result['matches'], 'result for ' . $selector);
}
$assert($cache->matchPeakBytes <= 16 * $rowBytes && $cache->matchBytes <= 16 * $rowBytes, 'the cache stays within its byte bound (' . $cache->matchPeakBytes . ' bytes)');
$assert(16 === $cache->matchPeakEntries, 'the bound holds sixteen one-result rows');
$retained = 64 - $cache->matchEvictions;
$assert($cache->matchRowEvictions === $cache->matchEvictions && $retained >= 14 && $retained <= 16, 'evictions are counted per result dropped (' . $retained . ' retained)');
foreach ( $selectors as $selector ) {
    $result = $cache->matches($element, $selector, $parsed($selector));
    $assert(( str_starts_with($selector, '.card') ) === $result['matches'], 'result after eviction for ' . $selector);
}

// A row whose results are evicted reports each of them.
$cache = new CssSelectorMatchCache($rowBytes);
$cache->matches($element, $selectors[0], $parsed($selectors[0]));
$cache->matches($other, $selectors[0], $parsed($selectors[0]));
$cache->matches($element, $selectors[1], $parsed($selectors[1]));
$assert(2 === $cache->matchEvictions && 1 === $cache->matchRowEvictions, 'dropping a row counts every result it held');

// A row hit on every use is never evicted: a hit sets its reference bit, and
// eviction moves a referenced row to the back instead of dropping it.
$cache = new CssSelectorMatchCache(8 * $rowBytes);
$cache->matches($element, $selectors[0], $parsed($selectors[0]));
for ( $i = 1; $i < 40; $i++ ) {
    $cache->matches($element, $selectors[$i], $parsed($selectors[$i]));
    $cache->matches($element, $selectors[0], $parsed($selectors[0]));
}
$assert(39 === $cache->matchHits && 40 === $cache->matchMisses, 'a result promoted on every use is never evicted');

// A bound smaller than one row still retains the row just written.
$cache = new CssSelectorMatchCache(1);
$cache->matches($element, $selectors[0], $parsed($selectors[0]));
$cache->matches($element, $selectors[1], $parsed($selectors[1]));
$cache->matches($element, $selectors[1], $parsed($selectors[1]));
$assert(1 === $cache->matchHits && 1 === $cache->matchEvictions, 'a one-byte cache keeps the latest row');

echo "Selector match cache batch eviction tests passed ({$count} assertions)\n";
