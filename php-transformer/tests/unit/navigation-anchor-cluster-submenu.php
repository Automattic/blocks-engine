<?php
declare(strict_types=1);

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

$markup = static function (string $html): string {
    return (string) ( ( new HtmlTransformer() )->transform($html)->toArray()['serialized_blocks'] ?? '' );
};

// An item that nests its children in a plain wrapper, with no list semantics
// and no dropdown-style class name, is still a parent with children.
$nested = $markup(
    '<nav class="site-menu"><div class="entry"><a href="/shop">Shop</a><div class="children"><a href="/shop/new">New</a><a href="/shop/sale">Sale</a></div></div>'
    . '<div class="entry"><a href="/about">About</a></div></nav>'
);
$assert(1 === substr_count($nested, '<!-- wp:navigation '), 'nested plain-wrapper menu emits one core/navigation', $nested);
$assert(1 === substr_count($nested, '<!-- wp:navigation-submenu '), 'the parent item becomes one core/navigation-submenu', $nested);
$assert(str_contains($nested, '"label":"Shop","url":"/shop"'), 'the submenu keeps the parent label and destination', $nested);
$assert(str_contains($nested, '"label":"New","url":"/shop/new"') && str_contains($nested, '"label":"Sale","url":"/shop/sale"'), 'every child link is preserved as a navigation-link', $nested);
$assert(str_contains($nested, '"label":"About","url":"/about"'), 'a sibling without children stays a plain navigation-link', $nested);
$assert(! str_contains($nested, '<!-- wp:paragraph') && ! str_contains($nested, '<!-- wp:group'), 'no flattened paragraph or group fallback remains', $nested);

// Fragment destinations are ordinary menu destinations.
$fragments = $markup('<nav class="site-menu"><div><a href="#one">One</a><div><a href="#one-a">One A</a></div></div><div><a href="#two">Two</a></div></nav>');
$assert(str_contains($fragments, '<!-- wp:navigation-submenu {"label":"One","url":"#one"') && str_contains($fragments, '"label":"One A","url":"#one-a"'), 'fragment destinations nest as a submenu', $fragments);

// A captured dialog panel opened by an `aria-haspopup="menu"` control is a menu
// panel: its nested items become one native navigation without a second overlay.
$panel = $markup(
    '<header class="top"><nav class="bar"><a href="#top" class="brand"><span class="mark">Acme</span></a>'
    . '<div class="wide"><a href="#one">One</a><div class="rel"><button class="drop">Shop</button></div><a href="#three">Three</a></div>'
    . '<div class="end"><a href="#join" class="cta">Join</a>'
    . '<button aria-label="Toggle menu" aria-haspopup="menu" aria-controls="p0" aria-expanded="false" data-dla-dialog-trigger="p0">Menu</button>'
    . '<div class="dla-dialog dla-dropdown" role="dialog" aria-modal="true" hidden id="p0" data-dla-dialog-panel="p0"><div class="panel">'
    . '<div><a href="#one">One</a></div><div><a href="#shop">Shop</a><div class="indent"><a href="#new">New</a><a href="#sale">Sale</a></div></div>'
    . '<a href="#join">Join</a></div></div></div></nav></header>'
);
$assert(str_contains($panel, '<!-- wp:navigation-submenu {"label":"Shop","url":"#shop"'), 'a menu-declared dialog panel converts its parent item to a submenu', $panel);
$assert(2 === preg_match_all('/<!-- wp:navigation-link \{[^}]*"label":"(?:New|Sale)"/', $panel), 'the dialog panel keeps its child links as navigation links', $panel);
$assert(str_contains($panel, '"overlayMenu":"never"'), 'the panel navigation does not nest a second responsive overlay', $panel);

// The same wrapper outside navigation context is ordinary content, not a menu.
$card = $markup('<section class="cards"><div class="card"><a href="/x">Title</a><div class="extras"><a href="/x/a">A</a><a href="/x/b">B</a></div></div></section>');
$assert(! str_contains($card, '<!-- wp:navigation'), 'a non-navigation card with a link cluster is not converted', $card);

if ( 0 < $failures ) {
    fwrite(STDERR, "Anchor-cluster submenu contract: {$failures} failed, {$passes} passed\n");
    exit(1);
}

echo "Anchor-cluster submenu contract passed: {$passes} assertions\n";
