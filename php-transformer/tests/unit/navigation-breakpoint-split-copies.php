<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

// A page that carries the same menu in a desktop-only wrapper and in a
// mobile-only wrapper needs both: the wrappers stay groups around
// core/navigation, so the desktop copy cannot serve the mobile viewport.

$assert = static function (bool $condition, string $message): void {
    if ( ! $condition ) {
        fwrite(STDERR, 'FAIL: ' . $message . PHP_EOL);
        exit(1);
    }
};

$menu = '<nav aria-label="Main"><ul><li class="active"><a href="/">Home</a></li><li><a href="/one/">One</a></li><li><a href="/two/">Two</a></li></ul></nav>';
$css = '<style>@media (max-width:767px){.desktop-only{display:none}}@media (min-width:768px){.mobile-only{display:none}}</style>';
$navigationIn = static function (string $markup, string $wrapperClass): int {
    $open = strpos($markup, 'class="wp-block-group ' . $wrapperClass . '"');
    if ( false === $open ) {
        return -1;
    }
    $next = strpos($markup, '<!-- wp:custom/layout-shell', $open);
    $end = false === $next ? strlen($markup) : $next;

    return substr_count(substr($markup, $open, $end - $open), '<!-- wp:navigation ');
};

$split = (string) (( new HtmlTransformer() )->transform($css
    . '<div class="desktop-only"><div class="bar">' . $menu . '</div></div>'
    . '<div class="mobile-only"><div class="bar">' . $menu . '</div></div>'
    . '<main><h1>Home</h1></main>')->toArray()['serialized_blocks'] ?? '');
$assert(1 === $navigationIn($split, 'desktop-only'), 'the desktop-only wrapper keeps its navigation');
$assert(1 === $navigationIn($split, 'mobile-only'), 'the mobile-only wrapper keeps its navigation');

// A copy hidden at the reference viewport is still a removable duplicate when
// the kept copy's wrappers stay visible at the collapsed viewport.
$redundant = (string) (( new HtmlTransformer() )->transform($css
    . '<div class="bar">' . $menu . '</div>'
    . '<div class="mobile-only"><div class="bar">' . $menu . '</div></div>'
    . '<main><h1>Home</h1></main>')->toArray()['serialized_blocks'] ?? '');
$assert(1 === substr_count($redundant, '<!-- wp:navigation '), 'a mobile-only copy of an always-visible menu is still deduplicated');

echo "Navigation breakpoint-split copies contract passed\n";
