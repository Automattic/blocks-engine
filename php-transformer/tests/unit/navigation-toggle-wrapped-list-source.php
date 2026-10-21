<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

// A CSS-only menu toggle wraps the menu list inside a list item:
// `nav > input + ul > li > (label + ul.menu)`. The nested list is the menu —
// its items lower to navigation links — so it is also the list whose classes
// and layout core's item container must carry. Reading the outer wrapper list
// instead drops the menu list's classes, and every author rule written for it
// (here, centred justification) matches nothing.

$assert = static function (bool $condition, string $message): void {
    if ( ! $condition ) {
        fwrite(STDERR, 'FAIL: ' . $message . PHP_EOL);
        exit(1);
    }
};

$menu = static function (bool $wrapped): array {
    $items = '<li><a href="/">Home</a></li><li><a href="/one/">One</a></li><li><a href="/two/">Two</a></li>';
    $list = '<ul class="root menu">' . $items . '</ul>';
    $nav = $wrapped
        ? '<nav id="main-menu" aria-label="Main"><input type="checkbox" id="toggle"><ul><li class="menu-mobile"><label for="toggle">Menu</label>' . $list . '</li></ul></nav>'
        : '<nav id="main-menu" aria-label="Main">' . $list . '</nav>';
    $html = '<!doctype html><html><head><title>Home</title><style>'
        . '#toggle{display:none}'
        . '#main-menu ul.root{display:flex;justify-content:center;flex-wrap:wrap}'
        . '@media (max-width:767px){.menu-mobile>ul{display:none}#toggle:checked~ul .menu-mobile>ul{display:block}}'
        . '</style></head><body><header>' . $nav . '</header><main><h1>Home</h1><p>Body.</p></main></body></html>';

    $plan = ( new ArtifactCompiler() )->compile(array(
        'entrypoint' => 'index.html',
        'files' => array( 'index.html' => $html ),
    ))->toArray()['source_reports']['wordpress_site_plan'] ?? array();

    $navigation = null;
    $walk = static function ($value) use (&$walk, &$navigation): void {
        if ( null !== $navigation ) {
            return;
        }
        if ( is_string($value) && preg_match('/<!-- wp:navigation (\{.*?\}) \/?-->/s', $value, $match) ) {
            $attrs = json_decode($match[1], true);
            if ( is_array($attrs) ) {
                $navigation = $attrs;
            }
            return;
        }
        if ( is_array($value) ) {
            foreach ( $value as $child ) {
                $walk($child);
            }
        }
    };
    $walk($plan);

    return array( 'navigation' => $navigation );
};

$flat = $menu(false);
$wrapped = $menu(true);

$assert(is_array($flat['navigation']), 'the plain menu list compiles to core/navigation');
$assert(is_array($wrapped['navigation']), 'the toggle-wrapped menu list compiles to core/navigation');

$classes = static fn (array $attrs): array => preg_split('/\s+/', trim((string) ( $attrs['className'] ?? '' ))) ?: array();
$assert(in_array('root', $classes($flat['navigation']), true), 'the plain menu carries its list classes');
$assert(in_array('root', $classes($wrapped['navigation']), true), 'the toggle-wrapped menu carries its own list classes, not the wrapper list');
$assert(in_array('menu', $classes($wrapped['navigation']), true), 'every class of the toggle-wrapped menu list reaches core/navigation');
$assert(
    ( $flat['navigation']['layout']['justifyContent'] ?? null ) === ( $wrapped['navigation']['layout']['justifyContent'] ?? null ),
    'the toggle-wrapped menu resolves the same layout as the same list unwrapped'
);

echo "Navigation toggle-wrapped list source contract passed\n";
