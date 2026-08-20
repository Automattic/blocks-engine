<?php
declare(strict_types=1);

/**
 * A branding anchor that SAYS it is the brand must not get the worse shape.
 *
 * `hasDirectBrandingAnchorBesideListNavigation()` defers the whole container
 * whenever a direct-child anchor carries a brand cue from the class/id/rel
 * vocabulary. That guard predates `brandAnchorCarrier()`, which was written to
 * hold the brand beside a real `core/navigation` built from the link cluster
 * alone — exactly the outcome the deferral was avoiding by giving up.
 *
 * Because the deferral is consulted first, the two paths are inverted: an
 * anchor whose class is OUTSIDE the vocabulary (`class="mark"`) reaches the
 * carrier and becomes core/navigation, while the same markup with
 * `class="brand"` defers to a generic group + wp:list of raw anchors.
 *
 * That costs three things at once, all measured on silver-summit's real header:
 *
 *   - no core/navigation, so WordPress has no menu to mark the current page in;
 *   - `aria-current="page"` carried verbatim onto Home, on a template part every
 *     page shares, where it can never be right for more than one page;
 *   - destinations left as raw `href` in inner HTML rather than a
 *     navigation-link `url` attribute, where a later re-serialization
 *     regenerates them and discards resolved permalinks.
 *
 * The brand is still never absorbed as a menu item — that is what the carrier
 * guarantees structurally, and it is the property the deferral was protecting.
 *
 * NOT covered here: the authored current-state CLASS (`is-current`) is still
 * carried onto the item on purpose. It is the hook a design's own rule selects
 * on — see the `html-brand-anchor-beside-active-nav-list` parity fixture, whose
 * `.nav-links a.active::after` rule depends on it — so dropping it without
 * re-pointing that rule at WordPress's runtime `current-menu-item` would delete
 * the indicator rather than move it.
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

$failures = 0;
$passes = 0;

$assert = static function (bool $condition, string $message, string $detail = '') use (&$failures, &$passes): void {
    if ( $condition ) {
        ++$passes;
        return;
    }

    ++$failures;
    fwrite(STDERR, 'FAIL: ' . $message . ('' !== $detail ? ' - ' . $detail : '') . PHP_EOL);
};

$transform = static fn (string $html): array => ( new HtmlTransformer() )->transform($html, array())->toArray();

/** @param array<int, array<string, mixed>> $blocks */
$findBlocks = static function (array $blocks, string $name) use (&$findBlocks): array {
    $found = array();
    foreach ( $blocks as $block ) {
        if ( $name === ($block['blockName'] ?? '') ) {
            $found[] = $block;
        }
        $found = array_merge($found, $findBlocks(is_array($block['innerBlocks'] ?? null) ? $block['innerBlocks'] : array(), $name));
    }

    return $found;
};

// silver-summit's real header: a <nav> landmark, a lockup brand anchor, and a
// three-item menu list. Only the brand anchor's class differs between arms.
$header = static fn (string $brandClass): string =>
    '<style>header nav{display:flex;gap:20px;padding:22px}'
    . '.navlinks{list-style:none;display:flex;gap:16px;margin:0;padding:0}</style>'
    . '<header><nav aria-label="Primary">'
    . '<a class="' . $brandClass . '" href="#hero">Super Coaching<span>Plymouth, New Hampshire</span></a>'
    . '<ul class="navlinks">'
    . '<li><a class="is-current" href="#hero" aria-current="page">Home</a></li>'
    . '<li><a href="#hero">About &amp; Services</a></li>'
    . '<li><a href="#hero">Contact</a></li>'
    . '</ul></nav></header>';

$shape = static function (string $brandClass) use ($transform, $findBlocks, $header): array {
    $result = $transform($header($brandClass));
    $blocks = is_array($result['blocks'] ?? null) ? $result['blocks'] : array();
    $markup = (string) ($result['serialized_blocks'] ?? '');
    $css = implode("\n", array_map(
        static fn (array $asset): string => 'css' === ($asset['kind'] ?? '') ? (string) ($asset['content'] ?? '') : '',
        is_array($result['assets'] ?? null) ? $result['assets'] : array()
    ));
    $navigations = $findBlocks($blocks, 'core/navigation');
    $carriers = array_values(array_filter(
        $findBlocks($blocks, 'core/group'),
        static fn (array $block): bool => 'nav' === (string) ($block['attrs']['tagName'] ?? '')
    ));

    $links = $findBlocks($blocks, 'core/navigation-link');
    $linksWithUrl = array_values(array_filter(
        $links,
        static fn (array $block): bool => '' !== trim((string) ($block['attrs']['url'] ?? ''))
    ));

    // Class tokens the DESIGN authored to name a current state, on any menu item.
    // The engine's own `blocks-engine-current-navigation-*` markers are excluded:
    // they are not what an authored rule keys on, and they carry resolved
    // decoration paint that has nowhere else to live.
    $currentTokens = array();
    foreach ( $links as $link ) {
        foreach ( preg_split('/\s+/', trim((string) ($link['attrs']['className'] ?? ''))) ?: array() as $token ) {
            if ( '' === $token || str_starts_with($token, 'blocks-engine-') ) {
                continue;
            }
            if ( preg_match('/(?:^|[^a-z])(?:current|active|selected)/i', $token) ) {
                $currentTokens[] = $token;
            }
        }
    }

    return array(
        'navigations' => count($navigations),
        'carriers' => array() === $navigations ? 0 : count($carriers),
        'links' => count($links),
        'linksWithUrl' => count($linksWithUrl),
        'lists' => count($findBlocks($blocks, 'core/list')),
        'ariaCurrent' => substr_count($markup, 'aria-current'),
        'rawHeroHrefs' => substr_count($markup, 'href="#hero"'),
        'currentTokens' => $currentTokens,
        'staticUnderline' => substr_count($markup, '"textDecoration":"underline"'),
        'carrierSizingRule' => (int) str_contains(
            $css,
            'nav.wp-block-group>.wp-block-navigation.blocks-engine-list-navigation{width:max-content'
        ),
    );
};

$cued = $shape('brand');
$uncued = $shape('mark');

// The load-bearing assertion: the two arms differ only in a class name, so any
// difference in emitted SHAPE is the inversion itself. This cannot pass by
// accident — it fails the moment one arm takes a different path from the other.
$assert(
    $cued['navigations'] === $uncued['navigations']
        && $cued['links'] === $uncued['links']
        && $cued['lists'] === $uncued['lists'],
    'a brand cue in the vocabulary produces the same shape as one outside it',
    'cued=' . json_encode($cued) . ' uncued=' . json_encode($uncued)
);

$assert(
    1 === $cued['navigations'] && 1 === $cued['carriers'],
    'a cued brand beside a list yields one carrier holding one core/navigation',
    json_encode($cued)
);

$assert(
    3 === $cued['links'] && 0 === $cued['lists'],
    'every menu item becomes a navigation-link, so destinations live in block attributes',
    json_encode($cued)
);

// Every MENU destination moves into a navigation-link `url` attribute, which is
// what survives re-serialization; a raw inner-HTML href does not. Exactly one
// raw href remains and it is the brand anchor's own link — the brand is its own
// block by design, and a consumer resolves it by lexical <nav> ancestry.
$assert(
    3 === $cued['linksWithUrl'] && 1 === $cued['rawHeroHrefs'],
    'menu destinations live in navigation-link url attributes, leaving only the brand anchor raw',
    json_encode($cued)
);

// The design's static "you are here" marker must not be baked onto one item of
// a template part every page shares; WordPress marks the current page itself.
$assert(
    0 === $cued['ariaCurrent'],
    'the design-time aria-current is not carried onto a shared navigation',
    json_encode($cued)
);


// Design parity for the carrier shape.
//
// The carrier renders <nav> and core/navigation renders another <nav> inside
// it, so the authored landmark rule now matches both. The inner block's auto
// flex-basis then resolves to the whole available width where the authored
// <ul> was content-sized, leaving `justify-content:space-between` nothing to
// distribute and squeezing the brand until it wraps.
//
// Measured on silver-summit at 1366px: brand 181x44 and menu 308 at x=962
// before, 155x82 and menu 1005 at x=265 after, restored exactly by sizing the
// block to its content. `max-width:100%` keeps it shrinkable so a narrow
// viewport still hands over to core's responsive overlay instead of overflowing.
$assert(
    1 === $cued['carrierSizingRule'],
    'the carrier emits a rule sizing its navigation block to its content',
    substr((string) json_encode($cued), 0, 220)
);

// --- sunny-ember: a CTA styled through the ANCHOR, inside the menu list.
//
// `render_block_core_navigation_link()` hard-codes the anchor's class, which is
// why `anchorClassName` is deliberately discarded downstream. The authored class
// therefore lands on the <li>, and an anchor-scoped rule like
// `.navlinks a.nav-cta{...}` selects nothing — sunny-ember's yellow pill
// rendered as plain text. A compat rule re-points that authored selector at the
// element core actually gives the class-bearing item.
$ctaHeader =
    '<style>header nav{display:flex;gap:20px;padding:22px}'
    . '.navlinks{list-style:none;display:flex;gap:16px;margin:0;padding:0}'
    . '.navlinks a.nav-cta{background:#FFD400;color:#1B1033;padding:.5rem 1.05rem}'
    . '.navlinks a.nav-cta:hover{background:#fff}</style>'
    . '<header><nav aria-label="Main">'
    . '<a class="brand" href="#hero">Super <span>Coaching</span></a>'
    . '<ul class="navlinks">'
    . '<li><a href="#hero">Home</a></li>'
    . '<li><a href="#services">Services</a></li>'
    . '<li><a class="nav-cta" href="#contact">Book a session</a></li>'
    . '</ul></nav></header>';

$ctaResult = $transform($ctaHeader);
$ctaCss = implode("\n", array_map(
    static fn (array $asset): string => 'css' === ($asset['kind'] ?? '') ? (string) ($asset['content'] ?? '') : '',
    is_array($ctaResult['assets'] ?? null) ? $ctaResult['assets'] : array()
));

$contentSelector = '.wp-block-navigation.blocks-engine-list-navigation .wp-block-navigation-item.nav-cta>.wp-block-navigation-item__content';

$assert(
    str_contains($ctaCss, $contentSelector . '{'),
    'an anchor-scoped item rule is re-pointed at the rendered navigation-link content',
    substr($ctaCss, -400)
);

// Read the body of the mapped rule itself. Asserting on the whole stylesheet
// would pass on the author's own carried copy of the rule and prove nothing.
$ctaBody = '';
if ( preg_match('/' . preg_quote($contentSelector, '/') . '\{([^}]*)\}/', $ctaCss, $ctaMatch) ) {
    $ctaBody = $ctaMatch[1];
}
$assert(
    str_contains($ctaBody, 'background:#FFD400') && str_contains($ctaBody, 'padding:.5rem 1.05rem'),
    'the authored pill paint and padding travel into the mapped rule',
    'body=' . $ctaBody
);

// No !important. The adaptive-header chrome used to force
// `color: var(--header-foreground) !important` over every header link, which
// left this pill with white text on yellow; that rule is no longer forced, so
// the mapped declaration wins on ordinary cascade and needs no escalation.
$assert(
    str_contains($ctaBody, 'color:#1B1033') && ! str_contains($ctaBody, '!important'),
    'the mapped rule carries the authored colour without escalating to !important',
    'body=' . $ctaBody
);

// Interaction rules that depend on the anchor-owned class need the same
// re-pointing. The class moves to the navigation item, so the source selector
// cannot match core's rendered anchor by itself.
$assert(
    str_contains($ctaCss, $contentSelector . ':hover{background:#fff}'),
    'the authored hover variant is re-pointed onto rendered navigation-link content',
    substr($ctaCss, -300)
);

// The brand anchor is NOT a menu item, so its own rules must not be dragged in.
$assert(
    ! str_contains($ctaCss, '.wp-block-navigation-item.brand'),
    'only classes that actually ride a navigation-link are re-pointed',
    substr($ctaCss, -400)
);

// A menu's authored default colour belongs on core/navigation, because dynamic
// core/navigation-link output does not render its own style.color.text. One
// current item or CTA may deliberately override that default, so unanimity is
// too strict: carry only a unique strict majority and abstain on a tie.
$majorityColour = $transform(
    '<style>.primary-menu a{color:#5c7c99}.primary-menu a.current{color:#dde6ef}.primary-menu a.nav-cta{color:#071018}</style>'
        . '<nav class="primary-menu"><a class="current" href="/">Home</a><a href="/services">Services</a>'
        . '<a href="/about">About</a><a class="nav-cta" href="/book">Book</a><a href="/contact">Contact</a></nav>'
);
$majorityNavigation = $findBlocks($majorityColour['blocks'] ?? array(), 'core/navigation')[0] ?? array();
$assert(
    '#5c7c99' === ($majorityNavigation['attrs']['customTextColor'] ?? null),
    'a unique strict-majority link colour is carried by core/navigation',
    json_encode($majorityNavigation['attrs'] ?? array())
);
$assert(
    '#dde6ef' === ($majorityNavigation['innerBlocks'][0]['attrs']['style']['color']['text'] ?? null)
        && '#071018' === ($majorityNavigation['innerBlocks'][3]['attrs']['style']['color']['text'] ?? null),
    'current and CTA link colour exceptions remain on their own navigation links',
    json_encode($majorityNavigation['innerBlocks'] ?? array())
);

// Source order breaks ties; it does not let a later single class beat an
// earlier descendant selector. zesty-canyon relies on this exact cascade: its
// menu-wide anchor colour remains the CTA text colour because `.nav-cta` is
// less specific than `.navlinks a`.
$specificityColour = $transform(
    '<style>.specific-menu a{color:#5c7c99}.nav-cta{color:#071018}</style>'
        . '<nav class="specific-menu"><a href="/">Home</a><a href="/services">Services</a>'
        . '<a class="nav-cta" href="/book">Book</a></nav>'
);
$specificityNavigation = $findBlocks($specificityColour['blocks'] ?? array(), 'core/navigation')[0] ?? array();
$assert(
    '#5c7c99' === ($specificityNavigation['innerBlocks'][2]['attrs']['style']['color']['text'] ?? null),
    'navigation link colour resolution honors selector specificity before source order',
    json_encode($specificityNavigation['innerBlocks'][2]['attrs'] ?? array())
);

$alphaColour = $transform(
    '<style>.alpha-menu a{color:rgba(255,255,255,0.82)}.alpha-menu a.active{color:rgb(255,255,255)}</style>'
        . '<nav class="alpha-menu"><a class="active" href="/">Home</a><a href="/work">Work</a><a href="/about">About</a></nav>'
);
$alphaNavigation = $findBlocks($alphaColour['blocks'] ?? array(), 'core/navigation')[0] ?? array();
$assert(
    'rgba(255,255,255,0.82)' === ($alphaNavigation['attrs']['customTextColor'] ?? null),
    'strict-majority promotion preserves authored alpha bytes',
    json_encode($alphaNavigation['attrs'] ?? array())
);

// Core renders navigation-link dynamically and does not consume its
// style.color.text attribute. Each authored resting colour therefore needs a
// class-scoped companion rule on the anchor core actually renders. This also
// outranks adaptive header defaults that target that anchor directly.
$alphaCss = implode("\n", array_map(
    static fn (array $asset): string => 'css' === ($asset['kind'] ?? '') ? (string) ($asset['content'] ?? '') : '',
    is_array($alphaColour['assets'] ?? null) ? $alphaColour['assets'] : array()
));
$alphaLinks = $alphaNavigation['innerBlocks'] ?? array();
$alphaCarrier = 'blocks-engine-navigation-link-color-' . hash('sha256', 'rgba(255,255,255,0.82)');
$activeCarrier = 'blocks-engine-navigation-link-color-' . hash('sha256', 'rgb(255,255,255)');
$currentCarrier = 'blocks-engine-navigation-current-color-' . hash('sha256', 'rgb(255,255,255)');
$assert(
    str_contains((string) ($alphaLinks[1]['attrs']['className'] ?? ''), $alphaCarrier)
        && str_contains((string) ($alphaLinks[2]['attrs']['className'] ?? ''), $alphaCarrier)
        && ! str_contains((string) ($alphaLinks[0]['attrs']['className'] ?? ''), $activeCarrier),
    'resting links carry deterministic colour classes without baking the design-time current item',
    json_encode($alphaLinks)
);
$assert(
    str_contains($alphaCss, '.' . $alphaCarrier . '>.wp-block-navigation-item__content{color:rgba(255,255,255,0.82)}')
        && str_contains($alphaCss, '{color:rgba(255,255,255,0.82)}')
        && ! str_contains($alphaCss, '.' . $alphaCarrier . '>.wp-block-navigation-item__content:not(')
        && ! str_contains($alphaCss, 'color:rgba(255,255,255,0.82)!important'),
    'a resting colour with no authored interaction replacement survives every interaction state',
    substr($alphaCss, -900)
);
$assert(
    str_contains((string) ($alphaNavigation['attrs']['className'] ?? ''), $currentCarrier)
        && str_contains($alphaCss, '.wp-block-navigation.' . $currentCarrier . ' .wp-block-navigation-item.current-menu-item>.wp-block-navigation-item__content:not(:hover)')
        && str_contains($alphaCss, '.wp-block-navigation.' . $currentCarrier . ' .wp-block-navigation-item__content[aria-current]:not(:hover)')
        && str_contains($alphaCss, '{color:rgb(255,255,255)}'),
    'authored current colour follows the WordPress runtime current item within its navigation',
    'attrs=' . json_encode($alphaNavigation['attrs'] ?? array()) . ' css=' . substr($alphaCss, -1200)
);

$uncolouredNavigationResult = $transform(
    '<nav class="plain-menu"><a href="/">Home</a><a href="/about">About</a></nav>'
);
$assert(
    ! str_contains((string) ($uncolouredNavigationResult['serialized_blocks'] ?? ''), 'blocks-engine-navigation-link-color-'),
    'uncoloured navigation links remain under theme colour ownership',
    (string) ($uncolouredNavigationResult['serialized_blocks'] ?? '')
);

$submenuColour = $transform(
    '<style>.submenu-colour a{color:#345678}</style><nav class="submenu-colour"><ul>'
        . '<li><a href="/services">Services</a><ul><li><a href="/design">Design</a></li></ul></li>'
        . '<li><a href="/about">About</a></li></ul></nav>'
);
$submenuBlocks = $findBlocks($submenuColour['blocks'] ?? array(), 'core/navigation-submenu');
$submenuCss = implode("\n", array_map(
    static fn (array $asset): string => 'css' === ($asset['kind'] ?? '') ? (string) ($asset['content'] ?? '') : '',
    is_array($submenuColour['assets'] ?? null) ? $submenuColour['assets'] : array()
));
$submenuCarrier = 'blocks-engine-navigation-link-color-' . hash('sha256', '#345678');
$assert(
    1 === count($submenuBlocks)
        && str_contains((string) ($submenuBlocks[0]['attrs']['className'] ?? ''), $submenuCarrier)
        && str_contains($submenuCss, '.' . $submenuCarrier . '>.wp-block-navigation-item__content{color:#345678}'),
    'a coloured navigation-submenu owner receives the same rendered-anchor carrier',
    'blocks=' . json_encode($submenuBlocks) . ' css=' . substr($submenuCss, -700)
);

$inlineColour = $transform(
    '<nav><a href="/" style="color:#aa1100">Home</a><a href="/about" style="color:#aa1100">About</a></nav>'
);
$inlineCss = implode("\n", array_map(
    static fn (array $asset): string => 'css' === ($asset['kind'] ?? '') ? (string) ($asset['content'] ?? '') : '',
    is_array($inlineColour['assets'] ?? null) ? $inlineColour['assets'] : array()
));
$inlineCarrier = 'blocks-engine-navigation-link-color-' . hash('sha256', '#aa1100');
$assert(
    str_contains($inlineCss, '.' . $inlineCarrier . '>.wp-block-navigation-item__content{color:#aa1100}')
        && ! str_contains($inlineCss, '.' . $inlineCarrier . '>.wp-block-navigation-item__content:not('),
    'direct inline navigation colour remains authored during hover focus and active states',
    substr($inlineCss, -700)
);

$hoverColour = $transform(
    '<style>.hover-menu a{color:#112233}.hover-menu a:hover{color:#ddeeff}</style>'
        . '<nav class="hover-menu"><a href="/">Home</a><a href="/about">About</a></nav>'
);
$hoverCss = implode("\n", array_map(
    static fn (array $asset): string => 'css' === ($asset['kind'] ?? '') ? (string) ($asset['content'] ?? '') : '',
    is_array($hoverColour['assets'] ?? null) ? $hoverColour['assets'] : array()
));
$hoverCarrier = 'blocks-engine-navigation-link-color-' . hash('sha256', '#112233');
$assert(
    str_contains($hoverCss, '.' . $hoverCarrier . '>.wp-block-navigation-item__content:not(:hover){color:#112233}')
        && ! str_contains($hoverCss, '.' . $hoverCarrier . '>.wp-block-navigation-item__content:not(:hover):not(:focus)'),
    'resting carrier yields only the interaction state with an authored colour replacement',
    substr($hoverCss, -800)
);

$dynamicCurrentList = $transform(
    '<style>.current-menu a{color:#223344}.current-menu a.current{color:#aa1100}</style>'
        . '<nav class="current-menu"><ul><li><a class="current" aria-current="page" href="/">Home</a></li>'
        . '<li><a href="/about">About</a></li><li><a href="/contact">Contact</a></li></ul></nav>'
);
$dynamicCurrentCss = implode("\n", array_map(
    static fn (array $asset): string => 'css' === ($asset['kind'] ?? '') ? (string) ($asset['content'] ?? '') : '',
    is_array($dynamicCurrentList['assets'] ?? null) ? $dynamicCurrentList['assets'] : array()
));
$staticCurrentSelector = '.wp-block-navigation.blocks-engine-list-navigation .wp-block-navigation-item.current>.wp-block-navigation-item__content';
$assert(
    ! str_contains($dynamicCurrentCss, $staticCurrentSelector . '{color:#aa1100}')
        && str_contains($dynamicCurrentCss, '.wp-block-navigation.blocks-engine-navigation-current-color-')
        && str_contains($dynamicCurrentCss, '.current-menu-item>.wp-block-navigation-item__content'),
    'list-shaped current colour targets runtime current state without colouring the static source item',
    substr($dynamicCurrentCss, -1200)
);

$tiedColour = $transform(
    '<style>.mixed-menu a.warm{color:#a34d35}.mixed-menu a.cool{color:#356ea3}</style>'
        . '<nav class="mixed-menu"><a class="warm" href="/one">One</a><a class="cool" href="/two">Two</a></nav>'
);
$tiedNavigation = $findBlocks($tiedColour['blocks'] ?? array(), 'core/navigation')[0] ?? array();
$assert(
    ! isset($tiedNavigation['attrs']['customTextColor']) && ! isset($tiedNavigation['attrs']['textColor']),
    'a tied mixed-colour menu keeps no invented parent colour',
    json_encode($tiedNavigation['attrs'] ?? array())
);


if ( $failures > 0 ) {
    fwrite(STDERR, "Navigation brand cue carrier contract: {$failures} failed, {$passes} passed\n");
    exit(1);
}

fwrite(STDOUT, "Navigation brand cue carrier contract passed: {$passes} assertions\n");
