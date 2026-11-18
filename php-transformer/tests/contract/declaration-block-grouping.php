<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\Css\CssSelectorMatcher;
use Automattic\BlocksEngine\PhpTransformer\Css\CssStylesheetTransformer;

// A rule whose declaration block repeats an earlier one in the same at-rule
// context joins that rule's selector list only when moving it up is
// cascade-neutral: every rule between them declares other properties, or
// selects no element (or pseudo-element box) the moved selectors do.
$hidden = '{visibility:hidden;transform:none!important}';
$a = ':where(#comp-a):not(.be-c-0):not(#be-i-0)';
$b = ':where(#comp-b):not(.be-c-0):not(#be-i-0)';
$cases = array(
    'disjoint properties between' => array(
        '@media (prefers-reduced-motion:no-preference){' . $a . $hidden . '.x{color:red}' . $b . $hidden . '}',
        '@media (prefers-reduced-motion:no-preference){' . $a . ',' . $b . $hidden . '.x{color:red}}',
    ),
    'same property on a selector that may match the same element' => array(
        $a . $hidden . '.x{visibility:visible}' . $b . $hidden,
        null,
    ),
    'same property on another id' => array(
        $a . $hidden . ':where(#comp-c):not(.be-c-0){visibility:visible}' . $b . $hidden,
        $a . ',' . $b . $hidden . ':where(#comp-c):not(.be-c-0){visibility:visible}',
    ),
    'same property on another pseudo-element' => array(
        '.a' . $hidden . '#comp-b::before{transform:scale(2)}.b' . $hidden,
        '.a,.b' . $hidden . '#comp-b::before{transform:scale(2)}',
    ),
    'an id under :not() does not constrain the subject' => array(
        '#comp-a' . $hidden . '.x:not(#comp-b){visibility:visible}#comp-b' . $hidden,
        null,
    ),
    'an id outside the subject compound does not constrain it' => array(
        '#comp-a .y' . $hidden . '#comp-c .y{visibility:visible}#comp-b .y' . $hidden,
        null,
    ),
    'one of several between rules overlaps' => array(
        $a . $hidden . '#comp-c{visibility:visible}.x{transform:scale(2)}' . $b . $hidden,
        null,
    ),
);

$transformer = new CssStylesheetTransformer();
$failures = array();
foreach ( $cases as $name => [$css, $expected] ) {
    $actual = $transformer->mergeRepeatedDeclarationRules($css);
    if ( ($expected ?? $css) !== $actual ) {
        $failures[] = $name . ': ' . $actual;
    }
}

// Grouping appends each selector as it is, never inside :is(), so every
// member keeps its own specificity.
$merged = $transformer->mergeRepeatedDeclarationRules('#comp-a' . $hidden . '.x{color:red}.b .c' . $hidden . 'p' . $hidden);
$head = substr($merged, 0, (int) strpos($merged, '{'));
if ( '#comp-a,.b .c,p' !== $head ) {
    $failures[] = 'specificity: unexpected selector list ' . $head;
}
$weight = static fn (string $selector): array => CssSelectorMatcher::specificityCounts(CssSelectorMatcher::parse($selector));
$arms = CssStylesheetTransformer::splitSelectorList($head) ?? array();
foreach ( array( '#comp-a', '.b .c', 'p' ) as $index => $original ) {
    if ( $weight($original) !== $weight(trim($arms[ $index ] ?? '')) ) {
        $failures[] = 'specificity changed for ' . $original;
    }
}

if ( array() !== $failures ) {
    fwrite(STDERR, implode("\n", $failures) . "\n");
    exit(1);
}
echo "Identical declaration blocks group only across cascade-neutral rules\n";
