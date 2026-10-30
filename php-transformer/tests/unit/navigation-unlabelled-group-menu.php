<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

// A menu list holding an unlabelled nested group — an `li` whose only child is
// a nested `ul` with no anchor of its own — used to read as one anchor-less
// item and reject the whole menu. The landmark then fell to core/group +
// core/list and the icon-only aria-haspopup toggle became a plain core/button
// with its SVG stripped, so the mobile menu lost its opener. The group has no
// label a submenu could show, so its items flatten into the parent level in
// source order with their destinations kept; the toggle becomes the menu's
// opener as for a flat list. A group holding non-navigation content is not
// flattened: the menu declines and the native list path renders that content.

$assertions = 0;
$failures = 0;
$assert = static function (bool $condition, string $message) use (&$assertions, &$failures): void {
    ++$assertions;
    if (! $condition) {
        ++$failures;
        fwrite(STDERR, "FAIL: {$message}\n");
    }
};

$toggle = '<button type="button" aria-haspopup="true"><svg viewBox="0 0 17 17"><line x2="100%"></line><line x2="100%"></line></svg></button>';
$groups = array(
    'flat'                     => '<li><a href="/a">A</a></li><li><a href="/b">B</a></li><li><a href="/c">C</a></li>',
    'unlabelled-nested-group'  => '<li><a href="/a">A</a></li><li><ul><li><a href="/b">B</a></li><li><a href="/c">C</a></li></ul></li>',
    'labelled-submenu'         => '<li><a href="/a">A</a></li><li><a href="/p">P</a><ul><li><a href="/b">B</a></li><li><a href="/c">C</a></li></ul></li>',
    'paragraph-nested-group'   => '<li><a href="/a">A</a></li><li><ul><li><p>Group copy.</p></li></ul></li>',
);

// Cases measured through the real path: every variant compiles as its own
// artifact and is read back from the site plan's header template part.
$cases = array(
    array(
        'name' => 'flat list (control)',
        'list' => 'flat',
        'navigation' => true,
        'items' => array(array('A', '/a'), array('B', '/b'), array('C', '/c')),
        'submenus' => 0,
    ),
    array(
        'name' => 'unlabelled nested group flattens into the parent level',
        'list' => 'unlabelled-nested-group',
        'navigation' => true,
        'items' => array(array('A', '/a'), array('B', '/b'), array('C', '/c')),
        'submenus' => 0,
    ),
    array(
        'name' => 'labelled submenu keeps the submenu shape (unchanged)',
        'list' => 'labelled-submenu',
        'navigation' => true,
        'items' => array(array('A', '/a'), array('P', '/p'), array('B', '/b'), array('C', '/c')),
        'submenus' => 1,
    ),
    array(
        'name' => 'nested group holding a paragraph keeps the content',
        'list' => 'paragraph-nested-group',
        'navigation' => false,
        'kept_text' => 'Group copy.',
    ),
);

$results = array();
foreach ($cases as $case) {
    $header = '<header><nav aria-label="Site"><ul aria-hidden="true" style="display:none">' . $groups[$case['list']] . '</ul>' . $toggle . '</nav></header>';
    $plan = (new ArtifactCompiler())->compile(array(
        'entrypoint' => 'index.html',
        'files' => array(
            'index.html' => "<!doctype html><html><body>{$header}<main><p>x</p></main></body></html>",
        ),
    ))->toArray()['source_reports']['wordpress_site_plan'] ?? array();

    $parts = array_values(array_filter(
        $plan['template_parts'] ?? array(),
        static fn (array $part): bool => 'header' === ($part['area'] ?? '')
    ));
    $headerMarkup = 1 === count($parts) ? (string) ($parts[0]['canonical_block_markup'] ?? '') : '';
    $assert('' !== $headerMarkup, "[{$case['name']}] the header template part carries canonical block markup");
    $results[$case['list']] = array(
        'header' => $headerMarkup,
        'menus' => implode("\n", array_map(
            static fn (array $menu): string => (string) ($menu['block_markup'] ?? ''),
            $plan['menus'] ?? array()
        )),
    );

    $isNavigation = str_contains($headerMarkup, '<!-- wp:navigation ');
    $assert($case['navigation'] === $isNavigation, "[{$case['name']}] the landmark " . ($case['navigation'] ? 'becomes' : 'does not become') . ' core/navigation');

    if (! $case['navigation']) {
        $assert(str_contains($headerMarkup, (string) ($case['kept_text'] ?? '')), "[{$case['name']}] the group's non-navigation content stays in the header");
        $assert(str_contains($headerMarkup, 'href="/a"'), "[{$case['name']}] the flat menu items stay in the header");
        continue;
    }

    $assert(str_contains($headerMarkup, '"overlayMenu":"mobile"'), "[{$case['name']}] the navigation declares the mobile overlay menu");
    $assert(str_contains($headerMarkup, 'blocks-engine-native-navigation-toggle-'), "[{$case['name']}] the icon-only toggle becomes the menu's opener");
    $assert(! str_contains($headerMarkup, '<!-- wp:list '), "[{$case['name']}] the menu is not left as a native list");

    $menuMarkup = $results[$case['list']]['menus'];
    $items = array();
    if ( false !== preg_match_all('/<!-- wp:navigation-(?:link|submenu) \{"label":"((?:[^"\\\\]|\\\\.)*)","url":"((?:[^"\\\\]|\\\\.)*)"/', $menuMarkup, $matches, PREG_SET_ORDER) ) {
        foreach ($matches as $match) {
            $items[] = array($match[1], $match[2]);
        }
    }
    $assert($case['items'] === $items, "[{$case['name']}] the menu keeps its items in source order with their hrefs\nexpected: " . json_encode($case['items'], JSON_UNESCAPED_SLASHES) . "\nactual:   " . json_encode($items, JSON_UNESCAPED_SLASHES));
    $assert($case['submenus'] === substr_count($menuMarkup, '<!-- wp:navigation-submenu '), "[{$case['name']}] the menu declares {$case['submenus']} submenu(s)");
}

// Flattening an unlabelled group yields exactly the menu the flat list makes.
$assert(
    '' !== $results['flat']['menus'] && $results['flat']['menus'] === $results['unlabelled-nested-group']['menus'],
    "the unlabelled group flattens to the same menu as the flat list"
);

if (0 !== $failures) {
    fwrite(STDERR, "navigation-unlabelled-group-menu: {$failures} of {$assertions} assertions failed\n");
    exit(1);
}

echo "navigation-unlabelled-group-menu: {$assertions} assertions passed\n";
