<?php
/**
 * Collapsed-viewport questions ask for a few properties (display, position,
 * flex flow) of many elements. A rule that declares none of them cannot
 * contribute a fact, so its selectors must not be matched against each
 * element asked about: on site-builder captures with thousands of author
 * rules that matching dominated page compilation.
 *
 * The contract: only rules declaring a requested property are matched, and
 * the declarations returned are unchanged.
 */
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\Css\CssSelectorMatcher;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlCompilation;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\AuthorStyleAnalysis;

$elements = 20;
$colorRules = 200;
$document = new DOMDocument();
$document->loadHTML('<!doctype html><html><body>' . str_repeat('<div class="item"></div>', $elements) . '</body></html>');
$body = $document->getElementsByTagName('body')->item(0);
$rule = static fn (int $order, string $selector, array $declarations, array $conditions = array()): array => array(
    'order' => $order,
    'declarations' => $declarations,
    'conditions' => $conditions,
    'selectors' => array(array('selector' => $selector, 'parsed' => CssSelectorMatcher::parse($selector))),
);
$rules = array();
for ($index = 0; $index < $colorRules; ++$index) {
    $rules[] = $rule($index, '.item:nth-child(' . ($index + 1) . ')', array('color' => '#123456'));
}
$rules[] = $rule($colorRules, '.item', array('display' => 'flex'));
$rules[] = $rule($colorRules + 1, '.item', array('display' => 'none'), array('@media (max-width: 767px)'));

$compilation = new HtmlCompilation();
$reflection = new ReflectionClass($compilation);
$authorStyles = new AuthorStyleAnalysis('', '', array(), $body);
$authorStyles->installStyleRules($rules);
$reflection->getProperty('session')->getValue($compilation)->installAuthorStyleAnalysis($authorStyles);
$resolver = $reflection->getProperty('styleResolver')->getValue($compilation);
$cache = $authorStyles->selectorMatchCache();

$results = array();
foreach ($body->getElementsByTagName('div') as $element) {
    $results[] = $resolver->collapsedViewportAuthorDeclarations($element, array('display'));
}
$lookups = $cache->matchHits + $cache->matchMisses;

if (array_fill(0, $elements, array('display' => 'none')) !== $results) {
    fwrite(STDERR, 'FAIL: collapsed-viewport display changed: ' . json_encode($results) . PHP_EOL);
    exit(1);
}
// Two display rules per element; the 200 color-only rules are never matched.
if ($lookups > 2 * $elements) {
    fwrite(STDERR, sprintf('FAIL: %d selector lookups for %d elements; rules that declare no requested property were matched.%s', $lookups, $elements, PHP_EOL));
    exit(1);
}

echo "collapsed-viewport-declaring-rules: {$lookups} selector lookups for {$elements} elements\n";
