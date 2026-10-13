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

// Converted chrome can also carry route state outside navigation links: a
// list-item group whose id is replaced by a state id, and the disclosure
// state of the nested menu that holds the served route.
$chrome = static function (string $current): string {
    $markup = '<!-- wp:group {"className":"masthead"} --><div class="wp-block-group masthead">';
    foreach (array('home' => 'Home', 'projects' => 'Projects') as $slug => $label) {
        $id = $slug === $current ? 'active' : 'item-' . $slug;
        $markup .= '<!-- wp:group {"anchor":"' . $id . '","className":"menu-item","tagName":"li"} --><li class="wp-block-group menu-item" id="' . $id . '"><!-- wp:paragraph --><p><a href="/' . $slug . '">' . $label . '</a></p><!-- /wp:paragraph -->';
        if ('projects' === $slug) {
            $markup .= '<!-- wp:navigation {"className":"submenu' . (in_array($current, array('one', 'two'), true) ? ' open' : '') . '","overlayMenu":"never"} -->';
            foreach (array('one' => 'One', 'two' => 'Two', 'three' => 'Three') as $sub => $subLabel) {
                $markup .= $sub === $current
                    ? '<!-- wp:navigation-link {"className":"submenu-item submenu-link blocks-engine-current-navigation-item","label":"' . $subLabel . '","url":"/' . $sub . '","kind":"custom"} /-->'
                    : '<!-- wp:navigation-link {"anchor":"sub-' . $sub . '","className":"submenu-item","metadata":{"blocksEngineNavigationAnchor":{"style":"","className":"submenu-link"}},"label":"' . $subLabel . '","url":"/' . $sub . '","kind":"custom"} /-->';
            }
            $markup .= '<!-- /wp:navigation -->';
        }
        $markup .= '</li><!-- /wp:group -->';
    }
    return $markup . '</div><!-- /wp:group -->';
};
$resting = ShellExtraction::identityMarkup($chrome('none'));
foreach (array('home', 'projects', 'one', 'two') as $current) {
    $assert($resting === ShellExtraction::identityMarkup($chrome($current)), "Chrome serving {$current} has the resting chrome identity.");
}
$assert($resting !== ShellExtraction::identityMarkup(str_replace('>Projects<', '>Portfolio<', $chrome('none'))), 'A different menu label is a different chrome identity.');
$part = ShellExtraction::withoutCurrentNavigationState($chrome('two'));
$assert(!str_contains($part, 'active') && !str_contains($part, 'submenu open') && str_contains($part, '"anchor":"item-projects"') && str_contains($part, '"className":"submenu-item","metadata":{"blocksEngineNavigationAnchor":{"style":"","className":"submenu-link"}}'), 'The shared part keeps stable ids and freezes neither the state id, the disclosed submenu, nor the selected item presentation: ' . $part);

echo "shared-shell-route-state contract passed\n";
