<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\Support\BlockMarkupIndex;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\WordPressSitePlan;

$assert = static function (bool $condition, string $message): void { if (! $condition) throw new RuntimeException($message); };

// Most routes end with the site footer after their content. Three shop
// routes show a second footer design (the same links plus a checkout note),
// each nested in its own page root (`#page-cart`, `#page-checkout`,
// `#page-orders`) and followed by a site-wide marker. The second design is
// one editable part bound by those routes' templates, after the content like
// the main footer. Each page root stays route content, so the routes share
// one template instead of forking one per page root around the same part.
$css = '*{box-sizing:border-box;margin:0}body{font-family:sans-serif}.page{display:grid}.site-footer{background:#123;color:#fff;padding:24px}.site-footer a{color:#fff}.shop-footer{background:#321;color:#fff;padding:16px}.shop-footer a{color:#fff}';
$links = '<nav aria-label="Footer"><a href="/contact/index.html">Contact</a> <a href="/imprint/index.html">Imprint</a></nav>';
$main = '<footer class="site-footer"><p>© 2026 Acme. All rights reserved.</p>' . $links . '</footer>';
$shop = '<footer class="shop-footer"><p>© 2026 Acme. All rights reserved.</p><p>Secure checkout by Acme Pay.</p>' . $links . '</footer>';
$content = static fn(string $title): string => '<section><h1>' . $title . '</h1><p>' . $title . ' body.</p></section>';
$head = static fn(string $title): string => '<!doctype html><html lang="en"><head><meta charset="utf-8"><title>' . $title . '</title><link rel="stylesheet" href="/site.css"></head>';
$plain = static fn(string $title): string => $head($title) . '<body><main>' . $content($title) . '</main>' . $main . '</body></html>';
$framed = static fn(string $title, string $root): string => $head($title) . '<body><div id="site"><div id="' . $root . '" class="page">' . $content($title) . $shop . '</div><div id="scroll-bottom" class="anchor-mark"><p>bottom of page</p></div></div></body></html>';
$files = array('website/site.css' => $css, 'website/index.html' => $plain('Home'));
foreach (array('hats', 'socks', 'shirts', 'about') as $slug) $files["website/$slug/index.html"] = $plain(ucfirst($slug));
$shopRoutes = array('cart' => 'page-cart', 'checkout' => 'page-checkout', 'orders' => 'page-orders');
foreach ($shopRoutes as $slug => $root) $files["website/$slug/index.html"] = $framed(ucfirst($slug), $root);

$plan = (new ArtifactCompiler())->compile(array('entrypoint' => 'website/index.html', 'files' => $files))->toArray()['source_reports']['wordpress_site_plan'];
WordPressSitePlan::assertValid($plan);
$templates = array_column($plan['templates'], 'canonical_block_markup', 'slug');
$pages = array_column($plan['pages'], null, 'source_path');
$footerParts = array_column(array_filter($plan['template_parts'], static fn(array $part): bool => 'footer' === ($part['area'] ?? null)), null, 'slug');
$diagnostics = json_encode(array_column($plan['diagnostics'], 'message'));

// One part per footer design, each bound by templates rather than content.
// Copy both designs share may factor into a part they nest.
$references = array();
foreach ($templates as $slug => $markup) {
    preg_match_all('/<!--\s*wp:template-part\s+\{[^}]*"slug":"([^"]+)"/', $markup, $found);
    $parts = array_values(array_intersect($found[1], array_keys($footerParts)));
    $assert(1 === count($parts), "Template {$slug} binds exactly one footer part: " . json_encode($found[1]) . ' ' . $diagnostics);
    $references[$parts[0]][] = $slug;
}
$assert(2 === count($references), 'Templates bind one footer part per footer design: ' . json_encode($references) . ' ' . $diagnostics);
foreach (array_diff(array_keys($footerParts), array_keys($references)) as $nested) {
    $holders = array_filter(array_keys($references), static fn(string $slug): bool => str_contains((string) $footerParts[$slug]['canonical_block_markup'], '"slug":"' . $nested . '"'));
    $assert(array() !== $holders, "Footer part {$nested} is bound by a template or nested in a bound footer part.");
}
foreach ($pages as $path => $page) {
    $markup = (string) $page['canonical_block_markup'];
    $assert(!str_contains($markup, 'wp:template-part') && !str_contains($markup, '"tagName":"footer"'), "{$path} leaves its footer to its template.");
}

// The shop routes share one template; each page root stays in route content.
$shopTemplates = array();
foreach ($shopRoutes as $slug => $root) {
    $page = $pages["website/$slug/index.html"];
    $shopTemplates[] = $page['route_template'] ?? null;
    $assert(str_contains((string) $page['canonical_block_markup'], '"anchor":"' . $root . '"'), "{$root} stays route content.");
    $owners = array_keys(array_filter($templates, static fn(string $markup): bool => str_contains($markup, '"anchor":"' . $root . '"')));
    $assert(array() === $owners, "No template carries the {$root} page root: " . implode(', ', $owners));
}
$assert(1 === count(array_unique($shopTemplates)) && is_string($shopTemplates[0]) && isset($templates[$shopTemplates[0]]), 'The shop routes render through one template: ' . json_encode($shopTemplates));
$shopFooter = array_values(array_filter(array_keys($footerParts), static fn(string $slug): bool => str_contains((string) $footerParts[$slug]['canonical_block_markup'], 'Secure checkout')));
$assert(1 === count($shopFooter) && array($shopTemplates[0]) === $references[$shopFooter[0]], 'The shop template binds the shop footer: ' . json_encode($references));

// No block subtree repeats across templates, and no two custom templates
// share a structure.
$owners = array();
foreach ($templates as $slug => $markup) {
    $index = new BlockMarkupIndex($markup);
    foreach ($index->ranges() as $range) {
        $chunk = substr($markup, $range['offset'], $range['length']);
        if (preg_match('/^<!--\s*wp:(?:template-part|post-content)\b/', $chunk)) continue;
        $owners[$chunk][$slug] = true;
    }
}
$repeated = array_filter($owners, static fn(array $slugs): bool => count($slugs) > 1);
$assert(array() === $repeated, 'No block markup repeats across templates: ' . json_encode(array_map('array_keys', $repeated)));
$standard = array('index', 'front-page', 'home', 'page', 'single', 'singular', 'archive', 'author', 'category', 'tag', 'taxonomy', 'date', 'search', '404', 'attachment');
$custom = array_diff_key($templates, array_flip($standard));
$assert(count($custom) === count(array_unique($custom)), 'Custom templates have distinct structures: ' . implode(', ', array_keys($custom)));
echo "route-population-footer-template-bound: ok\n";
