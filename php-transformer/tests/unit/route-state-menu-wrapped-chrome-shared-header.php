<?php
declare(strict_types=1);

/**
 * A menu is the same menu on every route even when one route's builder wraps
 * its chrome a level deeper.
 *
 * Wix nests the home page's header one `div` deeper than the other routes'
 * and marks the served item with `menu__item--selected`, `data-selected` and
 * `aria-current`. The served item's resting state is recorded on every copy of
 * that item, so the home route's copies must be found as peers: otherwise its
 * items carry no resting record, its header compares differently, and the
 * home route gets a header part of its own.
 */

require getenv('BLOCKS_ENGINE_AUTOLOAD') ?: dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};

$routes = array(
    'website/index.html' => array('/index.html', 'Home'),
    'website/about/index.html' => array('/about/index.html', 'About'),
    'website/terms/index.html' => array('/terms/index.html', 'Terms'),
    'website/privacy/index.html' => array('/privacy/index.html', 'Privacy'),
);
$files = array();
foreach ($routes as $path => [$url, $title]) {
    $item = static function (string $href, string $label) use ($url): string {
        $current = $href === $url;
        return '<li class="list-item" data-part="menu-item"><div class="item-wrapper"><div class="item label-container' . ($current ? ' menu__item--selected" data-selected="true" data-part="menu-item-content" data-interactive="false"' : '" data-part="menu-item-content" data-interactive="true"') . '>'
            . '<a data-part="menu-item-link" href="' . $href . '"' . ($current ? ' aria-current="page"' : '') . '><span class="item-label">' . $label . '</span></a></div></div></li>';
    };
    $header = '<header id="site-header" class="site-header"><section class="header-section"><a class="logo" href="/index.html"><img src="/media/logo.png" alt="Studio"></a>'
        . '<nav class="navbar" aria-label="Site"><ul class="menu-list">' . $item('/index.html', 'Home') . $item('/about/index.html', 'About') . $item('/about/index.html#work', 'Work') . '</ul></nav>'
        . '</section></header>';
    // The builder wraps the home route's chrome one level deeper.
    if ('website/index.html' === $path) $header = '<div class="pinned-layer">' . $header . '</div>';
    $files[] = array('path' => $path, 'content' => '<!doctype html><html lang="en"><head><meta charset="utf-8"><title>' . $title . '</title></head><body>'
        . '<div id="site-root">' . $header . '<main><h1>' . $title . '</h1><p>Body text for ' . $title . '.</p></main><footer><p>© 2026 Studio</p></footer></div></body></html>');
}

$result = (new ArtifactCompiler())->compile(array('entrypoint' => 'website/index.html', 'provenance' => array('source_url' => 'https://example.test/'), 'files' => $files));
$shells = array_values(array_filter($result->toArray()['source_reports']['compiled_site']['inline_shell_artifacts'] ?? array(), static fn(array $shell): bool => 'header' === ($shell['area'] ?? null)));
$expected = array_keys($routes);
sort($expected);
$assert(1 === count($shells), 'The routes compile one shared header shell: ' . count($shells));
$covered = $shells[0]['source_paths'] ?? array();
sort($covered);
$assert($expected === $covered, 'The shared header shell covers every route, including the one whose chrome is wrapped deeper: ' . implode(', ', $covered));
$plan = $result->toWordPressSitePlanView()['wordpress_site_plan'];
$headers = array_values(array_filter($plan['template_parts'], static fn(array $part): bool => 'header' === ($part['area'] ?? null)));
$assert(1 === count($headers), 'Every route shares one header part, not one for the route whose chrome is wrapped deeper: ' . implode(', ', array_column($headers, 'slug')));
$assert('header' === $headers[0]['slug'], 'The shared header is the site header part.');
$covered = $headers[0]['placement']['source_paths'] ?? array();
sort($covered);
$assert($expected === $covered, 'The shared header covers every route, including the wrapped one: ' . implode(', ', $covered));
foreach ($plan['templates'] as $template) {
    $assert(!str_contains($template['canonical_block_markup'], '"slug":"header-'), 'No template references a route-variant header: ' . $template['slug']);
}
echo "Route state menu wrapped chrome shared header: passed\n";
