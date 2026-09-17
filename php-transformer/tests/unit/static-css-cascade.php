<?php
/**
 * StaticCssCascade base-state resolution.
 *
 * This resolver decides the SOURCE side of every static-parity comparison, so a
 * value it reports that the browser would never compute becomes a parity finding
 * against a transformer that was correct. Each case below is a way that used to
 * happen.
 */
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\VisualParity\StaticCssCascade;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformerAnalysisCache;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Session\HtmlTransformerSession;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\CssCascade;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\StyleResolutionContext;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\StyleResolver;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\StylesheetAnalysisComposer;
use Automattic\BlocksEngine\PhpTransformer\WordPress\Runtime;

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

/** Resolve one property for the first element matching an XPath query. */
$resolve = static function (string $html, string $css, string $xpath, array $properties, array $inheritable = array()): array {
    $dom = new DOMDocument();
    $previous = libxml_use_internal_errors(true);
    $dom->loadHTML('<?xml encoding="utf-8" ?>' . $html);
    libxml_clear_errors();
    libxml_use_internal_errors($previous);

    $element = ( new DOMXPath($dom) )->query($xpath)->item(0);
    if ( ! $element instanceof DOMElement ) {
        throw new RuntimeException("No element for {$xpath}");
    }

    return ( new StaticCssCascade($dom, $css) )->resolve($element, $properties, $inheritable);
};

/**
 * The value the TRANSFORMER resolves for one author-declared property, which is
 * the candidate side of the same comparison `$resolve()` supplies the source
 * side of. `declaredPresentation()->resolvedValue()` is the entry point every
 * colour carrier reads, and it is where an `@supports` condition the evaluator
 * cannot read turns into the wrong declaration.
 */
$resolvedAuthorColor = static function (string $html, string $css, string $xpath): string {
    $dom = new DOMDocument();
    $previous = libxml_use_internal_errors(true);
    $dom->loadHTML('<?xml encoding="utf-8" ?>' . $html);
    libxml_clear_errors();
    libxml_use_internal_errors($previous);

    $element = ( new DOMXPath($dom) )->query($xpath)->item(0);
    if ( ! $element instanceof DOMElement ) {
        throw new RuntimeException("No element for {$xpath}");
    }

    $session = new HtmlTransformerSession(new Runtime(), static fn (DOMElement $node): array => array());
    $context = null;
    $context = new StyleResolutionContext(
        $session,
        static fn (DOMElement $node): int => 0,
        static fn (string $value): string => $value,
        static function (string $selector) use (&$context): array {
            return $context->sourceStyles()->parsedSelector($selector);
        },
        static fn (string $className): string => $className,
        static fn (string $url): string => $url,
        static fn (DOMElement $node): bool => false
    );

    $analysisCache = new HtmlTransformerAnalysisCache();
    $resolver = new StyleResolver($context, $analysisCache);
    $context->sourceStyles()->installStylesheetAnalysis(
        array(),
        ( new StylesheetAnalysisComposer($resolver, $analysisCache) )->composedStyleAnalysis(array( $css ))
    );

    return $resolver->declaredPresentation($element, 'color')->resolvedValue();
};

$page = '<html><body><footer class="site-footer"><div class="col"><ul><li><a href="#">Link</a></li></ul></div></footer><nav class="main-nav">nav</nav></body></html>';

// A comment before a rule used to be absorbed into that rule's selector, so the
// rule matched nothing. It lands on the first rule after every comment, which in
// hand-authored CSS is usually the structural one.
$commented = "/* ─── Footer ─── */\n.site-footer { color: rgb(1, 2, 3); font-size: 0.92rem; }";
$result = $resolve($page, $commented, '//footer', array( 'color', 'font-size' ));
$assert('rgb(1, 2, 3)' === ( $result['color'] ?? '' ), 'a rule preceded by a comment still matches');
$assert('0.92rem' === ( $result['font-size'] ?? '' ), 'a commented rule keeps every declaration');

$result = $resolve($page, "/* a */ .site-footer /* b */ { color: rgb(4, 5, 6); }", '//footer', array( 'color' ));
$assert('rgb(4, 5, 6)' === ( $result['color'] ?? '' ), 'comments inside a selector are removed');

// :root is the document element. Without it no custom property is ever collected
// and every var() reference stays literal on the source side.
$result = $resolve($page, ':root { --brand: #123456; } .site-footer { color: var(--brand); }', '//footer', array( 'color' ));
$assert('#123456' === ( $result['color'] ?? '' ), ':root custom properties resolve var() references');

$result = $resolve(
    $page,
    ':root { --a: #aabbcc; } .site-footer { color: var(--missing, var(--a)); }',
    '//footer',
    array( 'color' )
);
$assert('#aabbcc' === ( $result['color'] ?? '' ), 'var() fallback chains resolve through :root');

// A rule gated on interaction state must not become a base-state rule. Source
// order would otherwise let the hover declaration outrank the real base one.
$hover = '.site-footer a { color: rgb(1, 1, 1); } .site-footer a:hover { color: rgb(9, 9, 9); }';
$result = $resolve($page, $hover, '//footer//a', array( 'color' ));
$assert('rgb(1, 1, 1)' === ( $result['color'] ?? '' ), ':hover does not override the base colour');

foreach ( array( ':focus', ':focus-within', ':active', ':visited', ':target' ) as $state ) {
    $css = ".site-footer a { color: rgb(1, 1, 1); } .site-footer a{$state} { color: rgb(9, 9, 9); }";
    $result = $resolve($page, $css, '//footer//a', array( 'color' ));
    $assert('rgb(1, 1, 1)' === ( $result['color'] ?? '' ), "{$state} does not override the base colour");
}

foreach ( array( '::before', ':before', '::after', '::placeholder', '::marker' ) as $pseudoElement ) {
    $css = ".site-footer a { color: rgb(1, 1, 1); } .site-footer a{$pseudoElement} { color: rgb(9, 9, 9); }";
    $result = $resolve($page, $css, '//footer//a', array( 'color' ));
    $assert('rgb(1, 1, 1)' === ( $result['color'] ?? '' ), "{$pseudoElement} does not style the element itself");
}

// An ancestor gated on state must not reveal a descendant either: this is the
// CSS-only mega-menu shape, where the panel is hidden until the item is hovered.
$menu = '<html><body><div class="item"><div class="panel">p</div></div></body></html>';
$menuCss = '.panel { visibility: hidden; } .item:hover .panel { visibility: visible; }';
$result = $resolve($menu, $menuCss, '//div[@class="panel"]', array( 'visibility' ));
$assert('hidden' === ( $result['visibility'] ?? '' ), 'a hover-gated ancestor does not reveal a hidden descendant');

// @media is resolved against the desktop reference width rather than flattened.
$narrow = '@media (max-width: 1080px) { .main-nav { display: none; } }';
$result = $resolve($page, $narrow, '//nav', array( 'display' ));
$assert(! isset($result['display']), 'a max-width block below the reference width does not apply');

$wide = '@media (min-width: 768px) { .main-nav { display: flex; } }';
$result = $resolve($page, $wide, '//nav', array( 'display' ));
$assert('flex' === ( $result['display'] ?? '' ), 'a min-width block satisfied at the reference width applies');

$responsiveAlternatives = '@media (max-width:40rem), screen and (.5rem <= width < 90.0001rem) { .main-nav { display: grid; } }';
$result = $resolve($page, $responsiveAlternatives, '//nav', array( 'display' ));
$assert('grid' === ( $result['display'] ?? '' ), 'generic static responsive media keeps top-level alternatives and fractional range syntax at desktop');

$assert(
    CssCascade::mediaConditionApplies('print, not screen and (90.0001rem < width)', 1440.0),
    'a negated comma alternative applies when its supported fractional strict left-sided range is false at the viewport'
);
$assert(
    ! CssCascade::mediaConditionApplies('not screen and (unknown-feature: value)', 1440.0),
    'an unknown media feature remains fail-closed when negated'
);
$assert(
    CssCascade::supportsConditionApplies('((display:grid) and (object-fit:cover)) or not (display:flex)'),
    'compound known @supports expressions honor parentheses, and, or, and not'
);
$assert(
    ! CssCascade::supportsConditionApplies('(display:grid) and not (object-fit:cover)'),
    'known-false compound @supports expressions do not apply'
);
$assert(
    ! CssCascade::supportsConditionApplies('(display:grid) and (unknown-feature:value)'),
    'unknown @supports terms remain fail-closed inside compound expressions'
);

// Tailwind v4 emits every opacity-modified colour as a progressive-enhancement
// pair: an opaque fallback, then the translucent value gated on
// `@supports (color:color-mix(in lab, red, red))`. Reading that gate as unknown
// takes the fallback, and every translucent colour on the page renders opaque.
// One real stylesheet carried 74 of these pairs, which is the shape of every
// Tailwind v4 build and so of essentially every Lovable/v0/Bolt site.
$assert(
    CssCascade::supportsConditionApplies('(color:color-mix(in lab, red, red))'),
    'the Tailwind v4 colour-mix gate applies'
);
$assert(
    CssCascade::supportsConditionApplies('(color: color-mix(in lab, red, red))'),
    'whitespace around the colon does not change the colour-mix gate'
);
foreach ( array( 'lab(29% 39 -52)', 'lch(29% 65 301)', 'oklab(0.4 0.09 -0.13)', 'oklch(0.4 0.16 301)', 'color(display-p3 1 1 1)' ) as $function ) {
    $assert(
        CssCascade::supportsConditionApplies("(color:{$function})"),
        "the widely available colour function {$function} applies"
    );
}
$assert(
    CssCascade::supportsConditionApplies('(background-color:color-mix(in oklab, var(--brand) 70%, transparent))'),
    'a nested-paren colour-mix value under a -color longhand applies'
);
$assert(
    CssCascade::supportsConditionApplies('(fill:oklch(0.4 0.16 301))'),
    'SVG paint accepts a widely available colour function'
);

// The allowlist states browser support, so everything it does not name stays
// unknown. Blanket-applying unsupported `@supports` blocks would be a worse
// defect than the opaque fallback this fixes.
$assert(
    ! CssCascade::supportsConditionApplies('(color: some-nonexistent-fn(1))'),
    'an unrecognised colour function remains fail-closed'
);
$assert(
    ! CssCascade::supportsConditionApplies('(color:rgb(from red r g b))'),
    'relative colour syntax remains unknown'
);
$assert(
    ! CssCascade::supportsConditionApplies('(color:lch(from red l c calc(h + 180deg)))'),
    'relative colour syntax stays unknown even inside an allowlisted function'
);
$assert(
    ! CssCascade::supportsConditionApplies('(width:color-mix(in lab, red, red))'),
    'a colour function under a property that takes no colour remains unknown'
);
$assert(
    ! CssCascade::supportsConditionApplies('(color:color-mix(in lab, red, red) nonsense)'),
    'a value that is more than one function call remains unknown'
);
$assert(
    ! CssCascade::supportsConditionApplies('not (color:color-mix(in lab, red, red))'),
    'negating a known-supported colour function is known false'
);
$assert(
    ! CssCascade::supportsConditionApplies('not (color: some-nonexistent-fn(1))'),
    'negating an unknown term stays unknown rather than becoming true'
);
$assert(
    CssCascade::supportsConditionApplies('(color: some-nonexistent-fn(1)) or (color:color-mix(in lab, red, red))'),
    'a known-supported colour alternative carries an or-expression past an unknown term'
);
$assert(
    ! CssCascade::supportsConditionApplies('not (display: grid)'),
    'negating a known-supported display value is still known false'
);

// Both sides of a parity comparison have to read the pair the same way. The
// probe inlines `@supports` bodies; the transformer evaluates the condition. An
// engine that resolved the fallback here reported the author's translucent
// colour as opaque against a source that reported it correctly.
$translucent = ':root{--foreground:oklch(0.24 0.031 254.5)}'
    . '.meta{color:var(--foreground)}'
    . '@supports (color:color-mix(in lab, red, red)){.meta{color:color-mix(in oklab, var(--foreground) 70%, transparent)}}';
$mixed = 'color-mix(in oklab, oklch(0.24 0.031 254.5) 70%, transparent)';

$metaPage = '<html><body><p class="meta">Independent designer</p></body></html>';
$result = $resolve($metaPage, $translucent, '//p', array( 'color' ));
$assert($mixed === ( $result['color'] ?? '' ), 'the source probe reads the @supports-gated translucent colour');

$assert(
    'color-mix(in oklab, var(--foreground) 70%, transparent)' === $resolvedAuthorColor($metaPage, $translucent, '//p'),
    'the transformer resolves the @supports-gated translucent colour, not the opaque fallback'
);

$result = $resolve($page, '@media print { .main-nav { display: none; } }', '//nav', array( 'display' ));
$assert(! isset($result['display']), 'a non-visual media type does not apply');

// Rules after a dropped block must survive: the block is brace-balanced, not
// regex-unwrapped, so the rule following it is still parsed.
$following = '@media (max-width: 600px) { .main-nav { display: none; } } .site-footer { color: rgb(7, 7, 7); }';
$result = $resolve($page, $following, '//footer', array( 'color' ));
$assert('rgb(7, 7, 7)' === ( $result['color'] ?? '' ), 'a rule following a dropped @media block still parses');

$result = $resolve($page, '@supports (display: grid) { .site-footer { color: rgb(8, 8, 8); } }', '//footer', array( 'color' ));
$assert('rgb(8, 8, 8)' === ( $result['color'] ?? '' ), '@supports rules still declare effective style');

// Inheritance still comes from the nearest declaring ancestor, and only once the
// ancestor's own rule is actually matched.
$inherited = "/* ─── Footer ─── */\n.site-footer { color: rgb(2, 4, 6); font-size: 0.92rem; }\n.col ul li a { font-size: 0.9rem; }";
$result = $resolve($page, $inherited, '//footer//li', array( 'color', 'font-size' ), array( 'color', 'font-size' ));
$assert('rgb(2, 4, 6)' === ( $result['color'] ?? '' ), 'a list item inherits colour from the footer, not the body default');
$assert('0.92rem' === ( $result['font-size'] ?? '' ), 'a list item inherits font-size from the footer');

$result = $resolve($page, $inherited, '//footer//a', array( 'font-size' ), array( 'font-size' ));
$assert('0.9rem' === ( $result['font-size'] ?? '' ), 'a more specific descendant rule still wins over inheritance');

// :is()/:where()/:not() are the grammar the transformer's own author-stylesheet
// projection emits to preserve author specificity. Without support the probe
// cannot match the candidate's generated rules and blames the transformer for a
// declaration it carried correctly.
$marked = '<html><body><div class="footer-col"><ul><li class="src-li"><a href="#">Link</a></li></ul></div></body></html>';

$projected = '.footer-col ul :where(.src-li):not(be-specificity-0) a { font-size: 0.9rem; }';
$result = $resolve($marked, $projected, '//a', array( 'font-size' ));
$assert('0.9rem' === ( $result['font-size'] ?? '' ), 'a projected :where()/:not() rule matches');

$result = $resolve($marked, '.footer-col :is(ul) li a { font-size: 0.8rem; }', '//a', array( 'font-size' ));
$assert('0.8rem' === ( $result['font-size'] ?? '' ), ':is() matches its argument');

$result = $resolve($marked, 'li:not(.src-li) a { font-size: 0.7rem; }', '//a', array( 'font-size' ));
$assert(! isset($result['font-size']), ':not() excludes a matching element');

$result = $resolve($marked, 'li:not(.other) a { font-size: 0.6rem; }', '//a', array( 'font-size' ));
$assert('0.6rem' === ( $result['font-size'] ?? '' ), ':not() admits a non-matching element');

// :where() contributes no specificity, which is the whole reason the projection
// uses it: the author's own rule must still win.
$specificity = '.footer-col a { font-size: 1rem; } :where(.footer-col) a { font-size: 2rem; }';
$result = $resolve($marked, $specificity, '//a', array( 'font-size' ));
$assert('1rem' === ( $result['font-size'] ?? '' ), ':where() adds no specificity');

$notSpecificity = 'a:not(.x) { font-size: 3rem; } a { font-size: 4rem; }';
$result = $resolve($marked, $notSpecificity, '//a', array( 'font-size' ));
$assert('3rem' === ( $result['font-size'] ?? '' ), ':not() contributes its argument to specificity');

// Broad type selectors that also match promoted controls are emitted with a
// zero-specificity exclusion guard. The ordinary anchor must match it; a control
// marker named in the guard must not.
$guarded = 'a:not(:where(.control-a,.control-b)) { text-decoration: none; }';
$result = $resolve($marked, $guarded, '//a', array( 'text-decoration' ));
$assert('none' === ( $result['text-decoration'] ?? '' ), 'a generated nested :not(:where()) guard admits ordinary anchors');

$controlled = '<html><body><a class="control-a" href="#">Control</a></body></html>';
$result = $resolve($controlled, $guarded, '//a', array( 'text-decoration' ));
$assert(! isset($result['text-decoration']), 'a generated nested :not(:where()) guard excludes projected controls');

$guardSpecificity = '.ordinary { font-size: 1rem; } a:not(:where(.control-a,.control-b)) { font-size: 2rem; }';
$ordinary = '<html><body><a class="ordinary" href="#">Ordinary</a></body></html>';
$result = $resolve($ordinary, $guardSpecificity, '//a', array( 'font-size' ));
$assert('1rem' === ( $result['font-size'] ?? '' ), ':not(:where()) adds zero specificity');

// Selector shapes this resolver used to accept from no author stylesheet. The
// local grammar recognised `#id`, `.class`, `tag`, `tag.class…` and combinator
// chains of those, so each of the following matched nothing and the property it
// declares was reported as absent from the source — a parity finding blaming the
// transformer for a declaration the author had written and the probe could not
// read.
$utility = '<html><body><div class="card wide md:hidden" data-variant="promo">Card</div></body></html>';

$result = $resolve($utility, '.card.wide { color: #101010; }', '//div', array( 'color' ));
$assert('#101010' === ( $result['color'] ?? '' ), 'a tagless compound class selector matches');

$result = $resolve($utility, '.card[data-variant="promo"] { color: #202020; }', '//div', array( 'color' ));
$assert('#202020' === ( $result['color'] ?? '' ), 'an attribute selector matches');

// Every Tailwind variant utility is an escaped identifier. Without this the
// probe is blind to the whole utility layer of a Tailwind build, which is the
// author CSS that #1865 and #1879 were about.
$result = $resolve($utility, '.md\\:hidden { display: none; }', '//div', array( 'display' ));
$assert('none' === ( $result['display'] ?? '' ), 'an escaped identifier matches the class the author wrote');

// Matching and specificity must read the same grammar. The escaped identifier is
// one class (0,1,0); the old heuristic scored it 11 by counting `.md` plus a
// phantom `hidden` type, which let it beat a genuinely more specific rule.
$escapedSpecificity = '.md\\:hidden { color: #303030; } div.card { color: #404040; }';
$result = $resolve($utility, $escapedSpecificity, '//div', array( 'color' ));
$assert('#404040' === ( $result['color'] ?? '' ), 'an escaped identifier is ranked as a single class');

// `:root` reaches the resolver through the production matcher now rather than a
// local special case, both bare and as a descendant scope.
$rooted = ':root { --ink: #505050; } :root .card { color: var(--ink); }';
$result = $resolve($utility, $rooted, '//div', array( 'color' ));
$assert('#505050' === ( $result['color'] ?? '' ), ':root declares custom properties and scopes descendants');

// Cascade layers. `@layer` wrappers used to be deleted textually, which threw
// away the author's own precedence: a later layer lost to an earlier one
// whenever the earlier was more specific, and unlayered engine CSS became
// indistinguishable from layered author CSS. Each expectation below was
// confirmed against Chromium's getComputedStyle for the same markup and CSS.
$layered = static function (string $css) use ($resolve, $utility): string {
    return $resolve($utility, $css, '//div', array( 'color' ))['color'] ?? '';
};

$assert(
    'blue' === $layered('@layer base, utilities;@layer base{.card.wide{color:red}}@layer utilities{div{color:blue}}'),
    'a later layer wins over an earlier layer that is more specific'
);
$assert(
    'blue' === $layered('@layer base, utilities;@layer utilities{div{color:blue}}@layer base{.card.wide{color:red}}'),
    'layer precedence comes from registration order, not from where the rules appear'
);
$assert(
    'red' === $layered('@layer utilities{div{color:blue}}.card.wide{color:red}'),
    'an unlayered rule beats any layer'
);
$assert(
    'red' === $layered('.card.wide{color:red}@layer utilities{div{color:blue}}'),
    'an unlayered rule beats a layer declared after it'
);
$assert(
    'red' === $layered('@layer base, utilities;@layer base{.card.wide{color:red!important}}@layer utilities{div{color:blue!important}}'),
    '!important reverses layer order'
);
$assert(
    'blue' === $layered('@layer utilities{div{color:blue!important}}.card.wide{color:red!important}'),
    'an !important unlayered rule loses to an !important layered one'
);
$assert(
    'red' === $layered('@layer base{div{color:blue}.card.wide{color:red}}'),
    'within one layer specificity still decides'
);

// A layer nobody registered by statement takes its position from first use, and
// a nested layer inherits its top-level ancestor's position.
$assert(
    'blue' === $layered('@layer base{.card.wide{color:red}}@layer utilities{div{color:blue}}'),
    'an unregistered layer takes its position from first use'
);
$assert(
    'blue' === $layered('@layer base, utilities;@layer base{@layer inner{.card.wide{color:red}}}@layer utilities{div{color:blue}}'),
    'a nested layer inherits its top-level ancestor position'
);

// @media inside @layer and @layer inside @media both have to survive the walk.
$assert(
    'blue' === $layered('@layer base, utilities;@layer base{.card.wide{color:red}}@layer utilities{@media (width>=48rem){div{color:blue}}}'),
    'a media block inside a layer keeps its layer'
);
$assert(
    'blue' === $layered('@layer base, utilities;@layer base{.card.wide{color:red}}@media (width>=48rem){@layer utilities{div{color:blue}}}'),
    'a layer inside a media block keeps its layer'
);
$assert(
    'red' === $layered('@layer base, utilities;@layer base{.card.wide{color:red}}@layer utilities{@media (width<=30rem){div{color:blue}}}'),
    'a media block that does not apply contributes nothing, layer or not'
);

// Escaped identifiers can contain the very characters that delimit CSS blocks.
// Tailwind arbitrary-value utilities do exactly this, so the stylesheet walk
// reads escapes, quotes, parens and brackets through the shared
// CssSyntaxScanner rather than counting raw braces: an escaped `{` must not
// open a block and desynchronise every rule after it.
$arbitrary = '<html><body><div class="w-[calc(100%-1rem)] content-{x}">T</div></body></html>';
$result = $resolve($arbitrary, '.w-\\[calc\\(100\\%-1rem\\)\\]{color:red}', '//div', array( 'color' ));
$assert('red' === ( $result['color'] ?? '' ), 'an escaped bracket-and-paren utility matches');

$result = $resolve($arbitrary, '.content-\\{x\\}{color:blue}div{font-size:9px}', '//div', array( 'color', 'font-size' ));
$assert('blue' === ( $result['color'] ?? '' ), 'an escaped brace does not open a block');
$assert('9px' === ( $result['font-size'] ?? '' ), 'a rule following an escaped brace is still read');

// At-rules that declare no element rules must not leak declarations.
$assert(
    'red' === $layered('@keyframes spin{from{color:blue}to{color:blue}}@font-face{font-family:x;src:url(a.woff2)}.card.wide{color:red}'),
    'keyframe stops and @font-face descriptors are not element rules'
);

// A known-supported `@supports` condition is not a responsive variant. It
// resolves the same way for every reader, so its declaration is a resting rule
// and reaches the block the way an unconditional declaration does. Reading it
// as conditional resolved every Tailwind v4 opacity-modified colour to the
// opaque fallback the framework only emits for browsers without color-mix().
$serialized = static function (string $css): string {
    $page = '<html><body><section class="hero"><h1 class="hero-title">Mara</h1></section></body></html>';
    return (string) ( ( new HtmlTransformer() )->transform($page, array( 'static_css' => $css ))->toArray()['serialized_blocks'] ?? '' );
};

$assert(
    str_contains(
        $serialized('.hero-title{line-height:0.92}@supports (display: grid){.hero-title{letter-spacing:-0.04em}}'),
        'letter-spacing:-0.04em'
    ),
    'a declaration behind a known-supported @supports condition is a resting rule'
);
// The invariant that keeps the above safe: a viewport-varying property stays
// stylesheet-owned so the conditional declaration can still win, even when the
// same property is also declared behind a true `@supports`.
$assert(
    ! str_contains(
        $serialized(
            '.hero-title{line-height:0.92}'
            . '@supports (display: grid){.hero-title{letter-spacing:-0.04em}}'
            . '@media (max-width: 768px){.hero-title{letter-spacing:-0.01em}}'
        ),
        'letter-spacing:-0.04em'
    ),
    'a property with a @media variant stays stylesheet-owned even under a true @supports'
);
$assert(
    ! str_contains(
        $serialized('.hero-title{line-height:0.92}@supports (color: some-nonexistent-fn(1)){.hero-title{letter-spacing:-0.09em}}'),
        'letter-spacing:-0.09em'
    ),
    'an @supports condition the allowlist cannot decide stays conditional'
);

// The end-to-end shape this fixes: the translucent colour reaches the block
// attribute instead of the fallback, and survives value mapping.
$pill = ( new HtmlTransformer() )->transform(
    '<html><body><div><button class="pill">All 06</button></div></body></html>',
    array(
        'static_css' => ':root{--foreground:oklch(0.24 0.031 254.5)}'
            . '.pill{background:#fff;padding:8px 16px;color:var(--foreground)}'
            . '@supports (color:color-mix(in lab, red, red)){'
            . '.pill{color:color-mix(in oklab, var(--foreground) 70%, transparent)}}',
    )
)->toArray();
$pillColor = static function (array $blocks) use (&$pillColor): string {
    foreach ( $blocks as $block ) {
        if ( 'core/button' === ( $block['blockName'] ?? '' ) ) {
            return (string) ( $block['attrs']['style']['color']['text'] ?? '' );
        }
        if ( ! empty($block['innerBlocks']) ) {
            $found = $pillColor($block['innerBlocks']);
            if ( '' !== $found ) {
                return $found;
            }
        }
    }
    return '';
};
$assert(
    'color-mix(in oklab, oklch(0.24 0.031 254.5) 70%, transparent)' === $pillColor($pill['blocks'] ?? array()),
    'a button keeps the @supports-gated translucent colour as its native attribute'
);
$assert(
    'pass' === ( $pill['source_reports']['wp_block_validity']['status'] ?? '' ),
    'a color-mix() button colour stays editor-valid'
);

if ( $failures > 0 ) {
    fwrite(STDERR, "StaticCssCascade unit tests: {$failures} failed, {$passes} passed\n");
    exit(1);
}
fwrite(STDOUT, "StaticCssCascade unit tests: {$passes} passed\n");
