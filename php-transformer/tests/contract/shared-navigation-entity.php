<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\ShellExtraction;

$assert = static function (bool $condition, string $message): void { if (! $condition) throw new RuntimeException($message); };
$pages = static function (array $plan): array { $rows = array(); foreach ($plan['pages'] as $page) $rows[$page['source_path']] = $page; return $rows; };
$entityMenus = static function (array $plan): array {
    return array_values(array_filter($plan['menus'] ?? array(), static fn(array $menu): bool => is_string($menu['token'] ?? null) && is_string($menu['block_markup'] ?? null)));
};

$unlabeled = static function (string $title): string {
    return '<div class="frame"><div class="masthead"><p class="brand">Acme</p><nav class="primary"><a href="/">Home</a><a href="/about">About</a></nav></div><main><h1>' . $title . '</h1></main></div>';
};
$sharedPlan = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array(
    'index.html' => $unlabeled('Home'),
    'about.html' => $unlabeled('About'),
)))->toArray()['source_reports']['wordpress_site_plan'];
$sharedHeader = array_values(array_filter($sharedPlan['template_parts'], static fn(array $part): bool => 'header' === ($part['area'] ?? null)))[0] ?? array();
$headerMarkup = (string) ($sharedHeader['canonical_block_markup'] ?? '');
$menus = $entityMenus($sharedPlan);
$assert(isset($sharedHeader['slug']) && str_contains($headerMarkup, 'wp:navigation'), 'Shared unlabeled chrome still extracts a header part that contains core/navigation.');
$assert(1 === count($menus), 'Clustered header destinations become one navigation entity.');
$assert(2 === ($menus[0]['items'] ?? null) && str_contains((string) ($menus[0]['block_markup'] ?? ''), '"label":"Home"') && str_contains((string) ($menus[0]['block_markup'] ?? ''), '"url":"/"') && str_contains((string) ($menus[0]['block_markup'] ?? ''), '"label":"About"') && str_contains((string) ($menus[0]['block_markup'] ?? ''), '"url":"/about"'), 'The navigation entity keeps source labels, mapped routes, and order.');
$assert(!str_contains((string) ($menus[0]['block_markup'] ?? ''), '<!-- wp:navigation '), 'Navigation entity content is the inner links, not a nested navigation wrapper.');
$assert(is_string($menus[0]['token'] ?? null) && preg_match('/^navigation-[a-f0-9]{16}$/', (string) $menus[0]['token']) && is_string($menus[0]['reconciliation_identity'] ?? null) && 64 === strlen((string) $menus[0]['reconciliation_identity']), 'The navigation entity has a deterministic token and reconciliation identity.');
$assert(str_contains($headerMarkup, 'wp:navigation-link') && str_contains($headerMarkup, 'primary'), 'The plan keeps inline navigation children so WordPress can render the header before a materializer substitutes ref.');
$assert(str_contains((string) (array_column($sharedPlan['writes'], null, 'target_path')['functions.php']['payload']['data'] ?? ''), "render_block_core/navigation-link"), 'Current-page navigation-link recovery still ships in the theme bootstrap.');

$ownedPlan = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array(
    'index.html' => $unlabeled('Home'),
)))->toArray()['source_reports']['wordpress_site_plan'];
$ownedMenus = $entityMenus($ownedPlan);
$assert(1 === count($ownedMenus) && str_contains((string) ($ownedMenus[0]['block_markup'] ?? ''), '"label":"Home"'), 'A page-owned copy of the same destinations still emits the navigation entity.');

$mixed = static function (string $title, string $extra): string {
    return '<div class="frame"><div class="masthead"><p class="brand">Acme</p><nav><a href="/">Home</a><a href="/about">About</a></nav></div><main><h1>' . $title . '</h1></main><footer><nav><a href="/privacy">Privacy</a></nav><p>' . $extra . '</p></footer></div>';
};
$mixedPlan = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array(
    'index.html' => $mixed('Home', '© Acme'),
    'about.html' => $mixed('About', '© Acme'),
)))->toArray()['source_reports']['wordpress_site_plan'];
$mixedMenus = $entityMenus($mixedPlan);
$mixedTokens = array_column($mixedMenus, 'token');
$assert(2 === count($mixedMenus) && 2 === count(array_unique($mixedTokens)), 'Distinct destination lists remain distinct navigation entities.');

$nestedPlan = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array(
    'index.html' => '<header><nav><a href="/">Home</a><a href="/work">Work</a><ul><li><a href="/work">Work</a><ul><li><a href="/work/a">Project A</a></li></ul></li></ul></nav></header><main><h1>Home</h1></main>',
    'work.html' => '<header><nav><a href="/">Home</a><a href="/work">Work</a><ul><li><a href="/work">Work</a><ul><li><a href="/work/a">Project A</a></li></ul></li></ul></nav></header><main><h1>Work</h1></main>',
    'work/a.html' => '<header><nav><a href="/">Home</a><a href="/work">Work</a><ul><li><a href="/work">Work</a><ul><li><a href="/work/a">Project A</a></li></ul></li></ul></nav></header><main><h1>Project A</h1></main>',
)))->toArray()['source_reports']['wordpress_site_plan'];
$nestedMenus = $entityMenus($nestedPlan);
$assert(1 === count($nestedMenus) && str_contains((string) ($nestedMenus[0]['block_markup'] ?? ''), 'wp:navigation-submenu') && str_contains((string) ($nestedMenus[0]['block_markup'] ?? ''), '"label":"Project A"'), 'Submenus stay inside the shared navigation entity.');

$variantMenu = static function (string $title, string $eventsHref): string {
    $item = static function (string $label, string $href, bool $paragraph): string {
        $inner = $paragraph ? '<div class="pad"><p class="label">' . $label . '</p></div>' : $label;
        return '<li class="item"><a href="' . $href . '">' . $inner . '</a></li>';
    };
    $desktop = '<site-menu id="desktop-menu" class="desktop-menu"><nav aria-label="Site"><ul class="items">'
        . $item('Home', '/', true) . $item('Journal', '/journal', true) . $item('Events', $eventsHref, true)
        . '</ul></nav></site-menu>';
    $mobile = '<nav class="mobile" aria-label="Site"><ul>'
        . $item('Home', '/', false) . $item('Journal', '/journal', false) . $item('Events', '/', false)
        . '</ul></nav>';
    return '<header>' . $desktop . $mobile . '</header><main><h1>' . $title . '</h1></main>';
};
$variantPlan = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array(
    'index.html' => $variantMenu('Home', '/#events'),
    'journal.html' => $variantMenu('Journal', '/#events'),
)))->toArray()['source_reports']['wordpress_site_plan'];
$variantMenus = $entityMenus($variantPlan);
$variantMarkup = implode("\n", array_map(static fn(array $document): string => (string) ($document['canonical_block_markup'] ?? ''), array_merge($variantPlan['template_parts'] ?? array(), $variantPlan['pages'] ?? array())));
$assert(1 === count($variantMenus), 'Variant menus that differ only by a URL fragment become one navigation entity.');
$assert(3 === ($variantMenus[0]['items'] ?? null) && str_contains((string) ($variantMenus[0]['block_markup'] ?? ''), '"label":"Home"') && str_contains((string) ($variantMenus[0]['block_markup'] ?? ''), '"label":"Journal"') && str_contains((string) ($variantMenus[0]['block_markup'] ?? ''), '"label":"Events"'), 'The shared variant entity keeps the ordered item set.');
$assert(2 <= substr_count($variantMarkup, '<!-- wp:navigation '), 'Both viewport navigations remain separate blocks.');
$assert(!str_contains($variantMarkup, '{{wordpress-site-plan:navigation:'), 'Variant hosts stay inline until a materializer can bind an integer ref.');

$divergentVariant = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array(
    'index.html' => $variantMenu('Home', '/shop'),
    'journal.html' => $variantMenu('Journal', '/shop'),
)))->toArray()['source_reports']['wordpress_site_plan'];
$assert(2 === count($entityMenus($divergentVariant)), 'Variant menus with different destinations stay independent.');

$currentItem = '<!-- wp:navigation-link {"className":"item blocks-engine-current-navigation-item be-inline-geometry-0123456789abcdef","label":"Home","url":"/"} /-->';
$entityItem = ShellExtraction::withoutCurrentNavigationState($currentItem);
$assert(str_contains($entityItem, 'be-inline-geometry-0123456789abcdef') && !str_contains($entityItem, 'blocks-engine-current-navigation-item'), 'The shared navigation entity keeps a current item\'s geometry carrier.');
$identityItem = ShellExtraction::withoutCurrentNavigationState($currentItem, true);
$assert(!str_contains($identityItem, 'be-inline-geometry-0123456789abcdef'), 'Shell identity still ignores current-item geometry.');

echo "shared-navigation-entity: ok\n";
