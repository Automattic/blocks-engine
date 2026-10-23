<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

// A CSS-only hamburger (a checkbox plus the label that targets it) wraps the
// real menu list in a link-less item. That wrapper is toggle chrome, so the
// nav still converts to core/navigation and the header is shared.

$assert = static function (bool $condition, string $message): void {
    if ( ! $condition ) {
        fwrite(STDERR, 'FAIL: ' . $message . PHP_EOL);
        exit(1);
    }
};

$compile = static function (string $toggle): array {
    $items = array( '/' => 'Home', '/one/' => 'One', '/two/' => 'Two' );
    $nav = static function (string $current) use ($items, $toggle): string {
        $links = '';
        foreach ( $items as $href => $label ) {
            $links .= '<li' . ( $href === $current ? ' class="active"' : '' ) . '><a href="' . $href . '">' . $label . '</a></li>';
        }

        return '<nav aria-label="Main"><input type="checkbox" id="menu-toggle"><ul><li class="menu-mobile">'
            . $toggle . '<ul class="root">' . $links . '</ul></li></ul></nav>';
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

    $header = '';
    foreach ( $plan['template_parts'] ?? array() as $part ) {
        if ( 'header' === ( $part['area'] ?? '' ) ) {
            $header = (string) ( $part['canonical_block_markup'] ?? '' );
        }
    }
    $links = 0;
    foreach ( $plan['menus'] ?? array() as $menu ) {
        $links += substr_count((string) ( $menu['block_markup'] ?? '' ), '<!-- wp:navigation-link ');
    }

    return array(
        'header' => $header,
        'markup' => $header . (string) ( $plan['pages'][0]['canonical_block_markup'] ?? '' ),
        'links' => $links,
    );
};

$toggle = $compile('<label for="menu-toggle"><span class="menu-switch">Menu</span></label>');
$assert('' !== $toggle['header'], 'a checkbox-toggled menu is extracted as a shared header part');
$assert(str_contains($toggle['header'], '<!-- wp:navigation '), 'a checkbox-toggled menu compiles to core/navigation');
$assert(3 === $toggle['links'], 'every item of the toggled list stays a navigation link');
$assert(! str_contains($toggle['markup'], 'custom/authored-input') && ! str_contains($toggle['markup'], '<!-- wp:list '), 'the toggle and list do not fall back to authored controls or core/list');

// A label that does not target a checkbox is not toggle chrome: the wrapper
// item keeps its content and the menu is not reduced to the nested list.
$unbound = $compile('<label for="elsewhere">Menu</label>');
$assert(0 === $unbound['links'], 'a label that targets no checkbox keeps the wrapper out of navigation recognition');

echo "Navigation checkbox toggle contract passed\n";
