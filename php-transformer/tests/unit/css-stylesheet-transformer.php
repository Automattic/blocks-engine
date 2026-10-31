<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\Css\CssSelectorTokenizer;
use Automattic\BlocksEngine\PhpTransformer\Css\CssStylesheetTransformer;

$failures = 0;
$passes = 0;
$assert = static function (bool $condition, string $message) use (&$failures, &$passes): void {
    if ( $condition ) { ++$passes; return; }
    ++$failures;
    fwrite(STDERR, "FAIL: {$message}\n");
};

$transformer = new CssStylesheetTransformer();
$rename = static fn (string $prelude): string => str_replace('.before', '.after', $prelude);

// 1, 2, and 8: comments and declaration syntax remain source-identical.
$css = "/* leading */ .before, /* list */ [data-label=\"a,b\"] { content: '\\'{};'; --x: \";}\"; }";
$assert("/* leading */ .after, /* list */ [data-label=\"a,b\"] { content: '\\'{};'; --x: \";}\"; }" === $transformer->transform($css, $rename), 'comments, strings, escapes, and declarations are preserved');
$assert($css === $transformer->transform($css, static fn (string $prelude): string => $prelude), 'no-op callback is byte-identical');

// 3 and 4: only safe block at-rules recurse.
$css = '@media screen { @supports (display: grid) { .before { color:red } } } @font-face { font-family:"x"; src:url("x;{}.woff2"); } @keyframes spin { from { opacity:0 } }';
$expected = '@media screen { @supports (display: grid) { .after { color:red } } } @font-face { font-family:"x"; src:url("x;{}.woff2"); } @keyframes spin { from { opacity:0 } }';
$assert($expected === $transformer->transform($css, $rename), 'nested media/supports rules transform while font-face and keyframes remain opaque');
$conditions = array();
$transformer->transform($css, static function (string $prelude, string $body, array $ancestors) use (&$conditions): string {
    $conditions[trim($prelude)] = $ancestors;
    return $prelude;
});
$assert(array( '@media screen', '@supports (display: grid)' ) === ($conditions['.before'] ?? null), 'transform callbacks receive the enclosing at-rule stack');

$visitedStyles = array();
$visitedKeyframes = array();
$transformer->visitStyleAndKeyframeRules(
    '@media screen { .before { animation: fade 1s } @-webkit-keyframes fade { from { opacity:0 } } } .after { color:red }',
    static function (string $prelude, string $body, array $ancestors) use (&$visitedStyles): void {
        $visitedStyles[] = array(trim($prelude), trim($body), $ancestors);
    },
    static function (string $name, string $body) use (&$visitedKeyframes): void {
        $visitedKeyframes[] = array($name, trim($body));
    }
);
$assert(
    array(
        array('.before', 'animation: fade 1s', array('@media screen')),
        array('.after', 'color:red', array()),
    ) === $visitedStyles
    && array(array('fade', 'from { opacity:0 }')) === $visitedKeyframes,
    'combined visitor reports nested style rules and vendor-prefixed keyframes'
);

// 5: commas inside nested syntax are not selector-list separators.
$parts = CssStylesheetTransformer::splitSelectorList(':is(.a,.b), :not([data-x="a,b"]), [title="x,y"]');
$assert(array( ':is(.a,.b)', ' :not([data-x="a,b"])', ' [title="x,y"]' ) === $parts, 'selector lists split only on top-level commas');

// 6: tokenization identifies each combinator and the rightmost compound.
foreach ( array( '.a .b' => ' ', '.a>.b' => '>', '.a + .b' => '+', '.a~.b' => '~' ) as $selector => $combinator ) {
    $tokens = CssSelectorTokenizer::tokenize($selector);
    $assert($tokens['supported'] && array( '.a', '.b' ) === $tokens['compounds'] && array( $combinator ) === $tokens['combinators'] && '.b' === $tokens['rightmost_compound'], "tokenizes {$selector}");
}
$assert(! CssSelectorTokenizer::tokenize('.a, .b')['supported'], 'selector lists are surfaced as unsupported by the single-selector tokenizer');

// Escapes consume CSS hex digits and an optional CSS whitespace continuation.
foreach ( array( '.\\31 0', ".\\31\r\n0" ) as $selector ) {
    $tokens = CssSelectorTokenizer::tokenize($selector);
    $assert($tokens['supported'] && array( $selector ) === $tokens['compounds'], 'escaped identifier stays one compound');
}
$escapedCss = '.before\\{x\\;y\\,z { color:red }';
$assert('.after\\{x\\;y\\,z { color:red }' === $transformer->transform($escapedCss, $rename), 'escaped structural bytes do not split stylesheet rules');

// Long inert runs must not hide the next lexical transition. In particular,
// the closing star of a comment and escaped punctuation are not inert bytes.
foreach (array(str_repeat('ordinary', 4096), "caf\xc3\xa9\0tail") as $run) {
    $prelude = '/*' . $run . '*/ .before[data-label="' . $run . ',}\"x"]';
    $body = ' content:"' . $run . ';}\"x"; --value: fn(' . $run . '); /*' . $run . '*/ ';
    $css = $prelude . '{' . $body . '}';
    $assert(str_replace('.before', '.after', $css) === $transformer->transform($css, $rename), 'long runs preserve comments, quoted punctuation, escapes and binary bytes');
    $assert(array($prelude, ' .next') === CssStylesheetTransformer::splitSelectorList($prelude . ', .next'), 'long selector runs split at the actual top-level comma');
    $visited = array();
    $transformer->visitStyleRules('@media screen{' . $css . '}', static function (string $selector, string $declarations, array $ancestors) use (&$visited): void {
        $visited[] = array($selector, $declarations, $ancestors);
    });
    $assert(array(array(' .before[data-label="' . $run . ',}\"x"]', $body, array('@media screen'))) === $visited, 'long runs retain complete nested visitor payloads, the prelude without its comment');
    foreach (array('/*' . $run, '.before{content:"' . $run, '.before{--x:' . $run . '\\', '.before{--x:(' . $run . ';}') as $malformed) {
        $assert($malformed === $transformer->transform($malformed, $rename), 'malformed long-run input stays byte-identical');
    }
}

// CSS whitespace is exactly space, tab, LF, CR, and FF; comments only separate
// descendants when surrounding whitespace supplies the combinator.
$tokens = CssSelectorTokenizer::tokenize(".a\f>\f.b");
$assert($tokens['supported'] && array( '.a', '.b' ) === $tokens['compounds'] && array( '>' ) === $tokens['combinators'], 'form-feed around child combinator has no phantom compound');
$tokens = CssSelectorTokenizer::tokenize('.a/* note */ .b');
$assert($tokens['supported'] && array( ' ' ) === $tokens['combinators'], 'comments with CSS whitespace separate descendants');
$tokens = CssSelectorTokenizer::tokenize('.a/* note */.b');
$assert($tokens['supported'] && array( '.a/* note */.b' ) === $tokens['compounds'], 'comments alone stay inside a compound');

// Selectors 4 column combinator and namespace separator remain distinct.
foreach ( array( '.a||.b', '.a || .b' ) as $selector ) {
    $tokens = CssSelectorTokenizer::tokenize($selector);
    $assert($tokens['supported'] && array( '||' ) === $tokens['combinators'], "tokenizes column combinator {$selector}");
}
$tokens = CssSelectorTokenizer::tokenize('svg|a > *|button');
$assert($tokens['supported'] && array( 'svg|a', '*|button' ) === $tokens['compounds'] && array( '>' ) === $tokens['combinators'], 'single namespace separator remains in compounds');
$assert(array( 'start' => 0, 'end' => 2 ) === CssSelectorTokenizer::tokenize('.a > .b')['compound_spans'][0], 'compound spans reference original selector bytes');
$assert(array( 'start' => 3, 'end' => 4 ) === CssSelectorTokenizer::tokenize('.a > .b')['combinator_spans'][0], 'combinator spans reference original selector bytes');

// 7: malformed/truncated constructs are retained instead of partially transformed.
$malformed = '.before { content: "unterminated';
$assert($malformed === $transformer->transform($malformed, $rename), 'truncated stylesheet is preserved exactly');
$malformedSelector = '.before:is(.x { color:red }';
$assert($malformedSelector === $transformer->transform($malformedSelector, $rename), 'unbalanced selector prelude is preserved exactly');
$unmatchedClose = '} .before { color:red }';
$assert($unmatchedClose === $transformer->transform($unmatchedClose, $rename), 'top-level unmatched close preserves the entire stylesheet');
$unmatchedCloseAfterRule = '.before { color:red } } .before { color:blue }';
$assert($unmatchedCloseAfterRule === $transformer->transform($unmatchedCloseAfterRule, $rename), 'later unmatched close prevents earlier callback transformations');

// Block at-rules recurse only when rules are safe to walk. Declaration at-rules
// and declaration bodies, including custom-property braces and URL data, stay raw.
$css = '@scope (.root) { .before { color:red } } @property --x { syntax:"<color>"; initial-value:red; } @font-face { src:url(data:font/woff2;base64,abc==); } .before { --json: { braces: ";}"; }; background:url("data:image/svg+xml,<svg>{}</svg>"); }';
$expected = '@scope (.root) { .after { color:red } } @property --x { syntax:"<color>"; initial-value:red; } @font-face { src:url(data:font/woff2;base64,abc==); } .after { --json: { braces: ";}"; }; background:url("data:image/svg+xml,<svg>{}</svg>"); }';
$assert($expected === $transformer->transform($css, $rename), 'scope recurses while property, custom properties, and data URLs remain byte-preserved');

$identityCases = array(
    "/* comment */ .a\r\n{ content:';}'; }",
    ".a\f>\f.b { background:url(data:image/svg+xml,<svg>{}</svg>); }",
    '@scope (.x) { .a/*x*/ .b { --x:{ y:z; }; } }',
    '@property --x { syntax:"*"; inherits:false; initial-value:0; }',
);
foreach ( $identityCases as $identityCase ) {
    $assert($identityCase === $transformer->transform($identityCase, static fn (string $prelude): string => $prelude), 'no-op is byte-identical across scanner edge cases');
}

$mixedBody = 'margin:0;content:";}";--data:{token:";}";};/* media */@media (min-width:700px){margin:12px;@supports (display:grid){padding:4px}}margin:20px;&:hover{color:red}';
$mixedParts = $transformer->splitStyleRuleBody($mixedBody);
$assert(array(
    array('declarations' => 'margin:0;content:";}";--data:{token:";}";};'),
    array('prelude' => '/* media */@media (min-width:700px)', 'body' => 'margin:12px;@supports (display:grid){padding:4px}', 'at_rule' => 'media'),
    array('declarations' => 'margin:20px;'),
    array('prelude' => '&:hover', 'body' => 'color:red', 'at_rule' => ''),
) === $mixedParts, 'style-rule bodies retain ordered declaration runs, nested conditions, relative selectors, and custom-property blocks');
$assert(array(array('declarations' => 'content:"{";background:url("data:image/svg+xml,<svg>{}</svg>");margin:0')) === $transformer->splitStyleRuleBody('content:"{";background:url("data:image/svg+xml,<svg>{}</svg>");margin:0'), 'braces inside strings and URLs are not nested style rules');
$assert(array(array('declarations' => 'margin:0;@media (min-width:700px){color:red')) === $transformer->splitStyleRuleBody('margin:0;@media (min-width:700px){color:red'), 'incomplete mixed bodies remain opaque');

// Merging per-page projections of one stylesheet keeps each distinct rule once, at its last position.
$merged = $transformer->concatenateWithoutRedundantRules(array(
    '.shared{color:red}:where(.page-a){color:blue}@media (min-width:600px){.wide{margin:0}.a-only{padding:1px}}',
    '.shared{color:red}[data-theme]{color:green}@media (min-width:600px){.wide{margin:0}}',
));
$assert(':where(.page-a){color:blue}@media (min-width:600px){.a-only{padding:1px}}.shared{color:red}[data-theme]{color:green}@media (min-width:600px){.wide{margin:0}}' === $merged, 'rules repeated later are dropped from earlier projections while page-specific rules and conditional groups remain in order');
$assert('.x{color:red}.y{color:blue}.x{color:red}' !== $transformer->concatenateWithoutRedundantRules(array('.x{color:red}.y{color:blue}', '.x{color:red}')) && '.y{color:blue}.x{color:red}' === $transformer->concatenateWithoutRedundantRules(array('.x{color:red}.y{color:blue}', '.x{color:red}')), 'the surviving copy is the last one, so it still overrides the rules between the copies');
$assert('@media print{.x{color:red}}.x{color:red}' === $transformer->concatenateWithoutRedundantRules(array('@media print{.x{color:red}}', '.x{color:red}')), 'identical rules under different conditions are distinct');
$assert('@layer base{.x{color:red}}@layer a, b;@layer base{.x{color:red}}@layer a, b;' === $transformer->concatenateWithoutRedundantRules(array('@layer base{.x{color:red}}@layer a, b;', '@layer base{.x{color:red}}@layer a, b;')), 'layer blocks and statements are never dropped, since first appearance fixes layer order');
$assert(".x{color:red\n.x{color:red}" === $transformer->concatenateWithoutRedundantRules(array('.x{color:red', '.x{color:red}')), 'malformed input is concatenated unchanged');

$assert('.a-only{color:blue}@media (min-width:600px){.wide-a{margin:1px}}' === $transformer->rulesAbsentFrom(array('.shared{color:red}.a-only{color:blue}@media (min-width:600px){.wide{margin:0}.wide-a{margin:1px}}', '.a-only{color:blue}'), array('.shared{color:red}@media (min-width:600px){.wide{margin:0}}')), 'only rules missing from the present stylesheets remain, once each, in order and inside their conditional groups');
$assert('@layer base{.x{color:red}}' === $transformer->rulesAbsentFrom(array('@layer base{.x{color:red}}'), array('@layer base{.x{color:red}}')), 'layer blocks are never treated as already present');
$source = '.small{padding:20px}@media(min-width:768px){.wide{padding:32px}}';
$projection = '.small:not(.control){padding:20px}@media(min-width:768px){.wide{padding:32px}}';
$assert($projection === $transformer->rulesAbsentFrom(array($projection), array($source), true), 'a later page delta retains responsive rules after its rewritten base rule, preserving cascade order');
$assert('' === $transformer->rulesAbsentFrom(array($source), array($source), true), 'an unchanged projection still needs no page stylesheet');
$shared = 'footer.sitewide ul a{color:#036;font-weight:700}';
$assert('' === $transformer->rulesAbsentFrom(array($shared), array($shared), true), 'reduced multi-page footer projections do not repeat shared stylesheet rules');
$delta = $transformer->rulesAbsentFrom(
    array('.link.page-a{color:red}.unrelated{margin:0}.link.page-a{color:blue}.tail{padding:0}'),
    array('.link{color:black}.unrelated{margin:0}.link.page-a{color:blue}.tail{padding:0}'),
    true
);
$assert('.link.page-a{color:red}.link.page-a{color:blue}' === $delta, 'cascade-preserving page deltas retain changed rules and later selector/property overrides, not unrelated suffix rules');

$charset = $transformer->rulesAbsentFrom(array('/*# map */@charset "UTF-8";.shared{color:red}'), array('@charset "UTF-8";.shared{color:red}'), true);
$assert(!str_contains($charset, '.shared'), 'a changed comment or @charset statement declares nothing, so later shared rules stay shared');

// A shared rule re-emitted for interacting with a changed rule loads later
// too, so a shared responsive rule it originally lost to must follow it.
$transitive = $transformer->rulesAbsentFrom(
    array('.control:not(.engine){left:0}.item{left:1px;grid-row:1}.label{opacity:.8}@media (max-width:600px){.item--compact{grid-row:2}}'),
    array('.control{left:0}.item{left:1px;grid-row:1}.label{opacity:.8}@media (max-width:600px){.item--compact{grid-row:2}}'),
    true
);
$assert('.control:not(.engine){left:0}.item{left:1px;grid-row:1}@media (max-width:600px){.item--compact{grid-row:2}}' === $transitive, 'a rule overriding a re-emitted shared rule is re-emitted after it, while a rule sharing no property stays shared');
// Shorthands and their longhands (vendor-prefixed or not) set the same values.
$shorthand = $transformer->rulesAbsentFrom(
    array('.field.page-a{border:0}.field--error{border-color:red}.field--stack{-webkit-flex-direction:column}.panel.page-a{flex:1}'),
    array('.field{border:1px solid}.field--error{border-color:red}.field--stack{-webkit-flex-direction:column}.panel{flex:1}'),
    true
);
$assert('.field.page-a{border:0}.field--error{border-color:red}.panel.page-a{flex:1}' === $shorthand, 'a later longhand of a changed shorthand stays after it; an unrelated family before the change does not');

// Selector interaction narrows cascade-preserving deltas only when the
// compiled page document proves which elements each rule can style.
$interactionDocument = new DOMDocument();
@$interactionDocument->loadHTML('<html><body><nav class="site-nav"><a class="btn" href="/">Home</a></nav><p class="lead">Intro</p><h1>Title</h1></body></html>');
$interactionIndex = new \Automattic\BlocksEngine\PhpTransformer\Css\RouteSelectorMatchIndex(array('page' => $interactionDocument));
$framework = '.btn{color:red}.lead{color:green}h1{color:blue}';
$pageRule = '.site-nav .btn{color:purple}';
$interactionDelta = $transformer->rulesAbsentFrom(array($pageRule . $framework), array($framework), true, $interactionIndex, 'page');
$assert($pageRule . '.btn{color:red}' === $interactionDelta, 'page delta keeps same-element shared overrides and drops same-family rules for unrelated elements');
$assert($pageRule . $framework === $transformer->rulesAbsentFrom(array($pageRule . $framework), array($framework), true), 'without document evidence cascade preservation retains its conservative family-only behavior');
$assert($pageRule . $framework === $transformer->rulesAbsentFrom(array($pageRule . $framework), array($framework), true, $interactionIndex, 'missing'), 'missing page documents never prove selector disjointness');

$unknownDelta = $transformer->rulesAbsentFrom(array('.btn:unknown{color:purple}' . $framework), array($framework), true, $interactionIndex, 'page');
$assert('.btn:unknown{color:purple}.btn{color:red}.lead{color:green}h1{color:blue}' === $unknownDelta, 'unsupported selectors conservatively retain overlapping shared rules');

// A pseudo-element is its own cascade target; state pseudo-classes match in
// every state. Neither leaves a page rule's styled elements unknown.
$pseudoShared = '.btn::before{content:"shared"}.lead::before{content:"unrelated"}.btn{border:1px solid;color:red}.lead{color:green}';
foreach (array(
    array('.site-nav .btn::before{content:"page"}', '.btn::before{content:"shared"}', 'a pseudo-element rule keeps the shared rule for the same pseudo-element of its host'),
    array('.site-nav .btn{content:"page"}', '', 'a host rule does not compete with its pseudo-elements'),
    array('::-moz-focus-inner{border:0}', '', 'a vendor pseudo-element rule does not compete with element rules'),
    array('.site-nav .btn:checked{color:purple}', '.btn{border:1px solid;color:red}', 'a state pseudo-class rule competes on every element it may match'),
) as [$pseudoPage, $expected, $label]) {
    $assert($pseudoPage . $expected === $transformer->rulesAbsentFrom(array($pseudoPage . $pseudoShared), array($pseudoShared), true, $interactionIndex, 'page'), $label);
}

// Projected marker arms keep every shared rule that styles a marked element
// with an overlapping family, and the split sheet resolves identically.
$markedDocument = new DOMDocument();
@$markedDocument->loadHTML('<html><body><p class="m">One</p><p>Two</p><span class="m">S</span></body></html>');
$markedIndex = new \Automattic\BlocksEngine\PhpTransformer\Css\RouteSelectorMatchIndex(array('page' => $markedDocument));
$markedShared = 'p{color:blue}.m{color:red}span{padding:9px}h1{color:navy}';
$markedPage = ':where(.m){color:green}p{padding:2px}';
$markedDelta = $transformer->rulesAbsentFrom(array($markedPage . $markedShared), array($markedShared), true, $markedIndex, 'page');
$assert(':where(.m){color:green}p{padding:2px}p{color:blue}.m{color:red}' === $markedDelta, 'marker-arm page deltas retain only shared rules styling marked elements with an overlapping family');
$cascade = \Automattic\BlocksEngine\PhpTransformer\VisualParity\StaticCssCascade::class;
foreach (array($markedShared . "\n" . $markedDelta, $markedPage . $markedShared) as $sheet) {
    $resolved = array();
    foreach ($markedDocument->getElementsByTagName('*') as $element) {
        $resolved[] = (new $cascade($markedDocument, $sheet))->resolve($element, array('color', 'padding', 'margin'), array());
    }
    $sheets[] = $resolved;
}
$assert($sheets[0] === $sheets[1], 'split shared and delta stylesheets resolve identically to the source sheet');

$compact = static fn(string $list): string => \Automattic\BlocksEngine\PhpTransformer\Css\CssSelectorListCompactor::compact($list);
$assert('.catalog :is(.one,.two,.three)' === $compact('.catalog .one,.catalog .two,.catalog .three'), 'equal-weight compounds factor once');
foreach ( array(
    '.catalog .one,.catalog #two',
    '.catalog .one::before,.catalog .two::before',
    '.catalog .one:before,.catalog .two:before',
    '.catalog .one:unknown,.catalog .two:unknown',
    '.catalog .one:where(:unknown),.catalog .two:where(:unknown)',
    '.catalog .one,.catalog .two.extra',
    '.catalog .one\\:escaped,.catalog .two\\:escaped',
) as $unsafe ) {
    $assert($unsafe === $compact($unsafe), 'unsafe or unequal-weight arguments remain unchanged: ' . $unsafe);
}
$assert('.catalog :is(.one,.two),#unrelated' === $compact('.catalog .one,#unrelated,.catalog .two'), 'nonadjacent arms of one rule may fold without moving a rule');
$fixture = file_get_contents(dirname(__DIR__) . '/fixtures/projected-selector-compaction.html');
$output = (new \Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer())->transform($fixture)->toArray();
$projectedCss = implode("\n", array_column($output['assets'], 'content'));
$assert(str_contains($projectedCss, '.catalog :is(.tile-one,.tile-two,.tile-three){color:navy}') && strpos($projectedCss, '{color:maroon}') > strpos($projectedCss, '{color:navy}'), 'neutral projection fixture compacts and retains later cascade override');
$assert('.catalog :is(:where(.one):not(.shim),:where(.two):not(.shim))' === $compact('.catalog :where(.one):not(.shim),.catalog :where(.two):not(.shim)'), 'neutral markers and equal-weight shims can factor');
$unequalLogical = '.catalog :where(.one):not(.shim),.catalog :where(.two):not(#shim)';
$assert($unequalLogical === $compact($unequalLogical), 'is maximum must not promote a class arm to ID specificity');
$namespaceCss = '@namespace url(example);.catalog .one,.catalog .two{color:red}';
$assert($namespaceCss === \Automattic\BlocksEngine\PhpTransformer\Css\CssSelectorListCompactor::stylesheet($namespaceCss), 'namespace-sensitive sheets remain unchanged');
$splitRules = '.catalog .one{color:red}.catalog .other{color:blue}.catalog .two{color:red}';
$assert($splitRules === \Automattic\BlocksEngine\PhpTransformer\Css\CssSelectorListCompactor::stylesheet($splitRules), 'identical declarations never merge across an intervening rule');
// Page-local marker lists expand back into arms for cross-page alignment and
// merge into one zero-weight :where() list for the site-wide sheet.
$compactor = \Automattic\BlocksEngine\PhpTransformer\Css\CssSelectorListCompactor::class;
$m1 = 'blocks-engine-control-4041d29655d8-0';
$m2 = 'blocks-engine-control-f9b2c417568b-0';
$packed = $compact('.row-cols-6>:where(.' . $m1 . '),.row-cols-6>:where(.' . $m2 . ')');
$assert('.row-cols-6>:is(:where(.' . $m1 . '),:where(.' . $m2 . '))' === $packed, 'per-page projection packs marker arms');
$assert('.row-cols-6>:where(.' . $m1 . '),.row-cols-6>:where(.' . $m2 . ')' === $compactor::expand($packed), 'packed marker arms expand back into one arm per marker');
$merged = $compactor::mergeMarkerLists('.row-cols-6>:where(.' . $m1 . '):not(.be-c-0),#x,.row-cols-6>:where(.' . $m2 . '):not(.be-c-0)');
$assert('.row-cols-6>:where(.' . $m1 . ',.' . $m2 . '):not(.be-c-0),#x' === $merged, 'arms differing by one marker merge into a :where() list');
$assert('.row-cols-6>:where(.' . $m1 . '):not(.be-c-0),.row-cols-6>:where(.' . $m2 . '):not(.be-c-0),#x' === $compactor::expand($merged), 'merged marker lists expand back into arms');
$assert('.a :is(.one,.two)' === $compactor::expand('.a :is(.one,.two)') && ':not(:where(.' . $m1 . ',.' . $m2 . ')) a,b' === $compactor::mergeMarkerLists(':not(:where(.' . $m1 . ',.' . $m2 . ')) a,b'), 'non-marker lists and negated marker lists stay as authored');
$unequalMarkers = '.a :is(:where(.' . $m1 . '),#b)';
$assert($unequalMarkers === $compactor::expand($unequalMarkers), 'unequal-weight alternatives are never expanded');
$before = '.catalog .one,.catalog .two,.catalog .three';
$after = $compact($before);
$matcher = \Automattic\BlocksEngine\PhpTransformer\Css\CssSelectorMatcher::class;
$document = new DOMDocument();
$document->loadHTML('<main class="catalog"><p class="one"></p><p class="two"></p><p class="three"></p><p class="other"></p></main>');
foreach ( $document->getElementsByTagName('p') as $element ) {
    $oldMatch = false;
    foreach ( explode(',', $before) as $arm ) {
        $parsed = $matcher::parse($arm);
        $oldMatch = $oldMatch || $matcher::matches($element, $parsed)['matches'];
        $assert($matcher::specificityCounts($parsed) === $matcher::specificityCounts($matcher::parse($after)), 'factoring preserves the full specificity tuple');
    }
    $assert($oldMatch === $matcher::matches($element, $matcher::parse($after))['matches'], 'factored selector matches exactly the original union');
}

// Cached probe failures must not leak between routes sharing selector bytes.
foreach (array(
    array('.x{color:red}', '@page{margin:0}.x{color:red}', 'non-selector rule cannot be route scoped'),
) as [$base, $unsafe, $reason]) {
    $result = $transformer->scopeRouteVariants(array($base), array(
        'unsafe' => array($unsafe),
        'safe' => array('.y{color:red}'),
        'unsafe-again' => array($unsafe),
    ));
    $assert(array('safe') === $result['scoped'] && array('unsafe' => $reason, 'unsafe-again' => $reason) === $result['reasons'], 'probe cache preserves route-local failures and successful validation');
}

if ( $failures > 0 ) {
    fwrite(STDERR, "CssStylesheetTransformer unit tests: {$failures} failed, {$passes} passed\n");
    exit(1);
}
fwrite(STDOUT, "CssStylesheetTransformer unit tests: {$passes} passed\n");
