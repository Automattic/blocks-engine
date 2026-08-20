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

// NOT re-pointed: the `:hover` variant. Pseudo-class selectors are absent from
// the rule analysis this mapping reads — `.navlinks a.nav-cta:hover` never
// reaches `staticStyleRules` — so carrying it would need a separate extraction
// path. The resting pill is what a design review sees; the hover state is a
// known, deliberate omission rather than an oversight.
$assert(
    ! str_contains($ctaCss, $contentSelector . ':hover{'),
    'the hover variant is knowingly left unmapped, not silently half-emitted',
    substr($ctaCss, -300)
);

// The brand anchor is NOT a menu item, so its own rules must not be dragged in.
$assert(
    ! str_contains($ctaCss, '.wp-block-navigation-item.brand'),
    'only classes that actually ride a navigation-link are re-pointed',
    substr($ctaCss, -400)
);

// --- zesty-canyon: anchor ownership, not selector spelling, triggers mapping.
//
// Both CTA classes sit on source anchors. One rule names the anchor explicitly;
// the other is a bare class selector. core/navigation-link moves both classes to
// its <li>, so both authored rules must be re-pointed to the rendered anchor.
// A third bare class starts on the source <li>; it legitimately stays item-owned
// and must not be re-pointed merely because it appears in `className` too.
$surfaceHeader =
    '<style>.navlinks{list-style:none;display:flex}'
    . '.bare-cta{background:#22E1FF;color:#13202A;padding:.6rem 1.05rem;border:2px solid #22E1FF}'
    . '.navlinks a.scoped-cta{background:#22E1FF;color:#13202A;padding:.6rem 1.05rem;border:2px solid #22E1FF}'
    . '.li-only{background:#F00}</style>'
    . '<header><nav aria-label="Main">'
    . '<a class="brand" href="#hero">Super <span>Coaching</span></a>'
    . '<ul class="navlinks">'
    . '<li><a href="#hero">Home</a></li>'
    . '<li><a class="bare-cta" href="#bare">Bare CTA</a></li>'
    . '<li><a class="scoped-cta" href="#scoped">Scoped CTA</a></li>'
    . '<li class="li-only"><a href="#item">Item surface</a></li>'
    . '</ul></nav></header>';

$surfaceResult = $transform($surfaceHeader);
$surfaceSupportCss = implode("\n", array_map(
    static fn (array $asset): string => 'css' === ($asset['kind'] ?? '')
        && 'after-author' === ($asset['stylesheet_placement'] ?? '')
        ? (string) ($asset['content'] ?? '')
        : '',
    is_array($surfaceResult['assets'] ?? null) ? $surfaceResult['assets'] : array()
));

$mappedBody = static function (string $css, string $class): string {
    $selector = '.wp-block-navigation.blocks-engine-list-navigation .wp-block-navigation-item.'
        . $class . '>.wp-block-navigation-item__content';
    if ( preg_match('/' . preg_quote($selector, '/') . '\{([^}]*)\}/', $css, $match) ) {
        return $match[1];
    }
    return '';
};

$bareBody = $mappedBody($surfaceSupportCss, 'bare-cta');
$scopedBody = $mappedBody($surfaceSupportCss, 'scoped-cta');
$liBody = $mappedBody($surfaceSupportCss, 'li-only');

$assert(
    '' !== $bareBody && $bareBody === $scopedBody,
    'a bare rule on an anchor-carried class maps exactly like an explicit anchor rule',
    'bare=' . $bareBody . ' scoped=' . $scopedBody
);

$surfaceLinks = $findBlocks(
    is_array($surfaceResult['blocks'] ?? null) ? $surfaceResult['blocks'] : array(),
    'core/navigation-link'
);
$itemSurfaceLinks = array_values(array_filter(
    $surfaceLinks,
    static fn (array $block): bool => 'Item surface' === ($block['attrs']['label'] ?? '')
));
$itemSurfaceAttrs = is_array($itemSurfaceLinks[0]['attrs'] ?? null) ? $itemSurfaceLinks[0]['attrs'] : array();

$assert(
    str_contains((string) ($itemSurfaceAttrs['className'] ?? ''), 'li-only')
        && ! str_contains((string) ($itemSurfaceAttrs['anchorClassName'] ?? ''), 'li-only')
        && '' === $liBody,
    'a bare class authored on the source li stays item-owned and is not re-pointed',
    'attrs=' . json_encode($itemSurfaceAttrs) . ' body=' . $liBody
);


if ( $failures > 0 ) {
    fwrite(STDERR, "Navigation brand cue carrier contract: {$failures} failed, {$passes} passed\n");
    exit(1);
}

fwrite(STDOUT, "Navigation brand cue carrier contract passed: {$passes} assertions\n");
