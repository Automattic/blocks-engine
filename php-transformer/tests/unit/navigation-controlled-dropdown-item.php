<?php
declare(strict_types=1);

/**
 * A button that names its sibling link panel with `aria-controls` is a
 * dropdown item.
 *
 * Squarespace's desktop menu writes a folder as `div.item > button
 * [aria-controls=p] + div#p > div > a ...`. Without this reading the folder's
 * own label was lost: before #2743 the first child link became the submenu
 * label, and after it the child links were flattened into the top row.
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

$items = static function (string $controls, string $panelId): array {
    $child = static fn (string $label, string $href): string => '<div class="folder-item"><a href="' . $href . '">' . $label . '</a></div>';
    $html = '<header><nav class="nav-list">'
        . '<div class="nav-item"><a href="/">Home</a></div>'
        . '<div class="nav-item nav-item--folder"><button class="folder-title" aria-expanded="false"' . $controls . '><span>About Us</span></button>'
        . '<div class="folder-content"' . $panelId . '>' . $child('Our Mission', '/mission') . $child('Our Team', '/team') . '</div></div>'
        . '<div class="nav-item"><a href="/services">Services</a></div>'
        . '</nav></header>';
    $serialized = (string) (( new HtmlTransformer() )->transform($html, array())->toArray()['serialized_blocks'] ?? '');
    preg_match_all('/<!-- (\/?)wp:navigation-(link|submenu)(?: (\{.*?\}))?/', $serialized, $matches, PREG_SET_ORDER);
    $out = array();
    foreach ( $matches as $match ) {
        if ( '/' === $match[1] ) {
            $out[] = 'end';
            continue;
        }
        $attrs = json_decode($match[3] ?? '{}', true);
        $out[] = $match[2] . ':' . strip_tags((string) ($attrs['label'] ?? ''));
    }

    return $out;
};

$controlled = $items(' aria-controls="about-us"', ' id="about-us"');
$assert(
    array( 'link:Home', 'submenu:About Us', 'link:Our Mission', 'link:Our Team', 'end', 'link:Services' ) === $controlled,
    'the controlled panel becomes the button\'s submenu under the button label',
    json_encode($controlled)
);

$unbound = $items(' aria-controls="elsewhere"', ' id="about-us"');
$assert(! in_array('submenu:About Us', $unbound, true), 'a button that names another element does not claim the panel', json_encode($unbound));

if ( 0 < $failures ) {
    fwrite(STDERR, "Navigation controlled dropdown item: {$failures} failed, {$passes} passed\n");
    exit(1);
}

echo "Navigation controlled dropdown item passed: {$passes} assertions\n";
