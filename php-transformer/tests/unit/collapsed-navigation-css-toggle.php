<?php
declare(strict_types=1);

/**
 * When the source collapses its in-flow menu behind a CSS-drawn toggle below a
 * breakpoint, core/navigation must emit overlayMenu "mobile" and keep a native
 * open control. Hiding the links with no trigger leaves phone visitors stuck.
 *
 * Two source shapes share that contract:
 *   - a visible list plus a CSS-hidden duplicate whose identity names the
 *     collapsed surface (not a <nav> landmark)
 *   - a visible list beside a labelless <label> whose only child is the empty
 *     span CSS uses as a 3-bar glyph host
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

$transform = static fn (string $html): string => (string) (( new HtmlTransformer() )->transform($html, array())->toArray()['serialized_blocks'] ?? '');

$isReachableMobileMenu = static function (string $html, array $labels) use ($assert): void {
    $assert(str_contains($html, '"overlayMenu":"mobile"'), 'collapsed source navigation emits the native mobile overlay', $html);
    $assert(str_contains($html, 'blocks-engine-native-responsive-navigation'), 'native overlay marker is present so host repair CSS can keep the trigger visible', $html);
    $assert(! str_contains($html, '<!-- wp:button'), 'the source toggle is not emitted as a dead core/button', $html);
    foreach ( $labels as $label ) {
        $assert(str_contains($html, '"label":"' . $label . '"'), 'native overlay keeps the "' . $label . '" destination', $html);
    }
};

$duplicate = $transform(
    '<style>'
    . '.site-header .menu-toggle{display:none}'
    . '.inline-menu{display:table-cell}'
    . '.collapsed-menu{display:none}'
    . '@media screen and (max-width:992px){'
    . '.inline-menu{display:none}'
    . '.site-header .menu-toggle{display:table-cell}'
    . '.collapsed-menu{display:block;max-height:0}'
    . '.menu-toggle span,.menu-toggle span:before,.menu-toggle span:after{display:block;width:22px;height:2px;background:#fff;content:""}'
    . '}'
    . '</style>'
    . '<header class="site-header">'
    . '<a class="brand" href="/">Site</a>'
    . '<div class="nav inline-menu"><ul>'
    . '<li><a href="/">Home</a></li>'
    . '<li><a href="/blog">Blog</a></li>'
    . '<li><a href="/about">About</a></li>'
    . '<li><a href="/contact">Contact</a></li>'
    . '</ul></div>'
    . '<label class="menu-toggle"><span></span></label>'
    . '</header>'
    . '<div class="nav mobile-menu collapsed-menu">'
    . '<label class="menu-toggle"><span></span></label>'
    . '<ul>'
    . '<li><a href="/">Home</a></li>'
    . '<li><a href="/blog">Blog</a></li>'
    . '<li><a href="/about">About</a></li>'
    . '<li><a href="/contact">Contact</a></li>'
    . '</ul></div>'
);
$isReachableMobileMenu($duplicate, array( 'Home', 'Blog', 'About', 'Contact' ));
$assert(1 === substr_count($duplicate, '<!-- wp:navigation '), 'the collapsed duplicate does not emit a second navigation', $duplicate);

$cssToggle = $transform(
    '<style>'
    . '.menu-toggle{display:none}'
    . '@media screen and (max-width:992px){'
    . '.primary-menu{display:none}'
    . '.menu-toggle{display:block}'
    . '.menu-toggle span,.menu-toggle span:before,.menu-toggle span:after{display:block;width:22px;height:2px;background:#fff;content:""}'
    . '}'
    . '</style>'
    . '<header>'
    . '<div class="nav primary-menu"><ul>'
    . '<li><a href="/">Home</a></li>'
    . '<li><a href="/about">About</a></li>'
    . '<li><a href="/contact">Contact</a></li>'
    . '</ul></div>'
    . '<label class="menu-toggle"><span></span></label>'
    . '</header>'
);
$isReachableMobileMenu($cssToggle, array( 'Home', 'About', 'Contact' ));

$noToggle = $transform(
    '<style>.menu{display:block}@media(max-width:700px){.menu{display:none}}</style>'
    . '<nav class="menu"><ul><li><a href="/">Home</a></li><li><a href="/about">About</a></li></ul></nav>'
);
$assert(
    ! str_contains($noToggle, '"overlayMenu":"mobile"') && ! str_contains($noToggle, 'blocks-engine-native-responsive-navigation'),
    'a menu that merely hides below a breakpoint without a toggle does not invent an overlay',
    $noToggle
);

if ( 0 < $failures ) {
    fwrite(STDERR, "collapsed navigation css toggle FAILED: {$passes} passed, {$failures} failed\n");
    exit(1);
}
echo "collapsed navigation css toggle passed: {$passes} assertions\n";
