<?php
declare(strict_types=1);

/**
 * A header whose menu marks the served item with a class token is one shared
 * header on every route.
 *
 * Squarespace marks the served item, and the folder holding it, with
 * `active` beside the item's own classes (`collection homepage`, `folder`),
 * and one route's capture can tag a link with a device-island class. Only the
 * current-state token and the device-island class are route evidence, so every
 * route compares as the same header, and the part is seeded from the entry
 * route rather than from the route whose capture carried the island class.
 */

require getenv('BLOCKS_ENGINE_AUTOLOAD') ?: dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};

$routes = array(
    'website/index.html' => array('/', 'Home'),
    'website/what-we-do/index.html' => array('/what-we-do', 'What We Do'),
    'website/projects/index.html' => array('/projects', 'Projects'),
    'website/news/index.html' => array('/news', 'News'),
    'website/contact/index.html' => array('/contact', 'Contact'),
);
$files = array();
foreach ($routes as $path => [$url, $title]) {
    $item = static fn(string $href, string $label, string $class = 'collection'): string => '<div class="' . $class . ($href === $url ? ' active' : '') . '"><a href="' . $href . '">' . $label . '</a></div>';
    $folder = in_array($url, array('/what-we-do', '/projects'), true);
    $island = '/contact' === $url ? ' data-blocks-engine-capture-desktop-only' : '';
    $header = '<header id="header" role="banner"><div class="header-inner"><h1 id="logoImage"><a href="/"><img src="/media/logo.png" alt="Bedford"></a></h1>'
        . '<nav id="mainNavigation">'
        . '<div class="user-account-link"><a href="#"' . ('' === $island ? '' : ' class="' . trim($island) . '"') . '><span class="unauth' . $island . '">Sign In</span></a></div>'
        . $item('/', 'Home', 'collection homepage')
        . '<div class="folder' . ($folder ? ' active' : '') . '"><div class="folder-toggle" data-href="/about">About</div><div class="subnav">'
        . $item('/what-we-do', 'What We Do') . $item('/projects', 'Projects')
        . '</div></div>'
        . $item('/news', 'News') . $item('/contact', 'Contact')
        . '</nav></div></header>';
    $files[] = array('path' => $path, 'content' => '<!doctype html><html lang="en"><head><meta charset="utf-8"><title>' . $title . '</title></head><body>'
        . '<div id="siteWrapper">' . $header . '<main><h1>' . $title . '</h1><p>Body text for ' . $title . '.</p></main><footer><p>© 2026 Bedford</p></footer></div></body></html>');
}

$result = (new ArtifactCompiler())->compile(array('entrypoint' => 'website/index.html', 'provenance' => array('source_url' => 'https://example.test/'), 'files' => $files));
$shells = array_values(array_filter($result->toArray()['source_reports']['compiled_site']['inline_shell_artifacts'] ?? array(), static fn(array $shell): bool => 'header' === ($shell['area'] ?? null)));
$assert(1 === count($shells), 'The routes compile one shared header shell, not one per served item.');
$covered = $shells[0]['source_paths'] ?? array();
sort($covered);
$expected = array_keys($routes);
sort($expected);
$assert($expected === $covered, 'The shared header shell covers every route.');
$assert('website/index.html' === ($shells[0]['placement']['evidence_source_path'] ?? null), 'The entry route seeds the shared header, not the route whose capture tagged a device island.');
$plan = $result->toWordPressSitePlanView()['wordpress_site_plan'];
$headers = array_values(array_filter($plan['template_parts'], static fn(array $part): bool => 'header' === ($part['area'] ?? null)));
$assert(1 === count($headers) && 'header' === $headers[0]['slug'], 'One shared header part serves every route.');
$assert(str_contains($headers[0]['canonical_block_markup'], '"anchor":"mainNavigation"'), 'The shared header keeps the menu.');
$menus = implode('', array_map(static fn(array $menu): string => (string) ($menu['block_markup'] ?? ''), $plan['menus']));
$assert(!str_contains($headers[0]['canonical_block_markup'] . $menus, 'data-blocks-engine-capture-desktop-only'), 'One route\'s device-island class does not reach the shared header.');
$assert(str_contains($menus, 'wp:navigation-submenu') && str_contains($menus, 'folder'), 'The folder keeps its own class and stays a submenu.');
foreach ($plan['pages'] as $page) {
    $assert(!str_contains($page['canonical_block_markup'], 'mainNavigation') && !str_contains($page['canonical_block_markup'], 'Sign In'), 'Route content does not keep its own header: ' . $page['source_path']);
}
foreach ($plan['templates'] as $template) {
    $assert(str_contains($template['canonical_block_markup'], '"slug":"header"'), 'Template references the shared header: ' . $template['slug']);
}
echo "Source shell class-marked current shared header: passed\n";
