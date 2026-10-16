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
$document->loadHTML('<html><body><div id="a" class="card hero"></div></body></html>', LIBXML_NOERROR);
$element = $document->getElementById('a');
$selectors = array();
for ( $i = 0; $i < 64; $i++ ) {
    $selectors[] = 0 === $i % 2 ? '.card' . str_repeat(':not(.x' . $i . ')', 1) : '.missing' . $i;
}
$parsed = static fn (string $selector): array => CssSelectorMatcher::parse($selector);

$cache = new CssSelectorMatchCache(16);
foreach ( $selectors as $selector ) {
    $result = $cache->matches($element, $selector, $parsed($selector));
    $assert(( str_starts_with($selector, '.card') ) === $result['matches'], 'result for ' . $selector);
}
$entries = new ReflectionProperty(CssSelectorMatchCache::class, 'matches');
$retained = $entries->getValue($cache);
$assert(count($retained) <= 16, 'the cache stays within its bound (' . count($retained) . ' entries)');
$assert(48 === $cache->matchEvictions, 'evictions are counted per entry dropped');

// The most recently used result survives eviction.
$cache = new CssSelectorMatchCache(8);
$cache->matches($element, $selectors[0], $parsed($selectors[0]));
for ( $i = 1; $i < 40; $i++ ) {
    $cache->matches($element, $selectors[$i], $parsed($selectors[$i]));
    $cache->matches($element, $selectors[0], $parsed($selectors[0]));
}
$assert(39 === $cache->matchHits, 'a result promoted on every use is never evicted');

// A bound of one still retains the latest result.
$cache = new CssSelectorMatchCache(1);
$cache->matches($element, $selectors[0], $parsed($selectors[0]));
$cache->matches($element, $selectors[1], $parsed($selectors[1]));
$cache->matches($element, $selectors[1], $parsed($selectors[1]));
$assert(1 === $cache->matchHits && 1 === $cache->matchEvictions, 'a one-entry cache keeps the latest result');

echo "Selector match cache batch eviction tests passed ({$count} assertions)\n";
