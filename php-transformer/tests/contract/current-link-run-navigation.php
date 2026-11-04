<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

$assert = static function (bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); };
$labels = array('about' => 'About', 'services' => 'Services', 'work' => 'Work', 'contact' => 'Contact');
$run = static function (string $current, bool $signal = true) use ($labels): string {
    $html = '<div class="link-row">';
    foreach ($labels as $slug => $label) {
        $html .= '<a class="entry-link' . ($signal && $slug === $current ? ' current' : '') . '" href="/' . $slug . '/"' . ($signal && $slug === $current ? ' aria-current="page"' : '') . '><p' . ('contact' === $slug ? ' class="label-accent"' : '') . '>' . $label . '</p></a>';
    }
    return $html . '</div>';
};
$files = array();
foreach (array('about', 'services', 'work') as $slug) {
    $files[$slug . '.html'] = '<!doctype html><html><head><style>.link-row{display:flex;gap:20px}.entry-link{color:#234}.entry-link p{margin:0}.label-accent{font-weight:700}</style></head><body><div class="top-frame">' . $run($slug) . '</div><main><h1>' . $labels[$slug] . '</h1><p>Route content.</p></main></body></html>';
}
$simpleFiles = $files;
$realLabels = array('about' => 'About', 'services' => 'Services', 'work' => 'Work', 'news' => 'News', 'contact' => 'Contact');
$realFiles = array();
foreach (array('about', 'services', 'work') as $slug) {
    $links = '<div class="dismiss-row"><div class="dismiss-control"><img src="close.svg"><p class="label-accent">Close</p></div></div>';
    foreach ($realLabels as $destination => $label) {
        $links .= '<a href="/' . $destination . '/" class="entry-link' . ($slug === $destination ? ' current' : '') . '"' . ($slug === $destination ? ' aria-current="page"' : '') . '><p' . ('contact' === $destination ? ' class="label-accent"' : '') . '>' . $label . '</p></a>';
    }
    $realFiles[$slug . '.html'] = '<!doctype html><html><head></head><body><div class="site-style"><div class="resource-embed"><link rel="stylesheet" href="shared.css"></div></div><div class="resource-embed"><style>.entry-link{color:#234}.label-accent{font-weight:700}</style><script>/* resource only */</script></div><div></div><div class="page-frame"><div class="top-frame"><div class="top-inner"><div class="brand-slot"><a href="/index.html"><img src="logo.svg"></a></div><div class="action-slot"><div class="page-shade"></div><div class="link-row wide">' . $links . '</div><div class="link-row narrow">' . $links . '</div><div class="locale-picker"><img><a href="/about/"' . ('about' === $slug ? ' aria-current="page" class="locale-link current"' : ' class="locale-link"') . '>English</a><div class="divider"></div><a href="/fr/about/" class="locale-link">Français</a></div><a href="/about/"' . ('about' === $slug ? ' aria-current="page" class="action-link current"' : ' class="action-link"') . '><p class="label-accent"><span>Learn more about us.</span></p></a><div class="compact-control"><p>Menu</p><img></div></div></div></div><div class="page-section"><h1>' . $realLabels[$slug] . '</h1><p>Route content.</p></div></div></body></html>';
}
foreach (array('simple' => $simpleFiles, 'controls and responsive copy' => $realFiles) as $fixture => $files) {
$labels = 'simple' === $fixture ? $labels : $realLabels;
$plan = (new ArtifactCompiler())->compile(array('entrypoint' => 'about.html', 'files' => $files))->toArray()['source_reports']['wordpress_site_plan'];
$headers = array_values(array_filter($plan['template_parts'] ?? array(), static fn(array $part): bool => 'header' === $part['area']));
$assert(1 === count($headers), 'Moving current state must not fork the header.');
$header = $headers[0]['canonical_block_markup'];
$assert(str_contains($header, '<!-- wp:navigation'), 'The shared header contains native navigation.');
$assert(1 === count($plan['menus']), 'All routes share one navigation entity.');
if ('simple' !== $fixture) {
    $assert(2 === substr_count($header, '<!-- wp:navigation '), 'Both responsive occurrences survive while sharing one entity.');
    $assert(str_contains($header, 'locale-link') && str_contains($header, 'action-link'), 'Language links and the CTA remain outside the menu.');
}
$menu = $plan['menus'][0];
$assert(count($labels) === $menu['items'], $fixture . ': the navigation entity contains exactly the source run.');
preg_match_all('/<!-- wp:navigation-link (\{.*?\})\s*\/?-->/', $menu['block_markup'], $matches);
$items = array_map(static fn(string $json): array => json_decode($json, true, flags: JSON_THROW_ON_ERROR), $matches[1]);
$assert(array_values($labels) === array_map('strip_tags', array_column($items, 'label')), 'Labels retain source order.');
$expectedUrls = array('/about', '/services', '/work');
if ('simple' !== $fixture) $expectedUrls[] = '/news/';
$expectedUrls[] = '/contact/';
$assert($expectedUrls === array_column($items, 'url'), 'Destinations retain source order.');
$assert(str_contains($menu['block_markup'], 'entry-link') && str_contains($menu['block_markup'], 'label-accent'), 'Author anchor and label classes survive projection.');
$assert(!str_contains($menu['block_markup'] . $header, 'aria-current') && !str_contains($menu['block_markup'], ' current'), 'No route selection is frozen in the shared menu.');
foreach ($plan['pages'] as $page) $assert(!str_contains($page['canonical_block_markup'], 'top-frame'), 'No route retains a private header.');
}

foreach (array('no current signal' => $run('about', false), 'content-local run' => '<main>' . $run('about') . '</main>', 'after real content' => '<div><p>Article content.</p></div>' . $run('about'), 'after media' => '<div><img src="photo.svg"></div>' . $run('about'), 'nested link-bearing sibling' => str_replace('<div class="link-row">', '<div class="link-row"><div><a href="/extra/">Extra</a></div>', $run('about')), 'header-shaped run after content' => '<p>Article content.</p><div><a href="/index.html"><img src="logo.svg"></a>' . $run('about') . '</div><main><p>More content.</p></main>', 'too few links' => '<div><a href="/a" aria-current="page"><p>A</p></a><a href="/b"><p>B</p></a></div>', 'external destination' => str_replace('/contact/', 'https://elsewhere.test/contact/', $run('about')), 'multiple labels' => str_replace('<p>About</p>', '<p>About</p><p>More</p>', $run('about'))) as $case => $html) {
    $markup = (new HtmlTransformer())->transform('<!doctype html><html><body>' . $html . '</body></html>')->serializedBlocks;
    $assert(!str_contains($markup, '<!-- wp:navigation'), $case . ' retains ordinary lowering.');
}
echo "Current link run navigation contract passed.\n";
