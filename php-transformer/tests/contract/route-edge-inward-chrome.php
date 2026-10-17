<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\WordPressSitePlan;

$assert = static function (bool $condition, string $message): void {
    if (! $condition) {
        throw new RuntimeException($message);
    }
};

// Every route opens with the header and then the same mobile menu drawer,
// ahead of the page's own title band and <main>. The drawer repeats on every
// route, so it is chrome: it moves into the shared header part, after the
// header as in the source. The title band differs per route and stays page
// content.
$routes = array('index' => 'Home', 'about' => 'About', 'visit' => 'Visit');
$page = static function (string $current) use ($routes): string {
    $links = '';
    foreach ($routes as $slug => $label) $links .= '<li><a href="' . $slug . '.html"' . ($slug === $current ? ' class="active"' : '') . '>' . $label . '</a></li>';
    return '<!doctype html><html><head><title>' . $routes[$current] . '</title><style>.masthead{position:sticky;top:0}.drawer{position:fixed;inset:0;transform:translateX(100%)}.drawer.is-open{transform:none}</style></head><body>'
        . '<header class="masthead"><a href="index.html">Harbor Parish</a><nav><ul>' . $links . '</ul></nav><button class="drawer-toggle" aria-label="Open menu">Menu</button></header>'
        . '<div class="drawer" role="dialog" aria-label="Navigation menu"><nav><ul class="drawer-links">' . $links . '</ul></nav><p class="drawer-note">Open daily 9–5</p></div>'
        . '<section class="page-title"><h1>' . $routes[$current] . ' title</h1></section>'
        . '<main><p>' . $routes[$current] . ' body copy.</p></main>'
        . '<footer class="colophon"><p>1 Shore Road</p></footer></body></html>';
};
$files = array();
foreach (array_keys($routes) as $slug) $files[$slug . '.html'] = $page($slug);
$plan = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => $files))->toArray()['source_reports']['wordpress_site_plan'];
WordPressSitePlan::assertValid($plan);

$headers = array_values(array_filter($plan['template_parts'], static fn (array $part): bool => 'header' === ($part['area'] ?? null)));
$assert(1 === count($headers), 'One shared header part: ' . json_encode(array_column($plan['template_parts'], 'slug')));
$header = (string) ($headers[0]['canonical_block_markup'] ?? '');
$assert(str_contains($header, 'masthead') && str_contains($header, 'Open daily 9–5'), 'The header part holds the header and the drawer that follows it on every route.');
$assert(strpos($header, 'masthead') < strpos($header, 'Open daily 9–5'), 'The drawer stays after the header, in source order.');
$assert(! str_contains($header, 'title</h1>'), 'The title band is not chrome.');
foreach ($plan['pages'] as $document) {
    $markup = (string) $document['canonical_block_markup'];
    $assert(! str_contains($markup, 'Open daily 9–5') && ! str_contains($markup, 'drawer-links'), $document['source_path'] . ' keeps no copy of the drawer.');
    $assert(str_contains($markup, 'title</h1>') && str_contains($markup, 'body copy.'), $document['source_path'] . ' keeps its title band and content.');
}
