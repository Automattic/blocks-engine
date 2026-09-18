<?php
declare(strict_types=1);

/**
 * A menu toggle whose text label is visually hidden is an icon toggle, not a
 * labelled button. Site builders keep the word "Menu" in the markup for screen
 * readers and hide it with image-replacement CSS (a large negative
 * text-indent, often only inside the mobile media query where the toggle
 * shows), screen-reader-only clipping, a zero font-size, or hidden visibility,
 * then draw the bars with pseudo-elements. Treating that hidden text as a
 * visible label kept the toggle as an always-visible core/button and left the
 * navigation with overlayMenu "never", so the phone layout had no menu.
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

$markup = static fn (string $css, string $toggle): string => (string) (( new HtmlTransformer() )->transform(
    '<style>' . $css . '</style>'
    . '<header><div class="menu-wrap">' . $toggle
    . '<nav class="menu-body"><ul>'
    . '<li><a href="/">Home</a></li><li><a href="/about">About</a></li><li><a href="/contact">Contact</a></li>'
    . '</ul></nav></div></header><main><p>Body</p></main>',
    array()
)->toArray()['serialized_blocks'] ?? '');

$isHamburger = static fn (string $html): bool => str_contains($html, '"overlayMenu":"mobile"')
    && str_contains($html, 'blocks-engine-native-responsive-navigation')
    && ! str_contains($html, 'wp:button');

// BaseKit: the label is pushed off-screen only inside the mobile media query,
// where the toggle shows; the desktop view hides the whole toggle instead.
$mediaIndent = $markup(
    '.menu-toggle{display:none}'
    . '@media only screen and (max-width:769px){'
    . '.menu-toggle{display:block;overflow:hidden;width:100%;height:45px;text-indent:-9999px;line-height:0;border:0}'
    . '.menu-toggle:before{content:"";position:absolute;width:26px;height:3px;background:#fff;box-shadow:0 6px 0 #fff}'
    . '.menu-body{display:none}}',
    '<button class="menu-toggle"><span>Menú</span></button>'
);
$assert($isHamburger($mediaIndent), 'an off-screen text-indent label inside a media query is a hamburger toggle', $mediaIndent);
$assert(! str_contains($mediaIndent, 'Menú'), 'the hidden label is not emitted as a visible button', $mediaIndent);

$srOnly = $markup(
    '.sr-only{position:absolute;width:1px;height:1px;padding:0;margin:-1px;overflow:hidden;clip:rect(0,0,0,0);white-space:nowrap;border:0}',
    '<button class="menu-toggle"><i class="icon-bars"></i><span class="sr-only">Menu</span></button>'
);
$assert($isHamburger($srOnly), 'screen-reader-only clipped label text is not a visible label', $srOnly);

$zeroFont = $markup(
    '.menu-toggle{font-size:0}',
    '<button class="menu-toggle">Menu</button>'
);
$assert($isHamburger($zeroFont), 'a zero font-size on the control hides its label', $zeroFont);

$invisible = $markup(
    '.menu-toggle .label{visibility:hidden}',
    '<button class="menu-toggle"><span class="label">Navigation</span></button>'
);
$assert($isHamburger($invisible), 'a visibility:hidden label descendant is not a visible label', $invisible);

$visible = $markup(
    '.menu-toggle{text-indent:-2px}',
    '<button class="menu-toggle"><span>Menu</span></button>'
);
$assert(
    ! $isHamburger($visible) && str_contains($visible, 'wp:button') && str_contains($visible, 'Menu'),
    'a small text-indent keeps a visibly labelled button',
    $visible
);

$otherControl = $markup(
    '.search-toggle{text-indent:-9999px}',
    '<button class="search-toggle"><span>Search</span></button>'
);
$assert(
    ! str_contains($otherControl, '"overlayMenu":"mobile"'),
    'a hidden-label control that does not name a menu is not a hamburger',
    $otherControl
);

if ( 0 < $failures ) {
    fwrite(STDERR, "visually hidden menu toggle label FAILED: {$passes} passed, {$failures} failed\n");
    exit(1);
}
echo "visually hidden menu toggle label passed: {$passes} assertions\n";
