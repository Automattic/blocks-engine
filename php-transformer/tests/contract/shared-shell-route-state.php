<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\ShellExtraction;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\WordPressSitePlan;

$assert = static function (bool $condition, string $message): void { if (! $condition) throw new RuntimeException($message); };

// An unlabeled masthead (a leading sibling holding the menu) shares its page
// wrapper with content that carries its own <footer> landmark. Each page marks
// its own menu item with authored route state: a state id in place of the
// item's id, and a current class on a submenu item.
$menu = static function (string $current): string {
    $html = '<ul class="menu">';
    foreach (array('index' => 'Home', 'about' => 'About', 'contact' => 'Contact') as $slug => $label) {
        $html .= '<li id="' . ($slug === $current ? 'active' : 'item-' . $slug) . '" class="menu-item"><a href="/' . $slug . '.html" class="menu-link">' . $label . '</a></li>';
    }
    $html .= '<li id="item-work" class="menu-item"><a href="/work.html" class="menu-link">Work</a><ul class="submenu">';
    foreach (array('alpha' => 'Alpha', 'beta' => 'Beta', 'gamma' => 'Gamma') as $slug => $label) {
        $html .= '<li id="sub-' . $slug . '" class="submenu-item' . ($slug === $current ? ' submenu-current' : '') . '"><a href="/' . $slug . '.html" class="submenu-link">' . $label . '</a></li>';
    }
    return $html . '</ul></li></ul>';
};
$page = static fn(string $current, string $title, string $brand = 'Brand'): string => '<!doctype html><html><head><style>.masthead{background:#222}.menu-link{color:#fff;padding:4px}.submenu-link{color:#ccc}</style></head><body><div class="wrapper"><div class="masthead"><p class="brand">' . $brand . '</p><nav class="primary">' . $menu($current) . '</nav></div><div class="canvas"><h1>' . $title . '</h1><p>' . $title . ' body copy.</p><footer class="colophon"><p>Colophon</p></footer></div></div></body></html>';
$titles = array('index' => 'Home', 'about' => 'About', 'contact' => 'Contact', 'alpha' => 'Alpha', 'beta' => 'Beta', 'gamma' => 'Gamma');
$files = array();
foreach ($titles as $slug => $title) $files[$slug . '.html'] = $page($slug, $title);
// A genuinely different masthead stays page-owned.
$files['work.html'] = $page('work', 'Work', 'Other brand');

$plan = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => $files))->toArray()['source_reports']['wordpress_site_plan'];
WordPressSitePlan::assertValid($plan);
$pages = array_column($plan['pages'], null, 'source_path');
$headers = array_values(array_filter($plan['template_parts'], static fn(array $part): bool => 'header' === ($part['area'] ?? null)));
$footers = array_values(array_filter($plan['template_parts'], static fn(array $part): bool => 'footer' === ($part['area'] ?? null)));

$assert(1 === count($headers) && 'extracted' === ($headers[0]['provenance']['decision'] ?? null), 'Mastheads that differ only in authored route state share one header template part: ' . json_encode(array_column($plan['diagnostics'], 'code')));
$assert(1 === count($footers), 'The nested footer landmark is still shared alongside the unlabeled masthead.');
// One menu serves the shared header; the page-owned masthead keeps its own.
$shared = array_values(array_filter($plan['menus'], static fn(array $menu): bool => str_contains((string) $headers[0]['canonical_block_markup'], '{{wordpress-site-plan:navigation:' . $menu['token'] . '}}')));
$assert(2 === count($plan['menus']) && 1 === count($shared), 'One navigation entity serves every shared route instead of one copy per page: ' . count($plan['menus']));
$menuMarkup = (string) $shared[0]['block_markup'];
$assert(!str_contains($menuMarkup, 'blocks-engine-current-navigation-item') && !str_contains($menuMarkup, '"anchor":"active"') && !str_contains($menuMarkup, 'submenu-current'), 'The shared menu freezes no route selection.');
preg_match_all('/<!-- wp:navigation-link (\{.*?\}) \/-->/', $menuMarkup, $links);
$assert(6 === count($links[1]) && array() === array_filter($links[1], static fn(string $attrs): bool => !isset(json_decode($attrs, true)['metadata']['blocksEngineNavigationAnchor'])), 'The selected item renders through an anchor subject like its resting peers: ' . $menuMarkup);
foreach ($titles as $slug => $title) {
    $markup = (string) ($pages[$slug . '.html']['canonical_block_markup'] ?? '');
    $assert(1 === substr_count($markup, '"slug":"header"') && !str_contains($markup, '<!-- wp:navigation ') && !str_contains($markup, 'Brand') && str_contains($markup, $title . ' body copy.'), "{$slug}.html binds the shared header and keeps its content: {$markup}");
}
$work = (string) ($pages['work.html']['canonical_block_markup'] ?? '');
$assert(!str_contains($work, '"slug":"header"') && str_contains($work, 'Other brand') && str_contains($work, '<!-- wp:navigation '), 'A genuinely different masthead remains page-owned.');

// Converted chrome: a nested menu is disclosed (`open`) only on the routes it
// holds, and its authored current item renders without the anchor subject
// its resting siblings use, carrying stand-in line-height/box markers instead.
$box = 'blocks-engine-navigation-link-box-' . str_repeat('b', 64);
$lineHeight = 'blocks-engine-navigation-anchor-line-height-' . str_repeat('c', 64);
$chrome = static function (string $current) use ($box, $lineHeight): string {
    $markup = '<!-- wp:group {"className":"masthead"} --><div class="wp-block-group masthead"><!-- wp:paragraph --><p><a href="/">Brand</a></p><!-- /wp:paragraph -->';
    $markup .= '<!-- wp:navigation {"className":"submenu' . ('none' === $current ? '' : ' open') . '","overlayMenu":"never"} -->';
    foreach (array('one' => 'One', 'two' => 'Two', 'three' => 'Three') as $sub => $label) {
        $markup .= $sub === $current
            ? '<!-- wp:navigation-link {"anchor":"sub-' . $sub . '","className":"submenu-item submenu-link ' . $lineHeight . ' blocks-engine-navigation-link-color-states-1 ' . $box . ' blocks-engine-current-navigation-item","label":"' . $label . '","url":"/' . $sub . '","kind":"custom"} /-->'
            : '<!-- wp:navigation-link {"anchor":"sub-' . $sub . '","className":"submenu-item","metadata":{"blocksEngineNavigationAnchor":{"style":"","className":"submenu-link"}},"label":"' . $label . '","url":"/' . $sub . '","kind":"custom"} /-->';
    }
    return $markup . '<!-- /wp:navigation --></div><!-- /wp:group -->';
};
$resting = ShellExtraction::identityMarkup($chrome('none'));
foreach (array('one', 'two', 'three') as $current) {
    $assert($resting === ShellExtraction::identityMarkup($chrome($current)), "Chrome serving {$current} has the resting chrome identity.");
}
$assert($resting !== ShellExtraction::identityMarkup(str_replace('"Two"', '"Portfolio"', $chrome('none'))), 'A different menu label is a different chrome identity.');
$assert(str_contains(ShellExtraction::identityMarkup(str_replace('"className":"submenu"', '"className":"submenu open"', $chrome('none'))), 'open'), 'Disclosure state on a menu that does not hold the served route stays part of the identity.');
// An authored current submenu omits the resting colour marker its siblings
// share; identity recovers it from siblings with the same classes, whatever
// order the source listed them in.
$color = 'blocks-engine-navigation-link-color-' . str_repeat('d', 64);
$submenus = static function (bool $current) use ($color): string {
    $markup = '<!-- wp:navigation {"className":"menu","overlayMenu":"never"} -->';
    foreach (array('A', 'B') as $label) {
        $classes = 'z-item a-item blocks-engine-navigation-link-color-states-1 ' . ($current && 'A' === $label ? 'blocks-engine-current-navigation-item' : $color);
        $markup .= '<!-- wp:navigation-submenu {"className":"' . $classes . '","label":"' . $label . '","url":"/' . strtolower($label) . '","kind":"custom"} --><!-- wp:navigation-link {"className":"leaf","label":"' . $label . ' leaf","url":"/' . strtolower($label) . '-leaf","kind":"custom"} /--><!-- /wp:navigation-submenu -->';
    }
    return $markup . '<!-- /wp:navigation -->';
};
$assert(ShellExtraction::identityMarkup($submenus(false)) === ShellExtraction::identityMarkup($submenus(true)), 'An authored current submenu has its resting siblings\' identity.');

$part = ShellExtraction::withoutCurrentNavigationState($chrome('two'));
preg_match('/<!-- wp:navigation-link (\{[^>]*"label":"Two"[^>]*\}) \/-->/', $part, $two);
$twoAttrs = json_decode($two[1] ?? 'null', true) ?? array();
$twoClasses = preg_split('/\s+/', (string) ($twoAttrs['className'] ?? '')) ?: array();
$assert(!str_contains($part, 'submenu open') && in_array('submenu-item', $twoClasses, true) && !in_array('submenu-link', $twoClasses, true) && !in_array('blocks-engine-current-navigation-item', $twoClasses, true) && array('style' => '', 'className' => 'submenu-link') === ($twoAttrs['metadata']['blocksEngineNavigationAnchor'] ?? null), 'The shared part freezes neither the disclosed menu nor the selected item; the item renders through its siblings\' anchor subject: ' . $part);

echo "shared-shell-route-state contract passed\n";
