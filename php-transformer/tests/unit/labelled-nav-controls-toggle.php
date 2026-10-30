<?php
declare(strict_types=1);

/**
 * A toggle with a visible word label ("Menu") whose `aria-controls` names the
 * source `<nav>` itself is that navigation's toggle. Below the breakpoint the
 * source hides the nav and shows the toggle; converted as a plain button, the
 * phone layout had a "Menu" button that opened nothing and no reachable links.
 * The navigation owns its native mobile overlay instead.
 *
 * A word-labelled button that controls a panel without a navigation, or names
 * no panel at all, still converts as an ordinary button.
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

$failures = 0;
$assert = static function (bool $ok, string $message, string $detail = '') use (&$failures): void {
    if ( ! $ok ) {
        ++$failures;
        fwrite(STDERR, 'FAIL: ' . $message . ( '' !== $detail ? ' - ' . substr($detail, 0, 800) : '' ) . PHP_EOL);
    }
};

$css = 'header{display:flex;justify-content:space-between}header nav{display:flex;gap:28px}.menu-toggle{display:none}'
    . '@media(max-width:650px){.menu-toggle{display:block}header nav{display:none}.menu-toggle[aria-expanded=true]+nav{display:flex}}';
$nav = '<nav id="nav" aria-label="Main navigation"><a href="#services">Services</a><a href="#work">Work</a><a href="#contact">Contact</a></nav>';
$transform = static fn (string $header): string => (string) (( new HtmlTransformer() )->transform(
    '<style>' . $css . '</style><header><a class="brand" href="/">Brand</a>' . $header . '</header><main><h1 id="services">Services</h1></main>',
    array()
)->toArray()['serialized_blocks'] ?? '');

$html = $transform('<button class="menu-toggle" type="button" aria-expanded="false" aria-controls="nav">Menu</button>' . $nav);
$assert(str_contains($html, '"overlayMenu":"mobile"'), 'the navigation owns its native mobile overlay', $html);
$assert(! str_contains($html, '<!-- wp:button'), 'the labelled toggle is not emitted as a dead core/button', $html);
$assert(1 === substr_count($html, '<!-- wp:navigation '), 'exactly one navigation is emitted', $html);
foreach ( array( 'Services', 'Work', 'Contact' ) as $link ) {
    $assert(str_contains($html, '"label":"' . $link . '"'), 'keeps the "' . $link . '" destination', $html);
}

$panel = $transform('<button class="menu-toggle" type="button" aria-expanded="false" aria-controls="specials">Menu</button><div id="specials" hidden><p>Soup of the day</p></div>' . $nav);
$assert(str_contains($panel, '>Menu<') && str_contains($panel, '<!-- wp:button'), 'a word-labelled button controlling a non-navigation panel stays a button', $panel);

$plain = $transform('<button class="menu-toggle" type="button">Menu</button>' . $nav);
$assert(str_contains($plain, '>Menu<') && str_contains($plain, '<!-- wp:button'), 'a word-labelled button without aria-controls stays a button', $plain);

if ( $failures > 0 ) {
    fwrite(STDERR, "Labelled nav-controls toggle failed ({$failures})\n");
    exit(1);
}
fwrite(STDOUT, "Labelled nav-controls toggle passed\n");
