<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\WordPressSitePlan;

$assert = static function (bool $condition, string $message): void { if (!$condition) throw new RuntimeException($message); };

// A builder serves some routes as a desktop and a mobile document whose
// headers differ by device, and others as one document with a single
// header holding a newsletter form (a runtime-bound control). Each kind repeats
// one design, so the site has two header parts, like its footers: one for
// the device-document routes, one bound by the single-document routes'
// templates, carrying the form binding once.
$css = '*{box-sizing:border-box}body{margin:0;font-family:sans-serif}.site-header{padding:16px;border-bottom:1px solid #ddd}.site-header a{margin-right:16px;color:#222}.menu-button{border:0;background:none}.site-footer{border-top:1px solid #ddd;padding:16px}.newsletter{display:inline-flex;gap:4px}';
$nav = '<nav aria-label="Main"><a href="/">Home</a> <a href="/shop/">Shop</a> <a href="/about/">About</a> <a href="/contact/">Contact</a></nav>';
$footer = static fn(string $suffix): string => '<footer id="SITE_FOOTER' . $suffix . '" class="site-footer"><p>© Example Studio</p></footer>';
$head = static fn(string $title): string => '<!doctype html><html lang="en"><head><meta charset="utf-8"><title>' . $title . '</title><link rel="stylesheet" href="/site.css">'
    . '<style data-blocks-engine-capture-device-visibility="">[data-blocks-engine-capture-device-document]{display:none!important}html:not([data-blocks-engine-capture-selected-document]) [data-blocks-engine-capture-device-document="desktop"]{display:contents!important}html[data-blocks-engine-capture-selected-document="desktop"] [data-blocks-engine-capture-device-document="desktop"]{display:contents!important}html[data-blocks-engine-capture-selected-document="mobile"] [data-blocks-engine-capture-device-document="mobile"]{display:contents!important}</style></head>';
$content = static fn(string $title, string $suffix = ''): string => '<main id="main' . $suffix . '"><h1>' . $title . '</h1><p>' . $title . ' body copy.</p></main>';
$devices = static fn(string $title): string => $head($title) . '<body>'
    . '<div class="data-blocks-engine-capture-desktop-document" data-blocks-engine-capture-device-document="desktop" data-blocks-engine-capture-document-scope=""><header id="SITE_HEADER" class="site-header"><a href="/">Example Studio</a>' . $nav . '</header>' . $content($title) . $footer('') . '</div>'
    . '<div class="data-blocks-engine-capture-mobile-document" data-blocks-engine-capture-device-document="mobile" data-blocks-engine-capture-document-scope=""><header id="SITE_HEADER--m" class="site-header"><a href="/">Example Studio</a><p class="menu-button">Tap the menu</p></header>' . $content($title, '--m') . $footer('--m') . '</div>'
    . '</body></html>';
$single = static fn(string $title): string => $head($title) . '<body><header id="SITE_HEADER" class="site-header"><a href="/">Example Studio</a>' . $nav
    . '<form method="post" class="newsletter"><label>Email <input type="email" name="email"></label><button type="submit">Join</button></form></header>' . $content($title) . $footer('') . '</body></html>';
$files = array('website/site.css' => $css, 'website/index.html' => $single('Home'));
foreach (array('about', 'contact') as $slug) $files["website/$slug/index.html"] = $single(ucfirst($slug));
foreach (array('shop', 'hats', 'socks', 'shirts') as $slug) $files["website/$slug/index.html"] = $devices(ucfirst($slug));

$plan = (new ArtifactCompiler())->compile(array('entrypoint' => 'website/index.html', 'files' => $files))->toArray()['source_reports']['wordpress_site_plan'];
WordPressSitePlan::assertValid($plan);
$diagnostics = json_encode(array_values(array_filter(array_column($plan['diagnostics'], 'message'), static fn(string $message): bool => str_contains($message, 'header'))));
$headers = array_column(array_filter($plan['template_parts'], static fn(array $part): bool => 'header' === ($part['area'] ?? null)), null, 'slug');
$assert(2 === count($headers), 'One header part per header design: ' . json_encode(array_keys($headers)) . ' ' . $diagnostics);
foreach ($headers as $slug => $part) $assert('shared_shell' === ($part['placement']['kind'] ?? null), "Header part {$slug} is bound by templates.");
$formPart = array_values(array_filter(array_keys($headers), static fn(string $slug): bool => str_contains((string) $headers[$slug]['canonical_block_markup'], 'Join')));
$assert(1 === count($formPart), 'Exactly one header part carries the newsletter form: ' . json_encode($formPart));
$assert(in_array('front-page', $headers[$formPart[0]]['placement']['template_slugs'] ?? array(), true), 'The single-document header renders on the front page: ' . json_encode($headers[$formPart[0]]['placement']));
$devicePart = array_values(array_diff(array_keys($headers), $formPart))[0];
$assert(str_contains((string) $headers[$devicePart]['canonical_block_markup'], 'Tap the menu'), 'The device-document header part keeps the mobile header.');

// Every template binds exactly one header part, and no route keeps its own.
foreach ($plan['templates'] as $template) {
    preg_match_all('/<!--\s*wp:template-part\s+\{[^}]*"slug":"([^"]+)"/', (string) $template['canonical_block_markup'], $found);
    $bound = array_values(array_intersect($found[1], array_keys($headers)));
    $assert(1 === count($bound), "Template {$template['slug']} binds one header part: " . json_encode($found[1]));
}
foreach ($plan['pages'] as $page) $assert(!str_contains((string) $page['canonical_block_markup'], 'Example Studio'), "{$page['source_path']} leaves its header to its template.");

// The form binding moved with the part: one bound form entity, anchored on it.
$bindings = array();
foreach ($plan['runtime_declarations'] as $declaration) {
    if ('forms' !== ($declaration['type'] ?? null)) continue;
    foreach ($declaration['payload']['entities'] ?? array() as $entity) foreach ($entity['bindings'] ?? array() as $binding) $bindings[] = (string) ($binding['source_path'] ?? '');
}
$assert(array($headers[$formPart[0]]['source_path']) === array_values(array_unique($bindings)) && 1 === count($bindings), 'The header form is bound once, on its part: ' . json_encode($bindings));

echo "header-parts-device-documents-and-single-documents: ok\n";
