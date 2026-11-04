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
$plan = (new ArtifactCompiler())->compile(array('entrypoint' => 'about.html', 'files' => $files))->toArray()['source_reports']['wordpress_site_plan'];
$headers = array_values(array_filter($plan['template_parts'] ?? array(), static fn(array $part): bool => 'header' === $part['area']));
$assert(1 === count($headers), 'Moving current state must not fork the header.');
$header = $headers[0]['canonical_block_markup'];
$assert(str_contains($header, '<!-- wp:navigation'), 'The shared header contains native navigation.');
$assert(1 === count($plan['menus']), 'All routes share one navigation entity.');
$menu = $plan['menus'][0];
$assert(4 === $menu['items'], 'The navigation entity contains four links.');
preg_match_all('/<!-- wp:navigation-link (\{.*?\})\s*\/?-->/', $menu['block_markup'], $matches);
$items = array_map(static fn(string $json): array => json_decode($json, true, flags: JSON_THROW_ON_ERROR), $matches[1]);
$assert(array_values($labels) === array_map('strip_tags', array_column($items, 'label')), 'Labels retain source order.');
$assert(array('/about', '/services', '/work', '/contact/') === array_column($items, 'url'), 'Destinations retain source order.');
$assert(str_contains($menu['block_markup'], 'entry-link') && str_contains($menu['block_markup'], 'label-accent'), 'Author anchor and label classes survive projection.');
$assert(!str_contains($menu['block_markup'] . $header, 'aria-current') && !str_contains($menu['block_markup'], ' current'), 'No route selection is frozen in the shared menu.');
foreach ($plan['pages'] as $page) $assert(!str_contains($page['canonical_block_markup'], 'top-frame'), 'No route retains a private header.');

foreach (array('no current signal' => $run('about', false), 'content-local run' => '<main>' . $run('about') . '</main>', 'too few links' => '<div><a href="/a" aria-current="page"><p>A</p></a><a href="/b"><p>B</p></a></div>', 'external destination' => str_replace('/contact/', 'https://elsewhere.test/contact/', $run('about')), 'multiple labels' => str_replace('<p>About</p>', '<p>About</p><p>More</p>', $run('about'))) as $case => $html) {
    $markup = (new HtmlTransformer())->transform('<!doctype html><html><body>' . $html . '</body></html>')->serializedBlocks;
    $assert(!str_contains($markup, '<!-- wp:navigation'), $case . ' retains ordinary lowering.');
}
echo "Current link run navigation contract passed.\n";
