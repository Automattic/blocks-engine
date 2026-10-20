<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

// A `divider` or `separator` token inside a modifier class such as
// `show-divider` ("draw a divider after this item") must not turn a labelled
// navigation item into decoration. Only an element with no text of its own is
// a divider.

$assert = static function (bool $condition, string $message): void {
    if ( ! $condition ) {
        fwrite(STDERR, 'FAIL: ' . $message . PHP_EOL);
        exit(1);
    }
};

$compile = static function (string $itemTemplate, string $between = ''): array {
    $items = array( '/' => 'Home', '/one/' => 'One', '/two/' => 'Two' );
    $nav = static function (string $current) use ($items, $itemTemplate, $between): string {
        $markup = array();
        foreach ( $items as $href => $label ) {
            $markup[] = strtr($itemTemplate, array(
                '{active}' => $href === $current ? 'active ' : '',
                '{href}' => $href,
                '{label}' => $label,
            ));
        }

        return '<nav aria-label="Main"><ul>' . implode($between, $markup) . '</ul></nav>';
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
        'home' => (string) ( $plan['pages'][0]['canonical_block_markup'] ?? '' ),
        'links' => $links,
    );
};

$modifier = $compile('<li class="{active}show-divider"><a href="{href}">{label}</a></li>');
$assert('' !== $modifier['header'], 'a menu whose items carry show-divider is extracted as a shared header part');
$assert(str_contains($modifier['header'], '<!-- wp:navigation '), 'show-divider items compile to core/navigation');
$assert(3 === $modifier['links'], 'every show-divider item stays a navigation link');
$assert(! str_contains($modifier['home'] . $modifier['header'], '<!-- wp:list '), 'show-divider items do not fall back to core/list');

$decoration = $compile('<li class="{active}"><a href="{href}">{label}</a></li>', '<li class="divider"></li>');
$assert(str_contains($decoration['header'], '<!-- wp:navigation '), 'a menu with empty divider items still compiles to core/navigation');
$assert(3 === $decoration['links'], 'an empty divider item remains decoration');

echo "Navigation divider item contract passed\n";
