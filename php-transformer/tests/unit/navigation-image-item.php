<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

// A menu item whose only content is an image link with no accessible name —
// the centred logo of a header menu — has no label for `anchorLabel()` to
// read. It must not reject the whole list: the menu still lowers to
// core/navigation with every labelled link, the logo image keeps its source
// position inside the navigation, and the header stays one shared template
// part across routes instead of falling back to per-route groups.

$assert = static function (bool $condition, string $message): void {
    if ( ! $condition ) {
        fwrite(STDERR, 'FAIL: ' . $message . PHP_EOL);
        exit(1);
    }
};

$compile = static function (string $logoItem): array {
    $items = array( '/' => 'Home', '/one/' => 'One', '/two/' => 'Two' );
    $nav = static function (string $current) use ($items, $logoItem): string {
        $markup = '';
        foreach ( $items as $href => $label ) {
            $markup .= '<li' . ( $href === $current ? ' class="active"' : '' ) . '><a href="' . $href . '">' . $label . '</a></li>';
        }
        $markup .= $logoItem;

        return '<nav aria-label="Main"><ul class="root menu-picture">' . $markup . '</ul></nav>';
    };
    $page = static fn (string $path, string $title): string => '<!doctype html><html><head><title>' . $title . '</title></head><body>'
        . '<header>' . $nav($path) . '</header><main><h1>' . $title . '</h1><p>About ' . $title . '.</p></main>'
        . '<footer><p>Footer</p></footer></body></html>';

    $plan = ( new ArtifactCompiler() )->compile(array(
        'entrypoint' => 'index.html',
        'files' => array(
            'index.html' => $page('/', 'Home'),
            'one/index.html' => $page('/one/', 'One'),
            'two/index.html' => $page('/two/', 'Two'),
        ),
    ))->toArray()['source_reports']['wordpress_site_plan'] ?? array();

    $headers = array_values(array_filter(
        $plan['template_parts'] ?? array(),
        static fn (array $part): bool => 'header' === ( $part['area'] ?? '' )
    ));
    $header = array() === $headers ? '' : (string) ( $headers[0]['canonical_block_markup'] ?? '' );
    $menus = array_values(array_filter(
        $plan['menus'] ?? array(),
        static fn (array $menu): bool => is_string($menu['block_markup'] ?? null)
    ));

    $links = 0;
    $menuMarkup = implode("\n", array_map(static fn (array $menu): string => (string) ( $menu['block_markup'] ?? '' ), $menus));
    $links = substr_count($menuMarkup, '<!-- wp:navigation-link ');

    $headerPlacements = array_map(
        static fn (array $part): string => (string) ( $part['placement']['kind'] ?? '' ),
        $headers
    );

    return array(
        'header' => $header,
        'headers' => $headers,
        'header_placements' => $headerPlacements,
        'menus' => $menus,
        'menu_markup' => $menuMarkup,
        'links' => $links,
        'home' => (string) ( ( $plan['pages'][0]['canonical_block_markup'] ?? '' ) ),
        'markup' => $header . (string) ( $plan['pages'][0]['canonical_block_markup'] ?? '' ),
    );
};

$logo = $compile('<li class="item-picture"><a href="/" class="menu-picture-link"><picture><img src="logo.png"></picture></a></li>');
$assert('' !== $logo['header'], 'a menu with a nameless image item is extracted as a shared header part');
$assert(1 === count($logo['headers']), 'the header is one template part, not one per route');
$assert(array('shared_shell') === $logo['header_placements'], 'the shared header part serves every route');
$assert(str_contains($logo['header'], '<!-- wp:navigation '), 'the menu with a nameless image item compiles to core/navigation');
$assert(4 === $logo['links'], 'the logo stays a navigation item beside every labelled link');
$logoLink = null;
if ( preg_match_all('/<!-- wp:navigation-link (\{.*?\}) \/-->/', $logo['menu_markup'], $links) ) {
    foreach ( $links[1] as $json ) {
        $attrs = json_decode($json, true);
        if ( is_array($attrs) && str_contains((string) ( $attrs['label'] ?? '' ), '<img') ) {
            $logoLink = array( 'attrs' => $attrs );
        }
    }
}
$assert(null !== $logoLink, 'the logo is a navigation link labelled by its image');
$logoAttrs = $logoLink['attrs'];
$assert(str_starts_with((string) ( $logoAttrs['label'] ?? '' ), '<img '), 'the logo link label is the inline image');
$assert('/' === ( $logoAttrs['url'] ?? '' ), 'the logo link keeps its destination');
$assert(str_contains((string) ( $logoAttrs['className'] ?? '' ), 'item-picture'), 'the logo link keeps the list item classes the menu rules address');
$assert(! str_contains($logo['markup'], '<!-- wp:list '), 'the menu does not fall back to core/list');

// The same logo served as a `<picture>` whose `<source>` selects another
// format still stays a navigation item, labelled by the `<img>` fallback.
$selected = $compile('<li class="item-picture"><a href="https://example.test/" class="menu-picture-link"><picture><source srcset="logo.webp" type="image/webp"><img src="logo.png"></picture></a></li>');
$assert(1 === count($selected['headers']) && array('shared_shell') === $selected['header_placements'], 'a source-selected logo keeps one shared header part');
$assert(str_contains($selected['header'], '<!-- wp:navigation '), 'a menu with a source-selected logo compiles to core/navigation');
$assert(4 === $selected['links'], 'a logo inside a <picture> with a <source> stays a navigation item');
$assert(! str_contains($selected['menu_markup'], 'u003csource'), 'a navigation label carries the image fallback, not the picture sources');

// A nameless image item whose anchor targets a fragment keeps declining: a
// link that drives an in-page control is chrome, not brand content.
$fragment = $compile('<li><a href="#top"><img src="logo.png"></a></li>');
$assert('' === $fragment['header'], 'a nameless image item anchored to a fragment still rejects the menu');

// A named image item keeps the named-image contract: the alt text becomes
// the link label, never raw markup.
$named = $compile('<li class="item-picture"><a href="/" class="menu-picture-link"><picture><img src="logo.png" alt="Bakery"></picture></a></li>');
$assert(str_contains($named['header'], '<!-- wp:navigation '), 'a named image item still compiles to core/navigation');
$assert(4 === $named['links'], 'a named image item stays a labelled navigation link');
$assert(str_contains($named['menu_markup'], '"label":"Bakery"'), 'a named image item is labelled with its alt text');

echo "Navigation image item contract passed\n";
