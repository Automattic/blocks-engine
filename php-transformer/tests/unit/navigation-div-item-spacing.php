<?php
declare(strict_types=1);

/**
 * A menu of inline-block `nav>div` items keeps the space between its items.
 *
 * Squarespace (Bedford) renders its header menu as `<nav><div class="collection">
 * <a>…</a></div> <div …>` with `nav>div{display:inline-block}` in a
 * right-aligned bar. The whitespace between the divs draws one space of the
 * menu's font per gap. core/navigation renders each div as an item with
 * nothing between them, so every item but the last moved one space further
 * towards the end of the row (20px for "Sign In" across five gaps). The space
 * carrier existed only for authored `li` item selectors; a `div`-typed item
 * selector, which here also matches the mobile copy of the menu that never
 * becomes a navigation, got none.
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

$items = "\n  <div class=\"collection\"><a href=\"/\">Home</a></div>\n  <div class=\"collection\"><a href=\"/about/\">About</a></div>\n  <div class=\"collection\"><a href=\"/news/\">News</a></div>\n";
$html = '<style>'
    . '#headerNav{text-align:right;white-space:nowrap}#mobileNav{display:none}'
    . '.nav-wrapper nav>div{display:inline-block;vertical-align:middle;margin:0}'
    . '.nav-wrapper nav>div>a{display:block;padding:.75em 1em;font-size:13px;letter-spacing:2px}'
    . '</style>'
    // The mobile copy keeps an empty account slot, so it stays a group.
    . '<div id="mobileNav" class="nav-wrapper"><nav id="mobileNavigation">' . "\n  <div class=\"user-account-link\"></div>" . $items . '</nav></div>'
    . '<header><div id="headerNav" class="nav-wrapper"><nav id="mainNavigation">' . $items . '</nav></div></header>';

$result = ( new HtmlTransformer() )->transform($html)->toArray();
$css = '';
foreach ( $result['assets'] ?? array() as $asset ) {
    if ( 'css' === ( $asset['kind'] ?? '' ) ) {
        $css .= (string) ( $asset['content'] ?? '' ) . "\n";
    }
}

preg_match('/blocks-engine-navigation-instance-[a-f0-9]{12}-[0-9]+/', (string) ( $result['serialized_blocks'] ?? '' ), $instance);
if ( array() === $instance ) {
    fwrite(STDERR, "FAIL: the header menu must render as a core/navigation instance\n");
    exit(1);
}
$carrier = preg_quote('.wp-block-navigation.' . $instance[0] . ' .wp-block-navigation__container>.wp-block-navigation-item:not(:last-child)', '/');
$failures = 0;
if ( 1 !== preg_match('/' . $carrier . '[^{},]*::after\{content:"\\\\a0"\}/', $css) ) {
    fwrite(STDERR, "FAIL: the space between div menu items comes back after every item but the last\n");
    ++$failures;
}
if ( 1 !== preg_match('/' . $carrier . '[^{},]*\{display:inline-flex\}/', $css) ) {
    fwrite(STDERR, "FAIL: the spaced item is an inline flex row so the space stays beside its block link\n");
    ++$failures;
}
if ( $failures > 0 ) {
    exit(1);
}
echo "Navigation div item spacing passed\n";
