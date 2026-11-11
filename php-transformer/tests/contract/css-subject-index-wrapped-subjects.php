<?php
declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\Css\CssSelectorMatcher;
use Automattic\BlocksEngine\PhpTransformer\Css\CssSubjectIndex;

// A subject wrapped in :is()/:where() is filed under its alternatives' keys,
// so unrelated elements skip it and an element carrying several keys gets it once.
$selectors = array(
    'wrapped' => '.scope :where(:is(#hero,.hero-anchor)):not(#skip)',
    'list' => ':is(:where(#card):not(.x),:where(.tile):not(.x))',
    'keyless' => ':is(:hover,:focus)',
);
$index = CssSubjectIndex::build(array_map(static fn (string $selector): array => array('selector' => $selector, 'parsed_selector' => CssSelectorMatcher::parse($selector)), array_values($selectors)));
$document = new DOMDocument();
$document->loadHTML('<div class="scope"><p id="hero" class="hero-anchor"></p><p class="tile"></p><p id="other"></p></div>', LIBXML_NOERROR);
$xpath = new DOMXPath($document);
$cases = array(
    '//*[@id="hero"]' => array($selectors['wrapped'], $selectors['keyless']),
    '//*[@class="tile"]' => array($selectors['list'], $selectors['keyless']),
    '//*[@id="other"]' => array($selectors['keyless']),
);
foreach ($cases as $query => $expected) {
    $element = $xpath->query($query)->item(0);
    $actual = array_column(array_column(CssSubjectIndex::entries($element, $index), 'rule'), 'selector');
    if ($expected !== $actual) throw new RuntimeException($query . ' candidates: ' . json_encode($actual));
}
echo "CSS subject index wrapped-subject contract passed\n";
