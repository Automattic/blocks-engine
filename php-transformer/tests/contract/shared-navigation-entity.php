<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\WordPressSitePlan;

$assert = static function (bool $condition, string $message): void { if (! $condition) throw new RuntimeException($message); };
$pages = static function (array $plan): array { $rows = array(); foreach ($plan['pages'] as $page) $rows[$page['source_path']] = $page; return $rows; };
$writes = static function (array $plan): array { $rows = array(); foreach ($plan['writes'] as $write) $rows[$write['target_path']] = $write; return $rows; };
$navigationAttrs = static function (string $markup): array {
    if (!preg_match('/<!--\s*wp:navigation\s+(\{.*?\})\s*\/-->/s', $markup, $match)) return array();
    $attrs = json_decode($match[1], true);
    return is_array($attrs) ? $attrs : array();
};
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
$headerWrite = $writes($sharedPlan)['parts/header.html']['payload']['data'] ?? '';
$headerAttrs = $navigationAttrs($headerMarkup);
$menus = $entityMenus($sharedPlan);
$assert(isset($sharedHeader['slug']) && str_contains($headerMarkup, 'wp:navigation'), 'Shared unlabeled chrome still extracts a header part that contains core/navigation.');
$assert(1 === count($menus), 'Clustered header destinations become one navigation entity.');
$assert(2 === ($menus[0]['items'] ?? null) && str_contains((string) ($menus[0]['block_markup'] ?? ''), '"label":"Home"') && str_contains((string) ($menus[0]['block_markup'] ?? ''), '"url":"/"') && str_contains((string) ($menus[0]['block_markup'] ?? ''), '"label":"About"') && str_contains((string) ($menus[0]['block_markup'] ?? ''), '"url":"/about"'), 'The navigation entity keeps source labels, mapped routes, and order.');
$assert(!str_contains((string) ($menus[0]['block_markup'] ?? ''), '<!-- wp:navigation '), 'Navigation entity content is the inner links, not a nested navigation wrapper.');
$assert(is_string($menus[0]['token'] ?? null) && preg_match('/^navigation-[a-f0-9]{16}$/', (string) $menus[0]['token']) && is_string($menus[0]['reconciliation_identity'] ?? null) && WordPressSitePlan::contentHash('') !== ($menus[0]['reconciliation_identity'] ?? ''), 'The navigation entity has a deterministic token and reconciliation identity.');
$tokenPrefix = '{{wordpress-site-plan:navigation:';
$assert(isset($headerAttrs['ref']) && $headerAttrs['ref'] === $tokenPrefix . $menus[0]['token'] . '}}', 'The extracted header navigation references the entity by token rather than inlining children.');
$assert(!str_contains($headerMarkup, 'wp:navigation-link') && !str_contains($headerWrite, 'wp:navigation-link') && str_contains($headerMarkup, '/-->'), 'Header part and write are self-closing core/navigation references.');
$assert(isset($headerAttrs['className']) && str_contains((string) $headerAttrs['className'], 'primary'), 'Referencing navigation keeps its source className.');
$assert(str_contains((string) ($writes($sharedPlan)['functions.php']['payload']['data'] ?? ''), "render_block_core/navigation-link"), 'Hoisting links into the entity still emits the current-page navigation-link filter.');

$ownedPlan = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array(
    'index.html' => $unlabeled('Home'),
)))->toArray()['source_reports']['wordpress_site_plan'];
$ownedPage = $pages($ownedPlan)['index.html']['canonical_block_markup'] ?? '';
$ownedMenus = $entityMenus($ownedPlan);
$ownedAttrs = $navigationAttrs($ownedPage);
$assert(1 === count($ownedMenus) && isset($ownedAttrs['ref']) && $ownedAttrs['ref'] === $tokenPrefix . $ownedMenus[0]['token'] . '}}' && !str_contains($ownedPage, 'wp:navigation-link'), 'A page-owned copy of the same destinations also references the navigation entity.');

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
$privacy = array_values(array_filter($mixedMenus, static fn(array $menu): bool => str_contains((string) ($menu['block_markup'] ?? ''), '"label":"Privacy"')))[0] ?? array();
$headerMenu = array_values(array_filter($mixedMenus, static fn(array $menu): bool => str_contains((string) ($menu['block_markup'] ?? ''), '"label":"Home"')))[0] ?? array();
$assert(isset($privacy['token'], $headerMenu['token']) && $privacy['token'] !== $headerMenu['token'], 'Header and footer menus with different destinations do not share an entity.');

$nestedPlan = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array(
    'index.html' => '<header><nav><a href="/">Home</a><a href="/work">Work</a><ul><li><a href="/work">Work</a><ul><li><a href="/work/a">Project A</a></li></ul></li></ul></nav></header><main><h1>Home</h1></main>',
    'work.html' => '<header><nav><a href="/">Home</a><a href="/work">Work</a><ul><li><a href="/work">Work</a><ul><li><a href="/work/a">Project A</a></li></ul></li></ul></nav></header><main><h1>Work</h1></main>',
    'work/a.html' => '<header><nav><a href="/">Home</a><a href="/work">Work</a><ul><li><a href="/work">Work</a><ul><li><a href="/work/a">Project A</a></li></ul></li></ul></nav></header><main><h1>Project A</h1></main>',
)))->toArray()['source_reports']['wordpress_site_plan'];
$nestedMenus = $entityMenus($nestedPlan);
$assert(1 === count($nestedMenus) && str_contains((string) ($nestedMenus[0]['block_markup'] ?? ''), 'wp:navigation-submenu') && str_contains((string) ($nestedMenus[0]['block_markup'] ?? ''), '"label":"Project A"'), 'Submenus stay inside the shared navigation entity.');

echo "shared-navigation-entity: ok\n";
