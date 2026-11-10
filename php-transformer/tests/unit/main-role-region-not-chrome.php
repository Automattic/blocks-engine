<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};

// Builder pages mark content with `<div role="main">` inside a page wrapper
// whose site child also holds the masthead and footer, followed by layers. The
// region is page content: the header is the masthead, shared by every route.
$page = static function (string $title, string $current, array $layers): string {
    $link = static fn(string $href, string $label): string => '<li><a href="' . $href . '"' . ($current === $href ? ' class="current"' : '') . '>' . $label . '</a></li>';
    return '<html><body><div class="page"><div id="site" class="site"><div class="masthead"><a class="logo" href="/">Brand</a><nav class="menu"><ul>' . $link('/', 'Home') . $link('/about/', 'About') . '</ul></nav></div>'
        . '<div id="first" class="body"><div id="content" class="content" role="main"><h1>' . $title . '</h1><p>Body of ' . $title . '.</p></div></div>'
        . '<div class="colophon"><p>© Brand</p></div></div>'
        . implode('', array_map(static fn(string $layer): string => '<div class="' . $layer . '"><p>' . $layer . ' layer</p></div>', $layers)) . '</div></body></html>';
};
foreach (array('one trailing layer' => array('popup'), 'two trailing layers' => array('lightbox', 'popup')) as $case => $layers) {
    $plan = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array(
        array('path' => 'index.html', 'content' => $page('Home', '/', $layers), 'metadata' => array('route_path' => '/')),
        array('path' => 'about/index.html', 'content' => $page('About', '/about/', $layers), 'metadata' => array('route_path' => '/about')),
    )))->toArray()['source_reports']['wordpress_site_plan'] ?? array();
    $assert(array() !== $plan, $case . ': the routes compile to a site plan.');
    $headers = array_values(array_filter($plan['template_parts'], static fn(array $part): bool => 'header' === $part['area']));
    $assert(1 === count($headers), $case . ': both routes share one header part.');
    $header = (string) $headers[0]['canonical_block_markup'];
    $assert(str_contains($header, '<!-- wp:navigation') && !str_contains($header, 'Body of'), $case . ': the header holds the masthead, not the main region.');
    foreach ($plan['pages'] as $route) {
        $assert(1 === substr_count((string) $route['canonical_block_markup'], 'Body of'), $case . ': each page keeps its own content.');
    }
    foreach ($plan['templates'] as $template) {
        if (in_array($template['slug'], array('index', 'page'), true)) $assert(str_contains((string) $template['canonical_block_markup'], '"slug":"header"'), $case . ': the ' . $template['slug'] . ' template includes the shared header.');
    }
}

echo "Main role region stays page content; the masthead is the shared header\n";
