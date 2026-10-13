<?php
declare(strict_types=1);

/**
 * A list-less menu of wrapped items stays one flat list of peers.
 *
 * Squarespace renders its phone menu as panels ("folders") of item wrappers:
 * `nav > div.folder > div.content > div.item > a`. Two readings broke it:
 * - each `div.item` after the first was read as the first item's submenu
 *   cluster, so the whole panel became one "Home" submenu with every other
 *   link hidden inside it;
 * - the classes of the panel and its content wrapper, which hold every item,
 *   were carried onto each item as if core had collapsed them between item
 *   and link. The panel's off-canvas rule (`translateX(100%)`) then moved
 *   every item off screen.
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

$item = static fn (string $label, string $href): string => '<div class="nav-item"><a href="' . $href . '"><div class="nav-item-content">' . $label . '</div></a></div>';
$serialized = (string) (( new HtmlTransformer() )->transform(
    '<style>.nav-folder{position:absolute;top:0;left:0;width:100%;transform:translateX(100%)}.nav-folder--active{transform:translateX(0)}'
    . '.nav-folder-content{display:flex;flex-direction:column}</style>'
    . '<header><div class="menu"><nav class="menu-list"><div class="nav-folder nav-folder--active"><div class="nav-folder-content"><div class="nav-wrapper">'
    . $item('Home', '/') . $item('Services', '/services') . $item('Careers', '/careers') . $item('Contact', '/contact')
    . '</div></div></div></nav></div></header>',
    array()
)->toArray()['serialized_blocks'] ?? '');

preg_match_all('/<!-- wp:navigation-(link|submenu) (\{.*?\}) (?:\/)?-->/', $serialized, $matches, PREG_SET_ORDER);
$labels = array();
$classes = array();
foreach ( $matches as $match ) {
    $attrs = json_decode($match[2], true);
    $labels[] = $match[1] . ':' . ($attrs['label'] ?? '');
    $classes[] = (string) ($attrs['className'] ?? '');
}

$assert(
    array( 'link:Home', 'link:Services', 'link:Careers', 'link:Contact' ) === $labels,
    'every wrapped item is a peer link, with no submenu',
    json_encode($labels)
);
$assert(
    array() !== $classes && array() === array_filter($classes, static fn (string $class): bool => 1 === preg_match('/(?:^|\s)(?:nav-folder|nav-folder--active|nav-folder-content|nav-wrapper)(?:\s|$)/', $class)),
    'classes of the wrappers that hold every item stay off the items',
    json_encode($classes)
);
$assert(
    array() !== $classes && array() === array_filter($classes, static fn (string $class): bool => 1 !== preg_match('/(?:^|\s)nav-item(?:\s|$)/', $class)),
    'the per-item wrapper core collapses still carries its class',
    json_encode($classes)
);

if ( 0 < $failures ) {
    fwrite(STDERR, "Navigation peer item wrappers: {$failures} failed, {$passes} passed\n");
    exit(1);
}

echo "Navigation peer item wrappers passed: {$passes} assertions\n";
