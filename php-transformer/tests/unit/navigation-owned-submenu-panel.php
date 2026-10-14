<?php
declare(strict_types=1);

/**
 * An item's own link owns the link panel beside it.
 *
 * #2743 stopped reading peer item wrappers as a submenu and stopped carrying
 * list-level wrapper classes onto items. Two submenu shapes then fell through:
 * - a Squarespace desktop folder titled by a link,
 *   `div.item > a.title + div.panel > div.entry > a`: the panel's entries
 *   are wrapped one link per wrapper, which the anchor-only cluster reading
 *   did not accept, so they came out as top-level links in the bar and the
 *   title lost the item's classes;
 * - a parent link inside a label wrapper,
 *   `div.entry > span > a + div > a`: the guard wanted the link as the item's
 *   direct child, so the whole menu was dropped.
 * Peers stay peers: a wrapper of the same tag beside the label is another
 * item, not a submenu.
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

$items = static function (string $html): array {
    $serialized = (string) (( new HtmlTransformer() )->transform($html, array())->toArray()['serialized_blocks'] ?? '');
    preg_match_all('/<!-- (\/?)wp:navigation-(link|submenu)(?: (\{.*?\}))?/', $serialized, $matches, PREG_SET_ORDER);
    $out = array();
    foreach ( $matches as $match ) {
        if ( '/' === $match[1] ) {
            $out[] = 'end';
            continue;
        }
        $attrs = json_decode($match[3] ?? '{}', true);
        $out[] = $match[2] . ':' . strip_tags((string) ($attrs['label'] ?? '')) . '|' . (string) ($attrs['className'] ?? '');
    }

    return $out;
};
$labels = static fn (array $items): array => array_map(static fn (string $item): string => strtok($item, '|'), $items);

$entry = static fn (string $label, string $href): string => '<div class="nav-folder-item"><a href="' . $href . '"><span>' . $label . '</span></a></div>';
$folder = $items(
    '<header><nav class="nav-list">'
    . '<div class="nav-item"><a href="/">Home</a></div>'
    . '<div class="nav-item nav-item--folder"><a class="nav-folder-title" href="/services">Services</a>'
    . '<div class="nav-folder-content">' . $entry('Alpha', '/a') . $entry('Beta', '/b') . '</div></div>'
    . '<div class="nav-item"><a href="/contact">Contact</a></div>'
    . '</nav></header>'
);
$assert(
    array( 'link:Home', 'submenu:Services', 'link:Alpha', 'link:Beta', 'end', 'link:Contact' ) === $labels($folder),
    'a folder titled by a link keeps its wrapped entries as its submenu',
    json_encode($labels($folder))
);
$services = (string) ($folder[1] ?? '');
$assert(str_contains($services, 'nav-item') && str_contains($services, 'nav-item--folder'), 'the folder title keeps the item classes', $services);

$span = $items(
    '<nav class="site-menu"><div class="entry"><span><a href="/shop">Shop</a></span><div class="children"><a href="/shop/new">New</a></div></div>'
    . '<div class="entry"><a href="/about">About</a></div></nav>'
);
$assert(
    array( 'submenu:Shop', 'link:New', 'end', 'link:About' ) === $labels($span),
    'a parent link in a label wrapper owns the cluster beside it',
    json_encode($labels($span))
);

$peers = $items(
    '<header><div class="menu-panel"><div class="menu-nav"><nav class="menu-nav-list"><div class="nav-wrapper">'
    . '<div class="nav-item"><a href="/">Home</a></div><div class="nav-item"><a href="/a">A</a></div><div class="nav-item"><a href="/b">B</a></div>'
    . '</div></nav></div></div></header>'
);
$assert(! in_array('submenu:Home', $labels($peers), true), 'a wrapper of the same tag beside the label stays a peer', json_encode($labels($peers)));

if ( 0 < $failures ) {
    fwrite(STDERR, "Navigation owned submenu panel: {$failures} failed, {$passes} passed\n");
    exit(1);
}

echo "Navigation owned submenu panel passed: {$passes} assertions\n";
