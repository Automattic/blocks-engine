<?php
declare(strict_types=1);

/**
 * A labelless hash-anchor whose accessible name is "Menu" is a hamburger
 * control. Site builders emit `<a href="#" aria-label="Menu">` without
 * role="button" or aria-controls. The panel is often a CSS-hidden wrapper
 * around the nav, not a hidden <nav> itself.
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

$failures = 0;
$passes = 0;
$assert = static function (bool $ok, string $message, string $detail = '') use (&$failures, &$passes): void {
    if ( $ok ) {
        ++$passes;
        return;
    }
    ++$failures;
    fwrite(STDERR, 'FAIL: ' . $message . ( '' !== $detail ? ' - ' . $detail : '' ) . PHP_EOL);
};

$transform = static fn (string $html): array => ( new HtmlTransformer() )->transform($html, array())->toArray();
$markup = static fn (array $r): string => (string) ($r['serialized_blocks'] ?? '');

$weeblyCss = '.hamburger{display:none;padding:0 20px;width:100px;border-right:1px solid rgba(255,255,255,0.15);box-sizing:border-box}'
    . '.hamburger span{display:block;color:#fff;text-align:center}'
    . '.hamburger span:after{display:block;color:#fff;font-weight:bold;font-size:14px;content:"MENU"}';
$weebly = $transform(
    '<style>' . $weeblyCss . '</style>'
    . '<header><a class="hamburger" href="#" aria-label="Menu"><span></span></a>'
    . '<div class="nav-wrap" style="display:none"><nav><ul>'
    . '<li><a href="/">Home</a></li><li><a href="/about">About</a></li>'
    . '<li><a href="/research">Research</a></li>'
    . '</ul></nav></div></header>'
);
$weeblyMarkup = $markup($weebly);
$weeblyAssets = implode("\n", array_map(
    static fn (array $asset): string => (string) ($asset['content'] ?? ''),
    is_array($weebly['assets'] ?? null) ? $weebly['assets'] : array()
));

$assert(
    str_contains($weeblyMarkup, '"overlayMenu":"always"'),
    'a hash-anchor Menu control promotes a viewport-forced native overlay',
    $weeblyMarkup
);
$assert(
    str_contains($weeblyMarkup, '"label":"Home"')
        && str_contains($weeblyMarkup, '"label":"About"')
        && str_contains($weeblyMarkup, '"label":"Research"'),
    'all source destinations survive on the promoted navigation',
    $weeblyMarkup
);
$assert(
    str_contains($weeblyMarkup, 'blocks-engine-native-responsive-navigation'),
    'the promoted navigation is the native responsive overlay host',
    $weeblyMarkup
);
$assert(
    str_contains($weeblyMarkup, 'blocks-engine-native-navigation-toggle-'),
    'source toggle geometry is marked on the native overlay host',
    $weeblyMarkup
);
$assert(
    str_contains($weeblyAssets, 'responsive-container-open::after') && ( str_contains($weeblyAssets, 'content:"MENU"') || str_contains($weeblyAssets, 'content:MENU') ),
    'the source MENU generated-content label is projected onto the open control',
    $weeblyAssets
);
$assert(
    str_contains($weeblyAssets, 'width:100px') && str_contains($weeblyAssets, 'responsive-container-open'),
    'the source 100px toggle width is projected onto the open control at every viewport',
    $weeblyAssets
);
$assert(
    ! str_contains($weeblyAssets, '@media(max-width:599px){.wp-block-navigation.blocks-engine-native-responsive-navigation.blocks-engine-native-navigation-toggle-'),
    'a viewport-forced overlay does not confine toggle presentation to the mobile breakpoint',
    $weeblyAssets
);
$assert(
    1 !== preg_match('/wp:navigation \{[^}]*\bhamburger\b/', $weeblyMarkup),
    'toggle class hamburger is not copied onto the navigation host',
    $weeblyMarkup
);
$assert(
    str_contains($weeblyAssets, 'is-menu-open') && str_contains($weeblyAssets, 'flex-direction:row'),
    'the open overlay is a horizontal dropdown bar, not a left drawer',
    $weeblyAssets
);
$assert(
    str_contains($weeblyAssets, 'html.has-modal-open') && str_contains($weeblyAssets, 'overflow:visible'),
    'opening the dropdown does not lock document scroll the way a modal overlay does',
    $weeblyAssets
);
$assert(
    str_contains($weeblyAssets, 'responsive-container-close{display:flex') && str_contains($weeblyAssets, 'opacity:0')
        && ( str_contains($weeblyAssets, 'top:calc(0px - 60px)') || str_contains($weeblyAssets, 'top:-60px') )
        && str_contains($weeblyAssets, 'is-menu-open') && str_contains($weeblyAssets, 'overflow:visible'),
    'a second click on MENU hits an invisible close control in the same box',
    $weeblyAssets
);

$sticky = $transform(
    '<style>' . $weeblyCss
    . '.header-wrap{background-color:#2B2B2B;height:60px}'
    . '.topbar{position:absolute;top:0;left:0;right:0;height:61px;background-color:transparent}'
    . '</style>'
    . '<header class="header-wrap"><div id="topBar" class="topbar"><a class="hamburger" href="#" aria-label="Menu"><span></span></a>'
    . '<div class="nav-wrap" style="display:none"><nav><ul>'
    . '<li><a href="/">Home</a></li><li><a href="/about">About</a></li>'
    . '</ul></nav></div></div></header>'
);
$stickyAssets = implode("\n", array_map(
    static fn (array $asset): string => (string) ($asset['content'] ?? ''),
    is_array($sticky['assets'] ?? null) ? $sticky['assets'] : array()
));
$assert(
    str_contains($stickyAssets, '#topBar{position:fixed') && ( str_contains($stickyAssets, 'background-color:#2B2B2B') || str_contains($stickyAssets, 'background-color:#2b2b2b') ),
    'an absolutely pinned header bar is projected as a fixed opaque bar',
    $stickyAssets
);

$links = '<li><a href="/">Home</a></li><li><a href="/about">About</a></li>';
$twoHidden = $transform(
    '<style>.nav-wrap{display:none}.w-navpane.mobile-nav{display:none}.hamburger span:after{content:"MENU"}</style>'
    . '<div class="header-wrap"><a class="hamburger" href="#" aria-label="Menu"><span></span></a></div>'
    . '<div class="nav-wrap"><nav><ul>' . $links . '</ul></nav></div>'
    . '<div class="w-navpane nav mobile-nav"><a class="hamburger" href="#" aria-label="Menu"><span></span></a><ul>' . $links . '</ul></div>'
);
$twoHiddenMarkup = $markup($twoHidden);
$assert(
    str_contains($twoHiddenMarkup, '"overlayMenu":"always"'),
    'equivalent hidden nav-wrap and mobile pane still promote a native overlay',
    $twoHiddenMarkup
);
$assert(
    ! str_contains($twoHiddenMarkup, '<a class="hamburger"'),
    'the leftover pane hamburger is not left as a dead hash-anchor over MENU',
    $twoHiddenMarkup
);

$dualDoc = $transform(
    '<style>.nav-wrap{display:none}.hamburger span:after{content:"MENU"}</style>'
    . '<div class="data-liberation-desktop-document"><div class="header-wrap"><a class="hamburger" href="#" aria-label="Menu"><span></span></a></div>'
    . '<div class="nav-wrap"><nav><ul>' . $links . '</ul></nav></div></div>'
    . '<div class="data-liberation-mobile-document"><div class="header-wrap"><a class="hamburger" href="#" aria-label="Menu"><span></span></a></div>'
    . '<div class="nav-wrap"><nav><ul>' . $links . '</ul></nav></div></div>'
);
$dualDocMarkup = $markup($dualDoc);
$assert(
    str_contains($dualDocMarkup, '"overlayMenu":"always"'),
    'duplicate captured documents with the same hidden menu still promote a native overlay',
    $dualDocMarkup
);
$assert(
    ! str_contains($dualDocMarkup, '<a class="hamburger"'),
    'duplicate-document hash-anchor hamburgers are not left covering the overlay toggle',
    $dualDocMarkup
);

$clipped = $transform(
    '<style>.nav-wrap{display:block;max-height:0;overflow:hidden}.nav-wrap a{font-size:14px;font-weight:700;text-transform:uppercase;color:#444444}.hamburger span:after{content:"MENU"}</style>'
    . '<div class="header-wrap"><a class="hamburger" href="#" aria-label="Menu"><span></span></a></div>'
    . '<div class="nav-wrap"><nav><ul>' . $links . '</ul></nav></div>'
);
$clippedMarkup = $markup($clipped);
$clippedAssets = implode("\n", array_map(
    static fn (array $asset): string => (string) ($asset['content'] ?? ''),
    is_array($clipped['assets'] ?? null) ? $clipped['assets'] : array()
));
$assert(
    str_contains($clippedMarkup, '"overlayMenu":"always"')
        && 1 === substr_count($clippedMarkup, '<!-- wp:navigation '),
    'a max-height:0 nav-wrap is the overlay panel, not a second always-visible menu',
    $clippedMarkup
);
$assert(
    str_contains($clippedAssets, 'text-transform:uppercase') && str_contains($clippedAssets, 'font-size:14px'),
    'overlay items keep the source uppercase 14px menu labels',
    $clippedAssets
);
$assert(
    str_contains($clippedAssets, ':has(.wp-block-navigation__responsive-container.is-menu-open)>') && str_contains($clippedAssets, 'background:#fff'),
    'an open overlay paints the MENU control onto the white dropdown',
    $clippedAssets
);

$visibleTwin = $transform(
    '<style>.mobile-nav{display:none}.hamburger span:after{content:"MENU"}</style>'
    . '<div class="header-wrap"><a class="hamburger" href="#" aria-label="Menu"><span></span></a></div>'
    . '<div class="nav-wrap"><div class="nav desktop-nav"><ul>' . $links . '</ul></div></div>'
    . '<div class="mobile-nav"><ul>' . $links . '</ul></div>'
);
$visibleTwinMarkup = $markup($visibleTwin);
$assert(
    str_contains($visibleTwinMarkup, '"overlayMenu":"always"')
        && ! str_contains($visibleTwinMarkup, '"overlayMenu":"never"')
        && 1 === substr_count($visibleTwinMarkup, '<!-- wp:navigation '),
    'a visible desktop twin of the overlay menu is not left as a second always-on bar',
    $visibleTwinMarkup
);

$realLink = $transform(
    '<header><a href="/about" aria-label="Menu">About</a><nav><ul><li><a href="/">Home</a></li></ul></nav></header>'
);
$assert(
    ! str_contains($markup($realLink), 'blocks-engine-native-responsive-navigation'),
    'a real destination labelled Menu is not treated as a hamburger',
    $markup($realLink)
);

if ( 0 < $failures ) {
    fwrite(STDERR, "hash-anchor menu toggle FAILED: {$passes} passed, {$failures} failed\n");
    exit(1);
}
echo "hash-anchor menu toggle passed: {$passes} assertions\n";
