<?php
declare(strict_types=1);

/**
 * Contract for hoisting a branding anchor out of a promoted navigation.
 *
 * A header that authors three distinct elements — a nav landmark, a branding
 * anchor, and a menu list — collapsed into ONE core/navigation with the brand as
 * an extra menu item. The brand then emitted `anchorClassName`, which is not a
 * registered core/navigation-link attribute, and the menu list's className was
 * copied onto the nav container so `.navlinks{padding:0}` and
 * `header nav{padding:22px}` selected the same element.
 *
 * The branding anchor is detected structurally — a direct-child anchor outside
 * the link cluster — not from a class vocabulary, so a designer's choice of
 * class name cannot reopen the defect.
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

$classTokens = static fn (array $block): array => preg_split('/\s+/', trim((string) ($block['attrs']['className'] ?? ''))) ?: array();

// -- Azure-garden shape: brand class outside any allowlist, beside `<ul>` links.
$unlistedBrandCss = 'header nav{max-width:1200px;margin:0 auto;padding:18px 22px 20px;display:flex;flex-direction:column;gap:16px;align-items:flex-start}'
    . '.mark{display:flex;flex-direction:column;line-height:1}'
    . '.mark .name{font-weight:600;font-size:1.4rem}'
    . '.mark .place{margin-top:7px;font-size:.6rem;letter-spacing:.16em;text-transform:uppercase;color:#A9B4C2}'
    . '.navlinks{list-style:none;margin:0;padding:0;display:flex;flex-wrap:wrap;gap:14px 22px}'
    . '.navlinks a{font-size:.635rem;letter-spacing:.16em;text-transform:uppercase;color:#DDE3EB}'
    . '@media (min-width:720px){header nav{flex-direction:row;align-items:flex-end;justify-content:space-between;padding:22px 22px 22px}}';
$unlistedBrand = $transform(
    '<style>' . $unlistedBrandCss . '</style>'
    . '<header><nav aria-label="Primary">'
    . '<a class="mark" href="/"><span class="name">Harbor Studio</span><span class="place">Camden, Maine</span></a>'
    . '<ul class="navlinks">'
    . '<li><a href="/">Home</a></li><li><a href="/work/">Work</a></li><li><a href="/studio/">Studio</a></li>'
    . '<li><a href="/journal/">Journal</a></li><li><a href="/contact/">Contact</a></li>'
    . '</ul></nav></header>'
);
$unlistedMarkup = (string) ($unlistedBrand['serialized_blocks'] ?? '');
$unlistedBlocks = is_array($unlistedBrand['blocks'] ?? null) ? $unlistedBrand['blocks'] : array();
$unlistedNavigations = $findBlocks($unlistedBlocks, 'core/navigation');
$unlistedLinks = $findBlocks($unlistedBlocks, 'core/navigation-link');
$unlistedNavGroups = array_values(array_filter(
    $findBlocks($unlistedBlocks, 'core/group'),
    static fn (array $block): bool => 'nav' === (string) ($block['attrs']['tagName'] ?? '')
));

$assert(
    1 === count($unlistedNavigations),
    'unlisted brand class: the menu still promotes to exactly one core/navigation',
    (string) count($unlistedNavigations)
);
$assert(
    5 === count($unlistedLinks),
    'unlisted brand class: only the five menu anchors become navigation links',
    (string) count($unlistedLinks)
);
$assert(
    1 === count($unlistedNavGroups),
    'unlisted brand class: the nav landmark is emitted as a core/group{tagName:nav} carrier',
    (string) count($unlistedNavGroups)
);
$assert(
    ! str_contains($unlistedMarkup, 'anchorClassName'),
    'unlisted brand class: no navigation link carries the unregistered anchorClassName attribute',
    $unlistedMarkup
);
$assert(
    0 === preg_match('/wp:navigation-link(?:(?!-->).)*Camden/s', $unlistedMarkup),
    'unlisted brand class: the branding anchor is not folded in as a menu item label',
    $unlistedMarkup
);
$assert(
    str_contains($unlistedMarkup, '<a class="mark" href="/">'),
    'unlisted brand class: the branding anchor keeps its own class so its authored layout still applies',
    $unlistedMarkup
);
$assert(
    0 === count($findBlocks($unlistedBlocks, 'core/html')),
    'unlisted brand class: the hoisted brand is a real block rather than an HTML fallback',
    $unlistedMarkup
);
$assert(
    ! isset($unlistedNavigations[0]['attrs']['customTextColor']) && str_contains(implode("\n", array_column($unlistedBrand['assets'] ?? array(), 'content')), 'color:#DDE3EB'),
    'unlisted brand class: the colour every remaining link shares remains in projected navigation CSS',
    json_encode($unlistedNavigations[0]['attrs'] ?? array())
);

// -- N4: the menu list's className must not land on the nav container, where it
// would outrank `header nav` and zero the header's padding.
$assert(
    array() !== $unlistedNavGroups && ! in_array('navlinks', $classTokens($unlistedNavGroups[0]), true),
    'promoted nav className: the list class is not copied onto the nav container element',
    json_encode($unlistedNavGroups[0]['attrs'] ?? array())
);
$assert(
    in_array('navlinks', $classTokens($unlistedNavigations[0]), true),
    'promoted nav className: the list class moves to the block that stands in for the list',
    json_encode($unlistedNavigations[0]['attrs'] ?? array())
);
$assert(
    1 === preg_match('/<nav class="wp-block-group(?![^"]*\bnavlinks\b)[^"]*"/', $unlistedMarkup),
    'promoted nav className: the saved nav element carries no list class',
    $unlistedMarkup
);

// -- Tbilisi shape: allowlisted brand class, links in a div cluster with no list.
$divClusterBrand = $transform(
    '<style>header.site-header nav{max-width:1200px;margin:0 auto;padding:0.9rem 2rem;display:flex;flex-wrap:wrap;align-items:baseline;gap:0.6rem 2rem}'
    . '.brand{font-weight:800;text-transform:uppercase;margin-right:auto;line-height:1}'
    . '.nav-links{display:flex;flex-wrap:wrap;gap:0.35rem 1.9rem;align-items:baseline}'
    . '.nav-links a{font-size:0.76rem;letter-spacing:0.19em;text-transform:uppercase;color:#231f1d}</style>'
    . '<header class="site-header"><nav aria-label="Primary">'
    . '<a class="brand" href="/">Harbor Tavern<span>Old Town</span></a>'
    . '<div class="nav-links">'
    . '<a href="/">Home</a><a href="/menu/">Menu</a><a href="/about/">About</a>'
    . '<a href="/visit/">Visit</a><a href="/reserve/">Reservations</a>'
    . '</div></nav></header>'
);
$divMarkup = (string) ($divClusterBrand['serialized_blocks'] ?? '');
$divBlocks = is_array($divClusterBrand['blocks'] ?? null) ? $divClusterBrand['blocks'] : array();

$assert(
    1 === count($findBlocks($divBlocks, 'core/navigation')),
    'div link cluster: the anchor cluster still promotes to one core/navigation',
    (string) count($findBlocks($divBlocks, 'core/navigation'))
);
$assert(
    5 === count($findBlocks($divBlocks, 'core/navigation-link')),
    'div link cluster: only the five menu anchors become navigation links',
    (string) count($findBlocks($divBlocks, 'core/navigation-link'))
);
$assert(
    ! str_contains($divMarkup, 'anchorClassName'),
    'div link cluster: no navigation link carries the unregistered anchorClassName attribute',
    $divMarkup
);
$assert(
    0 === count($findBlocks($divBlocks, 'core/html')),
    'div link cluster: the hoisted brand is a real block rather than an HTML fallback',
    $divMarkup
);

// -- A lockup built from block-level markup is still a brand, not a menu item:
// the carrier converts the anchor instead of flattening it into a label.
$blockLevelBrand = $transform(
    '<style>header nav{display:flex;justify-content:space-between;padding:22px}.wordmark{display:block}'
    . '.navlinks{list-style:none;margin:0;padding:0;display:flex;gap:20px}</style>'
    . '<header><nav aria-label="Primary"><a class="wordmark" href="/"><h1>Harbor Studio</h1></a>'
    . '<ul class="navlinks"><li><a href="/">Home</a></li><li><a href="/work/">Work</a></li></ul></nav></header>'
);
$blockLevelMarkup = (string) ($blockLevelBrand['serialized_blocks'] ?? '');
$blockLevelBlocks = is_array($blockLevelBrand['blocks'] ?? null) ? $blockLevelBrand['blocks'] : array();

$assert(
    ! str_contains($blockLevelMarkup, 'anchorClassName'),
    'block-level brand lockup: the heading anchor is not folded in as a menu item',
    $blockLevelMarkup
);
$assert(
    2 === count($findBlocks($blockLevelBlocks, 'core/navigation-link')),
    'block-level brand lockup: only the two menu anchors become navigation links',
    (string) count($findBlocks($blockLevelBlocks, 'core/navigation-link'))
);
$assert(
    1 === count($findBlocks($blockLevelBlocks, 'core/heading')) && 0 === count($findBlocks($blockLevelBlocks, 'core/html')),
    'block-level brand lockup: the authored heading survives as a heading block',
    $blockLevelMarkup
);

// -- Structural position alone is not a brand. An ordinary menu link that happens
// to sit outside the list keeps its place in the menu: it stays a navigation link,
// keeps the promoted shared colour, and never becomes a hoisted sibling block.
$bareLinkOutsideList = $transform(
    '<style>header nav{display:flex;justify-content:space-between;padding:22px}'
    . '.menu{list-style:none;margin:0;padding:0;display:flex;gap:20px}.menu a,nav>a{color:#DDE3EB}</style>'
    . '<header><nav aria-label="Primary"><a href="/">Home</a>'
    . '<ul class="menu"><li><a href="/about/">About</a></li><li><a href="/contact/">Contact</a></li></ul></nav></header>'
);
$bareLinkBlocks = is_array($bareLinkOutsideList['blocks'] ?? null) ? $bareLinkOutsideList['blocks'] : array();
$bareLinkNavigations = $findBlocks($bareLinkBlocks, 'core/navigation');

$assert(
    3 === count($findBlocks($bareLinkBlocks, 'core/navigation-link')),
    'bare link outside the list: all three anchors stay navigation links',
    (string) count($findBlocks($bareLinkBlocks, 'core/navigation-link'))
);
$assert(
    array() === array_values(array_filter(
        $findBlocks($bareLinkBlocks, 'core/group'),
        static fn (array $block): bool => 'nav' === (string) ($block['attrs']['tagName'] ?? '')
    )),
    'bare link outside the list: no carrier group is emitted',
    (string) ($bareLinkOutsideList['serialized_blocks'] ?? '')
);
$assert(
    ! isset($bareLinkNavigations[0]['attrs']['customTextColor']) && str_contains(implode("\n", array_column($bareLinkOutsideList['assets'] ?? array(), 'content')), 'color:#DDE3EB'),
    'bare link outside the list: the shared link colour remains in projected navigation CSS',
    json_encode($bareLinkNavigations[0]['attrs'] ?? array())
);

// -- Semantic parity must read the hoist as faithful, not as content loss: the
// brand's link belongs to the menu's item list even though it now sits beside the
// navigation block. A brand that converts to a button nests the same anchor in two
// levels of saved markup, so it must still be counted exactly once.
$carrierParity = static function (array $result): array {
    $report = $result['source_reports']['semantic_parity'] ?? array();
    $menus = $report['navigation_menus'] ?? array();

    return array(
        'status' => (string) ($report['status'] ?? ''),
        'source' => (int) ($menus['source'][0]['item_count'] ?? -1),
        'blocks' => (int) ($menus['blocks'][0]['item_count'] ?? -1),
        'findings' => count(is_array($report['findings'] ?? null) ? $report['findings'] : array()),
    );
};

$lockupParity = $carrierParity($unlistedBrand);
$assert(
    'pass' === $lockupParity['status'] && 0 === $lockupParity['findings'],
    'hoisted brand: semantic parity reads the carrier as faithful',
    json_encode($lockupParity)
);
$assert(
    $lockupParity['source'] === $lockupParity['blocks'],
    'hoisted brand: the reported block item count matches the source item count',
    json_encode($lockupParity)
);

$buttonBrandParity = $carrierParity($transform(
    '<style>nav{display:flex;gap:20px}'
    . '.mark{display:inline-block;padding:10px 18px;background:#12151a;color:#fff;border-radius:6px}'
    . '.navlinks{list-style:none;display:flex;gap:16px;margin:0;padding:0}</style>'
    . '<nav aria-label="Primary"><a class="mark" href="/"><span>Harbor</span></a>'
    . '<ul class="navlinks"><li><a href="/a/">A</a></li><li><a href="/b/">B</a></li></ul></nav>'
));
$assert(
    'pass' === $buttonBrandParity['status'] && 0 === $buttonBrandParity['findings'],
    'brand that converts to a button: semantic parity still reads the carrier as faithful',
    json_encode($buttonBrandParity)
);
$assert(
    3 === $buttonBrandParity['blocks'] && $buttonBrandParity['source'] === $buttonBrandParity['blocks'],
    'brand that converts to a button: its anchor is counted once, not once per markup level',
    json_encode($buttonBrandParity)
);

// -- Control: a menu with no branding anchor keeps today's single navigation block.
$plainMenu = $transform(
    '<style>.main-nav{display:flex;gap:1rem}</style>'
    . '<nav class="main-nav" aria-label="Primary"><ul><li><a href="/">Home</a></li><li><a href="/about/">About</a></li></ul></nav>'
);
$plainBlocks = is_array($plainMenu['blocks'] ?? null) ? $plainMenu['blocks'] : array();

$assert(
    1 === count($plainBlocks) && 'core/navigation' === (string) ($plainBlocks[0]['blockName'] ?? ''),
    'no branding anchor control: the promoted navigation is not wrapped in a carrier group',
    json_encode(array_map(static fn (array $block): string => (string) ($block['blockName'] ?? ''), $plainBlocks))
);
$assert(
    2 === count($findBlocks($plainBlocks, 'core/navigation-link')),
    'no branding anchor control: both menu anchors remain navigation links',
    (string) count($findBlocks($plainBlocks, 'core/navigation-link'))
);

// -- A brand cue is a token an author named the element with, not a word that
// happens to appear in prose written for a screen reader or a tooltip. Reading
// `aria-label` and `title` as cues hoisted real menu items out of their menu:
// "Brand new products" and "Download our logo" are sentences, not brand claims.
$prose = static function (string $attribute, string $value) use ($transform, $findBlocks): array {
    $result = $transform(
        '<style>nav{display:flex;gap:20px}'
        . '.navlinks{list-style:none;display:flex;gap:16px;margin:0;padding:0}</style>'
        . '<nav aria-label="Primary"><a href="/new/" ' . $attribute . '="' . $value . '">New</a>'
        . '<ul class="navlinks"><li><a href="/a/">A</a></li><li><a href="/b/">B</a></li></ul></nav>'
    );
    $blocks = is_array($result['blocks'] ?? null) ? $result['blocks'] : array();
    $carriers = array_values(array_filter(
        $findBlocks($blocks, 'core/group'),
        static fn (array $block): bool => 'nav' === (string) ($block['attrs']['tagName'] ?? '')
    ));

    // A carrier group holds a core/navigation beside the hoisted block. A nav
    // group with NO core/navigation inside it is the deferral guard's generic
    // conversion instead, which is a different path and must not be read as a
    // hoist.
    $navigations = $findBlocks($blocks, 'core/navigation');

    return array(
        'carriers' => array() === $navigations ? 0 : count($carriers),
        'deferred' => array() !== $carriers && array() === $navigations,
        'links' => count($findBlocks($blocks, 'core/navigation-link')),
    );
};

foreach ( array( 'aria-label' => 'Brand new products', 'title' => 'Download our logo' ) as $attribute => $value ) {
    $prosed = $prose($attribute, $value);
    $assert(
        0 === $prosed['carriers'] && 3 === $prosed['links'],
        'brand vocabulary inside ' . $attribute . ' prose does not hoist a menu item out of the menu',
        json_encode($prosed)
    );
}

// The token attributes still carry a cue, and `rel` is one of them. A recognised
// cue reaches `hasDirectBrandingAnchorBesideListNavigation()` first, which defers
// the whole container — the pre-existing path for an allowlisted brand, not the
// carrier. What matters here is that the cue is SEEN, so the anchor is never
// absorbed as a menu item.
// The carrier is now offered the container before the deferral guard, so a cued
// brand takes the SAME path as an uncued one: hoisted into a carrier beside a
// real core/navigation. The property this pins is unchanged and is the one that
// matters — the cue is seen and the anchor is never absorbed as a menu item. It
// shows up as two links from a two-anchor list, not three.
$relCue = $prose('rel', 'home-link');
$assert(
    1 === $relCue['carriers'] && false === $relCue['deferred'] && 2 === $relCue['links'],
    'a brand cue authored in rel is hoisted into the carrier rather than absorbed as a menu item',
    json_encode($relCue)
);

// `rel="home"` is NOT in the vocabulary — it carries `home-link` and `home-logo`,
// not a bare `home` — so this anchor stays an ordinary menu item.
$bareRel = $prose('rel', 'home');
$assert(
    0 === $bareRel['carriers'] && false === $bareRel['deferred'] && 3 === $bareRel['links'],
    'rel="home" is not in the brand vocabulary, so it leaves the anchor a menu item',
    json_encode($bareRel)
);

if ( $failures > 0 ) {
    fwrite(STDERR, "Navigation brand anchor hoist contract: {$failures} failed, {$passes} passed\n");
    exit(1);
}

fwrite(STDOUT, "Navigation brand anchor hoist contract passed: {$passes} assertions\n");
