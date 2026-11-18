<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\WordPressSitePlan;

/**
 * Shared footer content spans device-document and plain routes once.
 *
 * Some routes are captured as desktop + mobile documents, others as one plain
 * document, all with the same header, main and footer. The only footer
 * difference is the capture's responsive counterpart class on the device
 * copies. The shared footer copy is factored out of the footer, not the whole
 * footer landmark, and every template renders exactly one footer per viewport
 * (#6507).
 */

$assert = static function (bool $condition, string $message): void { if (!$condition) throw new RuntimeException($message); };

$footer = static function (string $suffix, bool $dual): string {
    $class = $dual ? ' class="data-blocks-engine-capture-responsive-counterpart-e677c99042c7"' : '';
    $column = static fn (string $id, string $text): string => '<div id="' . $id . $suffix . '" class="col ' . $id . '"><p' . $class . '>' . $text . '</p></div>';
    return '<footer id="site-footer' . $suffix . '" class="site-footer">' . $column('f-mail', 'hello@example.test') . $column('f-addr', '1 Example Street') . $column('f-copy', '© 2026 Example. All rights reserved.') . '</footer>';
};
$header = static function (string $suffix, string $current): string {
    $nav = '';
    foreach (array('/' => 'Home', '/about/' => 'About', '/contact/' => 'Contact', '/events/' => 'Events', '/shop/' => 'Shop', '/gallery/' => 'Gallery') as $href => $label) $nav .= '<a href="' . $href . '"' . ($href === $current ? ' class="current"' : '') . '>' . $label . '</a>';
    return '<header id="site-header' . $suffix . '" class="site-header"><a class="logo" href="/">Example</a><nav>' . $nav . '</nav></header>';
};
$frame = static fn (string $suffix, string $current, string $title, bool $dual): string => '<div id="container' . $suffix . '" class="container"><div id="frame' . $suffix . '" class="frame">' . $header($suffix, $current) . '<main id="pages' . $suffix . '"><h1>' . $title . '</h1><p>' . $title . ' body copy.</p></main>' . $footer($suffix, $dual) . '</div></div>';

$css = '.container{min-height:100vh}#frame .site-footer{display:grid;grid-template-columns:1fr 1fr 1fr;background:#222;color:#eee;padding:40px}.site-header{display:flex;gap:16px}.current{font-weight:bold}'
    . '.data-blocks-engine-capture-mobile-document{display:none!important}@media(max-width:767px){.data-blocks-engine-capture-desktop-document{display:none!important}.data-blocks-engine-capture-mobile-document{display:contents!important}}';
$routes = array('/' => array('index.html', 'Home', false), '/about/' => array('about/index.html', 'About', false), '/contact/' => array('contact/index.html', 'Contact', true), '/events/' => array('events/index.html', 'Events', true), '/shop/' => array('shop/index.html', 'Shop', true), '/gallery/' => array('gallery/index.html', 'Gallery', true));
$files = array('site.css' => $css);
foreach ($routes as $href => [$path, $title, $dual]) {
    $body = $dual
        ? '<div class="data-blocks-engine-capture-desktop-document" data-blocks-engine-capture-document-scope="">' . $frame('', $href, $title, true) . '</div><div class="data-blocks-engine-capture-mobile-document" data-blocks-engine-capture-document-scope="">' . $frame('--data-blocks-engine-capture-mobile', $href, $title, true) . '</div>'
        : $frame('', $href, $title, false);
    $files[$path] = '<!doctype html><html><head><meta charset="utf-8"><title>' . $title . '</title><link rel="stylesheet" href="/site.css"></head><body>' . $body . '</body></html>';
}

$plan = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => $files))->toArray()['source_reports']['wordpress_site_plan'];
WordPressSitePlan::assertValid($plan);

$parts = array_column($plan['template_parts'], 'canonical_block_markup', 'slug');
$assert(isset($parts['footer-content']), 'The footer copy shared by every route is factored out.');
$assert(!str_contains($parts['footer-content'], '<footer'), 'Shared footer content holds the copy, not a whole footer landmark.');
$assert(str_contains($parts['footer-content'], 'All rights reserved.'), 'Shared footer content keeps the shared copy.');

$expand = static function (string $markup, int $depth = 0) use (&$expand, $parts): string {
    if ($depth > 4) throw new RuntimeException('Template parts reference each other cyclically.');
    return preg_replace_callback('/<!--\s*wp:template-part\s+(\{[^}]*\})\s*\/-->/', static function (array $match) use ($expand, $parts, $depth): string {
        $slug = (string) (json_decode($match[1], true)['slug'] ?? '');
        return isset($parts[$slug]) ? $expand($parts[$slug], $depth + 1) : $match[0];
    }, $markup) ?? $markup;
};
foreach ($plan['pages'] as $page) $assert(!str_contains($page['canonical_block_markup'], '<footer'), "{$page['source_path']}: the footer leaves page content.");
$slugs = array();
foreach ($plan['templates'] as $template) {
    $slugs[] = $template['slug'];
    $rendered = $expand((string) $template['canonical_block_markup']);
    // One footer per viewport: the desktop (or plain) copy, plus at most its mobile counterpart.
    $assert(1 === substr_count($rendered, '<footer id="site-footer"'), "{$template['slug']}: renders exactly one footer, got " . substr_count($rendered, '<footer id="site-footer"') . '.');
    $assert(substr_count($rendered, '<footer') <= 2 && substr_count($rendered, '<footer') === 1 + substr_count($rendered, '<footer id="site-footer--data-blocks-engine-capture-mobile"'), "{$template['slug']}: no footer nests beside or inside another.");
    $assert(1 + substr_count($rendered, '<footer id="site-footer--data-blocks-engine-capture-mobile"') === substr_count($rendered, 'All rights reserved.'), "{$template['slug']}: each footer carries the shared copy once.");
}
foreach (array('index', 'front-page', 'page-about') as $slug) $assert(in_array($slug, $slugs, true), "$slug template is emitted.");

echo "Footer content mixed device documents contract passed.\n";
