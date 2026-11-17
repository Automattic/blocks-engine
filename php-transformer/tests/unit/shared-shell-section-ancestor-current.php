<?php
declare(strict_types=1);

/**
 * A section's descendant routes (/shop/audio/ under /shop/) mark the section
 * link current in the source, though the link does not name the route. The
 * navigation runtime marks that longest-ancestor item current on the served
 * route, so the section state is route state like an exact current item: the
 * routes share one header part instead of each descendant keeping its own.
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

$assert = static function (bool $condition, string $message): void { if (!$condition) throw new RuntimeException($message); };

$css = '<style>:root{--muted:#777;--fg:#111;--primary:#c60}a{color:inherit;text-decoration:inherit}.nav{display:flex;gap:2rem;color:var(--muted)}@media (hover:hover){.link:hover{color:var(--fg)}}.text-primary{color:var(--primary)}</style>';
$page = static function (string $current, string $body) use ($css): string {
    $link = static fn (string $key, string $href, string $label): string => '<a class="link' . ($key === $current ? ' text-primary" aria-current="page' : '') . '" href="' . $href . '">' . $label . '</a>';
    return '<!doctype html><html lang="en"><head><title>' . $body . '</title>' . $css . '</head><body><header class="site-header"><div class="bar"><a class="brand" href="/">Atelier</a><nav class="nav">'
        . $link('shop', '/shop', 'Shop') . $link('about', '/about', 'About') . $link('help', '/help', 'Help')
        . '</nav><a class="cart" href="/cart">Cart <span class="text-primary">(0)</span></a></div></header><main><h1>' . $body . '</h1><p>' . $body . ' copy for this route.</p></main>'
        . '<footer class="site-footer"><p>Atelier footer</p></footer></body></html>';
};
$files = array(
    'index.html' => $page('', 'Home'),
    'shop/index.html' => $page('shop', 'Shop'),
    'shop/audio/index.html' => $page('shop', 'Audio'),
    'shop/care/index.html' => $page('shop', 'Care'),
    'about/index.html' => $page('about', 'About'),
    'help/index.html' => $page('help', 'Help'),
);
$plan = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => $files))->toWordPressSitePlanView()['wordpress_site_plan'];

$headers = array_values(array_filter($plan['template_parts'], static fn (array $part): bool => 'header' === ($part['area'] ?? null)));
$assert(1 === count($headers), 'Section descendants share the one header part: ' . json_encode(array_column($headers, 'slug')));
foreach ($plan['templates'] as $template) {
    if (!str_contains($template['canonical_block_markup'], '"area":"header"')) continue;
    $assert(str_contains($template['canonical_block_markup'], '"slug":"' . $headers[0]['slug'] . '"'), 'Template ' . $template['slug'] . ' renders the shared header.');
}
foreach ($plan['pages'] as $document) {
    $assert(!str_contains($document['canonical_block_markup'], 'wp:navigation'), 'Route ' . $document['source_path'] . ' keeps no header copy of its own.');
}
$menus = implode('', array_column($plan['menus'], 'block_markup')) . $headers[0]['canonical_block_markup'];
preg_match_all('/<!-- wp:navigation-link (\{.*?\}) \/-->/s', $menus, $links);
$assert(array() !== $links[1], 'The shared header renders its menu items.');
foreach ($links[1] as $json) $assert(!str_contains((string) (json_decode($json, true)['className'] ?? ''), 'text-primary'), 'The shared header does not freeze a route\'s section state: ' . $json);
echo "shared-shell-section-ancestor-current: ok\n";
