<?php
declare(strict_types=1);

/**
 * A navigation landmark used as a listing keeps every row's text.
 *
 * Each row of `tests/fixtures/navigation-listing-row-text.html` is a flex
 * `<div>` holding an index, one linked title and a year:
 * `<div><span>1.</span><a>Title</a><span>2026</span></div>`. The rows were
 * read as menu items and became navigation links that hold only the anchor's
 * label, so the index and year were dropped and the row layout went with them.
 * An item that shows words or numbers outside its one anchor is not a menu
 * item, and the landmark falls through to ordinary block conversion.
 *
 * The controls pin what a menu item may still keep beside its anchor: a
 * glyph-only indicator or separator, icon-font text hidden from assistive
 * technology, screen-reader-only text, a toggle button and a submenu.
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

$failures = 0;
$assert = static function (bool $condition, string $message, string $detail = ''): void {
    global $failures;
    if ( $condition ) {
        return;
    }
    ++$failures;
    fwrite(STDERR, 'FAIL: ' . $message . ('' !== $detail ? ' - ' . $detail : '') . PHP_EOL);
};

$markup = static fn (string $html): string => (string) (( new HtmlTransformer() )->transform($html, array())->toArray()['serialized_blocks'] ?? '');

// -- The listing keeps every row's index, title and year.
$listing = $markup((string) file_get_contents(dirname(__DIR__) . '/fixtures/navigation-listing-row-text.html'));
foreach ( array( '1.', '2026', '2.', '2024', '3.', '2022', 'First entry title', 'Second entry, a quote', 'Third entry with a much longer title' ) as $text ) {
    $assert(str_contains($listing, $text), 'the listing keeps "' . $text . '"', substr($listing, 0, 300));
}
foreach ( array( '/first/', '/second/', '/third/' ) as $href ) {
    $assert(str_contains($listing, 'href="' . $href . '"'), 'the listing keeps the link to ' . $href);
}
$assert(! str_contains($listing, 'wp:navigation-link'), 'listing rows are not reduced to navigation links', substr($listing, 0, 300));
$assert(3 === substr_count($listing, 'class="wp-block-group row'), 'each row keeps its own block box for the row layout', substr($listing, 0, 300));

// -- Genuine menu items keep becoming navigation links.
$menu = $markup(
    '<nav aria-label="Primary"><ul>'
    . '<li><a href="/a/">Alpha</a><span class="caret">▾</span><ul><li><a href="/a/one/">One</a></li><li><a href="/a/two/">Two</a></li></ul></li>'
    . '<li><a href="/b/">Beta</a> <span aria-hidden="true">chevron_right</span></li>'
    . '<li><a href="/c/">Gamma</a><span class="sr-only">current page</span></li>'
    . '<li><a href="/d/">Delta</a><span class="sep">|</span></li>'
    . '<li><a href="/e/">Epsilon</a><button type="button" aria-expanded="false">Open</button></li>'
    . '</ul></nav>'
);
$assert(str_contains($menu, 'wp:navigation-submenu'), 'an item with a glyph indicator and submenu stays a navigation submenu', substr($menu, 0, 300));
foreach ( array( '/b/', '/c/', '/d/', '/e/' ) as $url ) {
    $assert(str_contains($menu, '"url":"' . str_replace('/', '\/', $url) . '"') || str_contains($menu, '"url":"' . $url . '"'), 'the menu item for ' . $url . ' stays a navigation link', substr($menu, 0, 400));
}

if ( 0 < $failures ) {
    exit(1);
}
echo "navigation-listing-row-text: ok\n";
