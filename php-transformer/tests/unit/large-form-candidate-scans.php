<?php
/**
 * Large form pages stay near-linear in their controls and author rules (#6752).
 *
 * A page with hundreds of form controls and a big stylesheet spent most of
 * its compile on work that grew with controls times rules or with the
 * selector list's length squared. Each contract below is a deterministic
 * count, not a timing, so it holds on a loaded machine:
 *
 *  - an element is matched only against the viewport rules whose subject it
 *    could match, not every rule declaring the property asked about;
 *  - a selector list is compacted once per distinct text, in one linear scan;
 *  - a document's ids are indexed once for its labels, not searched once per
 *    label.
 */
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\Css\CssSelectorListCompactor;
use Automattic\BlocksEngine\PhpTransformer\Css\CssSelectorMatcher;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Classification\FormControlClassifier;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlCompilation;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\AuthorStyleAnalysis;

$failures = array();
$check = static function (bool $condition, string $message) use (&$failures): void {
    if ( ! $condition ) {
        $failures[] = 'FAIL: ' . $message;
    }
};

// 1. Viewport facts match only candidate rules.
$elements = 20;
$otherRules = 200;
$document = new DOMDocument();
$document->loadHTML('<!doctype html><html><body>' . str_repeat('<div class="item"></div>', $elements) . '</body></html>');
$body = $document->getElementsByTagName('body')->item(0);
$rule = static fn (int $order, string $selector, array $declarations): array => array(
    'order' => $order,
    'declarations' => $declarations,
    'conditions' => array(),
    'selectors' => array_map(
        static fn (string $part): array => array( 'selector' => $part, 'parsed' => CssSelectorMatcher::parse($part) ),
        explode(',', $selector)
    ),
);
$rules = array();
for ( $index = 0; $index < $otherRules; ++$index ) {
    // Every rule declares the property asked about, but names other elements.
    $rules[] = $rule($index, '.field-' . $index . ' input,#control-' . $index, array( 'display' => 'block' ));
}
$rules[] = $rule($otherRules, '.missing,.item', array( 'display' => 'flex' ));
$compilation = new HtmlCompilation();
$reflection = new ReflectionClass($compilation);
$authorStyles = new AuthorStyleAnalysis('', '', array(), $body);
$authorStyles->installStyleRules($rules);
$reflection->getProperty('session')->getValue($compilation)->installAuthorStyleAnalysis($authorStyles);
$resolver = $reflection->getProperty('styleResolver')->getValue($compilation);
$cache = $authorStyles->selectorMatchCache();
$results = array();
foreach ( $body->getElementsByTagName('div') as $element ) {
    $results[] = $resolver->authorViewport->collapsed($element, array( 'display' ));
}
$lookups = $cache->matchHits + $cache->matchMisses;
$check(array_fill(0, $elements, array( 'display' => 'flex' )) === $results, 'viewport display facts changed: ' . json_encode($results));
// One candidate rule (two selectors) per element; the 200 rules naming other
// subjects are never matched.
$check($lookups <= 2 * $elements, sprintf('%d selector lookups for %d elements; rules whose subject cannot match were matched', $lookups, $elements));

// 2. Selector-list compaction is memoized and linear in the list.
$list = static function (int $selectors, string $salt): string {
    $parts = array();
    for ( $index = 0; $index < $selectors; ++$index ) {
        $parts[] = '.form-' . $salt . ' :is(.field-' . $index . ',.row-' . $index . ') input:not([type="hidden"])';
    }

    return implode(',', $parts);
};
$scanned = static function (string $prelude): int {
    $before = CssSelectorListCompactor::nestedListScanBytes();
    CssSelectorListCompactor::compact($prelude);

    return CssSelectorListCompactor::nestedListScanBytes() - $before;
};
$small = $list(400, 'a');
$large = $list(800, 'b');
$smallBytes = $scanned($small);
$largeBytes = $scanned($large);
$check($smallBytes > 0, 'compacting a nested list scans it');
$check($largeBytes <= 2.5 * strlen($large) && $largeBytes / max(1, $smallBytes) < 2.5, sprintf('doubling the list scanned %d -> %d bytes', $smallBytes, $largeBytes));
$check(0 === $scanned($large), 'compacting the same list again rescans it');

// 3. A document's ids are indexed once for all its labels.
$page = static function (int $controls): string {
    $fields = '';
    for ( $index = 0; $index < $controls; ++$index ) {
        // A question caption and the choice's own label both name the box.
        $fields .= '<div class="row"><label for="c' . $index . '">Question ' . $index . '</label><span class="choice"><input type="checkbox" id="c' . $index . '" name="c' . $index . '" value="1"><label for="c' . $index . '">Agree</label></span></div>';
    }

    return '<!doctype html><html><head><title>Form</title></head><body><form action="/submit" method="post">' . $fields . '<button type="submit">Send</button></form></body></html>';
};
$builds = array();
foreach ( array( 20, 80 ) as $controls ) {
    $before = FormControlClassifier::idIndexBuilds();
    $markup = (string) ( ( new HtmlTransformer() )->transform($page($controls))->toArray()['serialized_blocks'] ?? '' );
    $builds[$controls] = FormControlClassifier::idIndexBuilds() - $before;
    $check($controls === preg_match_all('/<input\b/', $markup), $controls . ' controls convert');
}
$check($builds[20] > 0 && $builds[80] <= $builds[20], 'id index builds grew with the labels: ' . json_encode($builds));

if ( array() !== $failures ) {
    fwrite(STDERR, implode(PHP_EOL, $failures) . PHP_EOL);
    exit(1);
}

echo "large-form-candidate-scans: {$lookups} viewport lookups, {$smallBytes} -> {$largeBytes} compaction bytes, " . json_encode($builds) . " id index builds\n";
