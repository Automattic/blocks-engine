<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

$assert = static function (bool $condition, string $message): void {
    if ( ! $condition ) {
        fwrite(STDERR, 'FAIL: ' . $message . PHP_EOL);
        exit(1);
    }
};

$links = '<li><a href="/chair/">Chair Message</a></li><li><a href="/video/">Department Video</a></li>';
foreach ( array( 'ul', 'ol' ) as $listTag ) {
    $html = '<div id="navigation">'
        . '<ul class="menu accordion data-blocks-engine-capture-mobile-only"><li><a href="/about/">About Us</a>'
        . '<' . $listTag . ' class="menu">' . $links . '</' . $listTag . '>'
        . '</li><li><a href="/people/">People</a></li></ul></div>'
        . '<main><h1>Department Video</h1><p>Body text.</p></main>'
        . '<aside><h2>About Us</h2><div class="sidebar-menu"><ul class="menu">' . $links . '</ul></div></aside>';
    // Also cover the exact reduced fixture, without extra submenu wrappers.
    foreach ( array( $html, str_replace('<' . $listTag . ' class="menu">' . $links . '</' . $listTag . '>', '<ul>' . $links . '</ul>', $html) ) as $fixture ) {
        $result = (new HtmlTransformer())->transform($fixture)->toArray();
        $markup = (string) ($result['serialized_blocks'] ?? '');
        preg_match_all('/<!-- wp:navigation (.*?) -->/', $markup, $matches);
        $sidebar = null;
        foreach ( $matches[1] as $json ) {
            $attrs = json_decode($json, true);
            if ( str_contains($attrs['className'] ?? '', 'sidebar-menu') ) {
                $sidebar = $attrs;
            }
        }
        $assert(null !== $sidebar, 'Sidebar is emitted as navigation');
        $assert('never' === ($sidebar['overlayMenu'] ?? null), 'Sidebar links remain inline on mobile');
        foreach ( array( 'blocks-engine-native-responsive-navigation', 'blocks-engine-merged-device-navigation' ) as $class ) {
            $assert(! str_contains($sidebar['className'] ?? '', $class), 'Sidebar has no ' . $class . ' marker');
        }
        $assert(2 === count($matches[1]), 'Mobile accordion remains a separate navigation');
    }
}

echo "Sidebar mobile submenu navigation contract passed\n";
