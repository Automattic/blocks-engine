<?php
declare(strict_types=1);

/**
 * A menu toggle whose only visible content is the hamburger drawn as a
 * character — U+2630 TRIGRAM FOR HEAVEN (☰), or the U+2261 IDENTICAL TO (≡)
 * stand-in — is an icon-only control, the same as CSS-drawn bars or an SVG.
 * Reading the glyph as a visible text label kept the toggle as an
 * always-visible core/button and left the navigation with overlayMenu
 * "never", so the phone layout had no reachable menu.
 *
 * The change stays conservative: a word label, a glyph beside a word, a
 * glyph with no associated navigation, a glyph inside a form, and other
 * symbols (kebab, ellipsis, plus) still convert as ordinary buttons.
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

// Desktop shows the link row and hides the toggle; below the breakpoint the
// toggle shows and the row collapses until a script adds `.open`.
$css = '.bar{display:flex;align-items:center;position:relative}'
    . '.bar button{display:none;border:0;background:none;font-size:27px;color:#fff}'
    . '.bar nav{position:absolute;right:0;top:50%;transform:translateY(-50%);display:flex;align-items:center;gap:22px}'
    . '@media(max-width:1300px){'
    . '.bar button{display:flex;position:absolute;right:0;top:50%;transform:translateY(-50%);padding:8px}'
    . '.bar nav{display:none;position:absolute;left:0;right:0;top:100%;transform:none;flex-direction:column;background:#123}'
    . '.bar nav.open{display:flex}'
    . '}';
$links = array( 'Home', 'About', 'Work', 'News', 'Contact', 'FR' );
$nav = '<nav><a href="#home">Home</a><a href="#about">About</a><a href="#work">Work</a><a href="#news">News</a><a href="#contact">Contact</a><a href="/fr.html">FR</a></nav>';

$transform = static fn (string $body, string $styles = ''): string => (string) (( new HtmlTransformer() )->transform(
    '<style>' . $css . $styles . '</style>' . $body . '<main><section id="home"><h1>Hello</h1></section></main>',
    array()
)->toArray()['serialized_blocks'] ?? '');

$header = static fn (string $controls): string => '<header><div class="bar"><a class="brand" href="#home">Brand</a>' . $controls . '</div></header>';

$isNativeResponsiveMenu = static function (string $html, string $label) use ($assert, $links): void {
    $assert(str_contains($html, '"overlayMenu":"mobile"'), $label . ': navigation emits the native mobile overlay', $html);
    $assert(str_contains($html, 'blocks-engine-native-responsive-navigation'), $label . ': native overlay marker is present', $html);
    $assert(! str_contains($html, '<!-- wp:button'), $label . ': the glyph toggle is not emitted as a dead core/button', $html);
    $assert(! str_contains($html, '☰') && ! str_contains($html, '≡'), $label . ': the glyph is not emitted as content', $html);
    $assert(1 === substr_count($html, '<!-- wp:navigation '), $label . ': exactly one navigation is emitted', $html);
    foreach ( $links as $link ) {
        $assert(str_contains($html, '"label":"' . $link . '"'), $label . ': keeps the "' . $link . '" destination', $html);
    }
};

$isPlainButton = static function (string $html, string $label, string $buttonText) use ($assert): void {
    $assert(str_contains($html, '"overlayMenu":"never"'), $label . ': navigation keeps overlayMenu never', $html);
    $assert(str_contains($html, '<!-- wp:button'), $label . ': the control stays a core/button', $html);
    $assert(str_contains($html, $buttonText), $label . ': the button keeps its content "' . $buttonText . '"', $html);
};

// --- Recognized: glyph-only toggles beside a visible desktop navigation. ---

$isNativeResponsiveMenu($transform($header('<button>☰</button>' . $nav)), 'bare glyph button');
$isNativeResponsiveMenu($transform($header('<button>&#9776;</button>' . $nav)), 'glyph as an HTML entity');
$isNativeResponsiveMenu($transform($header('<button><span>☰</span></button>' . $nav)), 'glyph inside a wrapper span');
$isNativeResponsiveMenu($transform($header('<button> ☰&nbsp;</button>' . $nav)), 'glyph padded with whitespace');
$isNativeResponsiveMenu($transform($header('<button>≡</button>' . $nav)), 'identical-to glyph stand-in');
$isNativeResponsiveMenu($transform($header('<button>☰<span class="sr-only">Open menu</span></button>' . $nav), '.sr-only{position:absolute;width:1px;height:1px;overflow:hidden;clip:rect(0,0,0,0)}'), 'glyph plus screen-reader-only label');
$isNativeResponsiveMenu($transform($header('<button aria-controls="menu" aria-expanded="false">☰</button><nav id="menu">' . substr($nav, 5))), 'glyph with ARIA toggle wiring');

// An unclosed inline element in the source leaves the browser (and the
// capture) wrapping the toggle and its menu in stray inline wrappers.
$isNativeResponsiveMenu($transform($header('<b><b><button>☰</button>' . $nav . '</b></b>')), 'glyph and menu wrapped in stray inline elements');

// --- Not recognized: the control is a labelled button or no menu toggle. ---

$isPlainButton($transform($header('<button>Menu</button>' . $nav)), 'word label', '>Menu<');
$isPlainButton($transform($header('<button>☰ Menu</button>' . $nav)), 'glyph beside a word', 'Menu');
$isPlainButton($transform($header('<button>⋮</button>' . $nav)), 'kebab glyph', '⋮');
$isPlainButton($transform($header('<button>…</button>' . $nav)), 'ellipsis glyph', '…');
$isPlainButton($transform($header('<button>+</button>' . $nav)), 'plus sign', '+');
$isPlainButton($transform($header('<button>☰☰</button>' . $nav)), 'repeated glyphs', '☰☰');

$noNavigation = $transform($header('<button>☰</button>'));
$assert(str_contains($noNavigation, '<!-- wp:button') && str_contains($noNavigation, '☰'), 'a glyph button with no associated navigation stays a core/button', $noNavigation);

$inForm = $transform($header($nav . '<form action="/search"><input type="search" name="q"><button>☰</button></form>'));
$assert(str_contains($inForm, '"overlayMenu":"never"'), 'a glyph control inside a form is not the menu toggle', $inForm);
$assert(str_contains($inForm, '☰'), 'the form control keeps its glyph', $inForm);

if ( 0 < $failures ) {
    fwrite(STDERR, "glyph menu toggle FAILED: {$passes} passed, {$failures} failed\n");
    exit(1);
}
echo "glyph menu toggle passed: {$passes} assertions\n";
