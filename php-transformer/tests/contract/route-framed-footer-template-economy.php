<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\WordPressSitePlan;

$assert = static function (bool $condition, string $message): void { if (! $condition) throw new RuntimeException($message); };

// Every route nests its footer inside its own page root (`#page-cart`,
// `#page-about`), followed by a site-wide marker. Most routes share one footer
// presentation; two routes show the same copy and links in their own layouts.
// Promoting those two footers to route variant parts would make each route's
// template carry its own page root around the part, so every such route would
// fork a template and repeat the frame markup. They stay route content instead,
// and both routes render through one shared template.
$css = '*{box-sizing:border-box;margin:0}body{font-family:sans-serif}.page{display:grid}.site-footer{background:#123;color:#fff;padding:24px}.site-footer a{color:#fff}.footer-row{display:flex;gap:16px}.footer-stack{display:grid;gap:8px}';
$copy = '<p>© 2026 Acme. All rights reserved.</p><nav aria-label="Footer"><a href="/contact/index.html">Contact</a> <a href="/imprint/index.html">Imprint</a></nav>';
$alt = '<h2 class="legal">© 2026 Acme. All rights reserved.</h2><ul class="links"><li><a href="/contact/index.html">Contact</a></li><li><a href="/imprint/index.html">Imprint</a></li></ul>';
$page = static fn(string $title, string $root, string $footer): string => '<!doctype html><html lang="en"><head><meta charset="utf-8"><title>' . $title . '</title><link rel="stylesheet" href="/site.css"></head><body><div id="site"><div id="' . $root . '" class="page"><section><h1>' . $title . '</h1><p>' . $title . ' body.</p></section><footer class="site-footer">' . $footer . '</footer></div><div id="scroll-bottom" class="anchor-mark"><p>bottom of page</p></div></div></body></html>';
$files = array('website/site.css' => $css);
$files['website/index.html'] = $page('Home', 'page-home', '<div class="footer-inner">' . $copy . '</div>');
foreach (array('hats', 'socks', 'shirts') as $slug) $files["website/$slug/index.html"] = $page(ucfirst($slug), 'page-shop', '<div class="footer-inner">' . $copy . '</div>');
$files['website/cart/index.html'] = $page('Cart', 'page-cart', '<div class="footer-row"><div class="a">' . $alt . '</div></div>');
$files['website/about/index.html'] = $page('About', 'page-about', '<div class="footer-stack"><div class="b"><div class="c">' . $alt . '</div></div></div>');

$plan = (new ArtifactCompiler())->compile(array('entrypoint' => 'website/index.html', 'files' => $files))->toArray()['source_reports']['wordpress_site_plan'];
WordPressSitePlan::assertValid($plan);
$templates = array_column($plan['templates'], 'canonical_block_markup', 'slug');
$pages = array_column($plan['pages'], null, 'source_path');

$footerParts = array_values(array_filter(array_column($plan['template_parts'], 'slug'), static fn(string $slug): bool => str_starts_with($slug, 'footer-')));
$assert(array() === $footerParts, 'No route variant footer part is created for footers inside their own page root: ' . implode(', ', $footerParts));
foreach (array('page-cart', 'page-about') as $root) {
    $owners = array_keys(array_filter($templates, static fn(string $markup): bool => str_contains($markup, '"anchor":"' . $root . '"')));
    $assert(array() === $owners, "No template carries the {$root} page root: " . implode(', ', $owners));
}
$cart = $pages['website/cart/index.html'] ?? array();
$about = $pages['website/about/index.html'] ?? array();
$assert(($cart['route_template'] ?? null) === ($about['route_template'] ?? null), 'Both routes render through one template: ' . json_encode(array($cart['route_template'] ?? null, $about['route_template'] ?? null)));
$assert(str_contains((string) ($cart['canonical_block_markup'] ?? ''), 'footer-row') && str_contains((string) ($about['canonical_block_markup'] ?? ''), 'footer-stack'), 'Each route keeps its own footer presentation.');
foreach (array($cart, $about) as $route) $assert(1 === substr_count((string) $route['canonical_block_markup'], '"tagName":"footer"'), "{$route['source_path']} renders its footer once.");
echo "route-framed-footer-template-economy: ok\n";
