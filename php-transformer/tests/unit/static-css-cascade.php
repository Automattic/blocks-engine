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

if ( $failures > 0 ) {
    fwrite(STDERR, "StaticCssCascade unit tests: {$failures} failed, {$passes} passed\n");
    exit(1);
}
fwrite(STDOUT, "StaticCssCascade unit tests: {$passes} passed\n");
