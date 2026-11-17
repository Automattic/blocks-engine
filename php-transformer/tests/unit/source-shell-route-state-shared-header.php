<?php
declare(strict_types=1);

/**
 * A Squarespace header captured on every route is one shared header.
 *
 * Each route's copy differs only by what the capture recorded about that
 * visit: YUI stamps an element with an id minted per page load, the served
 * item and the folder holding it carry `active`, and the home link reads
 * `collection homepage` everywhere but home. None of that is shell identity,
 * so no route keeps its own copy and every template uses the part.
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
$load = 1794936403308;
foreach ($routes as $path => [$url, $title]) {
    $item = static fn(string $href, string $label, string $class = 'collection'): string => '<div class="' . $class . ($href === $url ? ' active' : '') . '"><a href="' . $href . '">' . $label . '</a></div>';
    $folder = in_array($url, array('/what-we-do', '/projects'), true);
    $header = '<header id="header" role="banner"><div class="header-inner"><h1 id="logo"><a href="/">Bedford</a></h1>'
        . '<nav id="mainNavigation">'
        . '<div class="user-account-link" id="yui_3_17_2_1_' . ($load += 4511) . '_46"><a href="#"><span class="unauth">Sign In</span></a></div>'
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
$assert(1 === count($shells), 'The routes compile one shared header shell, not one per route state.');
$covered = $shells[0]['source_paths'] ?? array();
sort($covered);
$expected = array_keys($routes);
sort($expected);
$assert($expected === $covered, 'The shared header shell covers every route.');
$assert(!str_contains($shells[0]['block_markup'] ?? '', 'yui_3_17'), 'A per-load YUI id is not shell markup.');
$plan = $result->toWordPressSitePlanView()['wordpress_site_plan'];
$headers = array_values(array_filter($plan['template_parts'], static fn(array $part): bool => 'header' === ($part['area'] ?? null)));
$assert(1 === count($headers) && 'header' === $headers[0]['slug'], 'One shared header part serves every route.');
$assert(str_contains($headers[0]['canonical_block_markup'], '"anchor":"mainNavigation"'), 'The shared header keeps the menu.');
foreach ($plan['pages'] as $page) {
    $assert(!str_contains($page['canonical_block_markup'], 'mainNavigation') && !str_contains($page['canonical_block_markup'], 'Sign In'), 'Route content does not keep its own header: ' . $page['source_path']);
}
foreach ($plan['templates'] as $template) {
    $assert(str_contains($template['canonical_block_markup'], '"slug":"header"'), 'Template references the shared header: ' . $template['slug']);
}
echo "Source shell route state shared header: passed\n";
