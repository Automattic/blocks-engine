<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\Css\CssSelectorMatcher;
use Automattic\BlocksEngine\PhpTransformer\Css\CssSelectorMatchCache;
use Automattic\BlocksEngine\PhpTransformer\Support\EngineMarker;

// A form's retention filter skips a selector only when subjectMayMatch() says
// no element can be its subject. That must never drop a selector matches()
// accepts, or one it reports as unsupported.
$dom = new DOMDocument();
libxml_use_internal_errors(true);
$dom->loadHTML('<!doctype html><div id="page" class="shell"><form class="signup"><label for="email" class="field-label">Email</label><INPUT id="email" class="field wide" type="email"><span class="' . EngineMarker::editorAnchorClass('panel') . '">x</span></form></div>');
libxml_clear_errors();
$elements = array();
foreach ( $dom->getElementsByTagName('*') as $element ) {
    $elements[] = $element;
}
$cache = new CssSelectorMatchCache();
$tokens = $cache->subjectTokens($elements);

$cases = array(
    // selector => expected subjectMayMatch()
    '.shell .field.wide' => true,
    'form input#email' => true,
    'INPUT.field' => true,
    '#panel' => true,              // editor anchor class stands in for the id
    '.signup > label:first-child' => true,
    'div *' => true,
    '[type="email"]' => true,
    '.missing .field' => true,      // only the subject compound is checked
    '.field.missing' => false,
    '#absent' => false,
    'textarea' => false,
    'input.field:hover' => true,    // pseudo-state selectors keep their own path
    'input::placeholder' => true,   // unsupported: kept so it reports as before
);
$failures = 0;
foreach ( $cases as $text => $expected ) {
    $selector = CssSelectorMatcher::parse($text);
    $may = $cache->subjectMayMatch($selector, $tokens);
    if ( $may !== $expected ) {
        ++$failures;
        fwrite(STDERR, "FAIL: subjectMayMatch({$text}) should be " . var_export($expected, true) . "\n");
    }
    if ( ! $may ) {
        foreach ( $elements as $element ) {
            $match = $cache->matches($element, $text, $selector);
            if ( $match['matches'] || ! $match['supported'] ) {
                ++$failures;
                fwrite(STDERR, "FAIL: {$text} was skipped but matches() keeps it for <{$element->tagName}>\n");
            }
        }
    }
}

if ( $failures > 0 ) {
    exit(1);
}
echo "css selector subject prefilter ok\n";
