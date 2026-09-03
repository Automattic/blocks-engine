<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\CssCascade;
use Automattic\BlocksEngine\PhpTransformer\Css\CssRuleAnalyzer;
use Automattic\BlocksEngine\PhpTransformer\Css\CssSelectorMatcher;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

$failures = 0;
$passes = 0;
$assert = static function (bool $condition, string $message) use (&$failures, &$passes): void {
    if ( $condition ) {
        ++$passes;
        return;
    }
    ++$failures;
    fwrite(STDERR, "FAIL: {$message}\n");
};

$analysis = (new CssRuleAnalyzer())->analyze(
    array(
        array(
            'content' => '@media (max-width: 40rem) { @supports (display: grid) { .field, [data-label="a,b"] { display:flex; gap:var(--gap, "a;b") } } } .field { display:grid !important; display:block }',
            'source_path' => 'form.css',
            'source_hash' => hash('sha256', 'form.css'),
        ),
    ),
    '',
    array( 'display', 'gap' ),
    1024,
    16,
    16,
    4
);

$rules = $analysis['rules'];
$conditional = $rules[0] ?? array();
$quotedSelector = $rules[1] ?? array();
$base = $rules[2] ?? array();
$assert(3 === count($rules), 'analyzes nested at-rules and selector lists into shared rules');
$assert('all' === ($conditional['condition']['kind'] ?? null) && 'media' === ($conditional['condition']['conditions'][0]['kind'] ?? null) && 'supports' === ($conditional['condition']['conditions'][1]['kind'] ?? null), 'retains nested at-rule conditions');
$assert('[data-label="a,b"]' === ($quotedSelector['selector'] ?? null) && 'var(--gap, "a;b")' === ($quotedSelector['declarations'][1]['value'] ?? null), 'quoted commas and semicolons remain inside selector and declaration values');
$assert(2 === count($base['declarations'] ?? array()), 'preserves duplicate declaration order for cascade resolution');

$dom = new DOMDocument();
libxml_use_internal_errors(true);
$dom->loadHTML('<!doctype html><div class="field" data-label="a,b"></div>');
libxml_clear_errors();
$element = $dom->getElementsByTagName('div')->item(0);
$facts = array();
foreach ( array($base, $conditional) as $rule ) {
    $match = CssSelectorMatcher::matches($element, $rule['parsed_selector']);
    $assert($match['supported'] && $match['matches'], 'analyzed rules use the shared selector matcher');
    foreach ( $rule['declarations'] as $declaration ) {
        $important = 1 === preg_match('/\s*!important\s*$/i', $declaration['value']);
        $fact = array( 'value' => preg_replace('/\s*!important\s*$/i', '', $declaration['value']), 'important' => $important, 'specificity' => $rule['specificity'], 'order' => $rule['order'] );
        CssCascade::apply($facts, $declaration['name'], $fact);
    }
}
$assert('grid' === ($facts['display']['value'] ?? null) && true === ($facts['display']['important'] ?? null), 'shared cascade gives !important precedence over later normal declarations');
$assert('var(--gap, "a;b")' === ($facts['gap']['value'] ?? null), 'shared cascade retains quoted declaration delimiters');

$commentAnalysis = (new CssRuleAnalyzer())->analyze(
    array( array( 'content' => '.field/**/input { align-self:flex-start }', 'source_path' => 'comments.css', 'source_hash' => hash('sha256', 'comments.css') ) ),
    '',
    array( 'align-self' ),
    1024,
    16,
    16,
    4
);
$commentRule = $commentAnalysis['rules'][0] ?? array();
$commentDom = new DOMDocument();
$commentDom->loadHTML('<!doctype html><div class="field"><input></div>');
$commentInput = $commentDom->getElementsByTagName('input')->item(0);
$commentMatch = CssSelectorMatcher::matches($commentInput, $commentRule['parsed_selector'] ?? array());
$assert('.field input' === ($commentRule['selector'] ?? null) && $commentMatch['supported'] && $commentMatch['matches'], 'comments separating identifier-like selector tokens retain descendant boundaries');

$unrelatedCss = '';
for ( $index = 0; $index < 1200; ++$index ) {
    $unrelatedCss .= '.unrelated-' . $index . '{display:grid}';
}
$unrelatedCss .= '.retained{display:flex}';
$filterCalls = 0;
$filteredAnalysis = (new CssRuleAnalyzer())->analyze(
    array( array( 'content' => $unrelatedCss, 'source_path' => 'large.css', 'source_hash' => hash('sha256', $unrelatedCss) ) ),
    '',
    array( 'display' ),
    262144,
    16,
    16,
    4,
    static function (array $selector) use (&$filterCalls): bool {
        return 1201 === ++$filterCalls;
    },
    4096
);
$assert(false === $filteredAnalysis['truncated'] && 1 === count($filteredAnalysis['rules']) && '.retained' === ($filteredAnalysis['rules'][0]['selector'] ?? null), 'relevance filtering scans more than 1,024 unrelated selectors without consuming retained selector capacity');

$scanOverflowAnalysis = (new CssRuleAnalyzer())->analyze(
    array( array( 'content' => str_repeat('.unrelated{display:grid}', 17), 'source_path' => 'overflow.css', 'source_hash' => hash('sha256', 'overflow.css') ) ),
    '',
    array( 'display' ),
    1024,
    16,
    16,
    4,
    static fn (array $selector): bool => false,
    16
);
$assert(true === $scanOverflowAnalysis['truncated'] && array() === $scanOverflowAnalysis['rules'] && in_array('css_selector_scan_limit', $scanOverflowAnalysis['diagnostics'], true), 'scanned selector work fails closed before parsing or filtering beyond its independent budget');

$commentGraph = (new HtmlTransformer())->transform('<form><div class="field"><input name="email"></div><button type="submit">Send</button></form>', array( 'static_css' => '.field/**/input { align-self:flex-start }' ))->toArray()['fallbacks'][0]['layout_graph'] ?? array();
$commentGraphNodes = array_column($commentGraph['nodes'] ?? array(), null, 'id');
$assert('flex-start' === ($commentGraphNodes['control-0']['layout']['align_self'] ?? null), 'form layout graphs retain CSS comment selector boundaries');

if ( $failures > 0 ) {
    fwrite(STDERR, "CssRuleAnalyzer unit tests: {$failures} failed, {$passes} passed\n");
    exit(1);
}
fwrite(STDOUT, "CssRuleAnalyzer unit tests: {$passes} passed\n");
