<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\WordPressSitePlan;

$assert = static function (bool $condition, string $message): void { if (! $condition) throw new RuntimeException($message); };

// A landing page and two legal pages share one site, but present its chrome
// two ways. The landing header has a call-to-action button where the legal
// header links back home; the landing footer is a full-bleed band after
// <main>, while each legal page closes its <main> with a centred card footer.
// Every route renders through a template referencing one header part and one
// footer part, and no page's content keeps a header or footer of its own.
$brand = '<a class="brand" href="/index.html" aria-label="Acme home"><img class="brand-logo" src="/media/logo.svg" alt="Acme"></a>';
$ui = ':root{--teal:#003435;--gold:#fec340;--cream:#fffbf7}*{box-sizing:border-box;margin:0}body{background:var(--cream);font-family:sans-serif}.nav{padding:12px 0;border-bottom:4px solid var(--gold);background:var(--teal)}.nav-inner{display:flex;justify-content:space-between;align-items:center;max-width:1100px;margin:0 auto;padding:0 24px}.brand-logo{height:36px;display:block}.nav-btn{background:var(--gold);border:0;border-radius:99px;padding:10px 18px;font-weight:700}main section{padding:64px 24px}footer{background:var(--teal);color:#fff;border-top:5px solid var(--gold)}.footer-inner{max-width:1100px;margin:0 auto;padding:32px 24px;display:flex;justify-content:space-between;gap:16px}.footer-links{display:flex;gap:18px}.footer-links a{color:#fff}';
$legal = ':root{--teal:#003435;--gold:#fec340;--cream:#fffbf7}*{box-sizing:border-box;margin:0}body{background:var(--cream);font-family:sans-serif}.nav{padding:12px 0;border-bottom:4px solid var(--gold);background:var(--teal)}.nav-inner{display:flex;justify-content:space-between;align-items:center;max-width:1100px;margin:0 auto;padding:0 24px}.brand-logo{height:36px;display:block}.home-link{color:#fff;font-weight:700}main{max-width:980px;margin:0 auto;padding:64px 32px 80px}article{padding:40px;border:1px solid #ddd;border-top:6px solid var(--gold);border-radius:24px;background:#fff}footer{display:flex;align-items:center;justify-content:center;gap:16px;margin-top:28px;padding:24px 28px;border-radius:18px;background:var(--teal);color:#ddd;text-align:center}footer a{color:#fff;font-weight:800}.legal-mark{width:44px}';
$files = array(
  'website/styles/ui.css' => $ui,
  'website/styles/legal.css' => $legal,
  'website/media/logo.svg' => '<svg xmlns="http://www.w3.org/2000/svg" width="120" height="36"><rect width="120" height="36" fill="#b2d235"/></svg>',
  'website/media/mark.svg' => '<svg xmlns="http://www.w3.org/2000/svg" width="44" height="44"><circle cx="22" cy="22" r="20" fill="#b2d235"/></svg>',
  'website/index.html' => '<!doctype html><html lang="en"><head><meta charset="utf-8"><title>Acme</title><link rel="stylesheet" href="/styles/ui.css"></head><body><header class="nav"><div class="nav-inner">' . $brand . '<button class="nav-btn">Join our waitlist</button></div></header><main><section class="hero"><h1>The ultimate gardening app.</h1><p>Grow anything, anywhere.</p></section><section class="more"><h2>Why Acme</h2><p>Plants love it.</p></section></main><footer><div class="footer-inner"><div class="footer-brand"><img class="footer-mark" src="/media/mark.svg" alt=""> © 2026 Acme. All rights reserved.</div><nav class="footer-links" aria-label="Legal"><a href="/terms/index.html">Terms</a><a href="/privacy/index.html">Privacy</a></nav></div></footer></body></html>',
);
foreach (array('terms' => 'Terms and Conditions', 'privacy' => 'Privacy Policy') as $slug => $title) {
  $files["website/$slug/index.html"] = '<!doctype html><html lang="en"><head><meta charset="utf-8"><title>' . $title . '</title><link rel="stylesheet" href="/styles/legal.css"></head><body><header class="nav"><div class="nav-inner">' . $brand . '<a class="home-link" href="/index.html">Back to home</a></div></header><main><article><h1>' . $title . '</h1><p>These ' . strtolower($title) . ' explain how Acme works.</p><p>Second paragraph for ' . $slug . '.</p></article><footer><img class="legal-mark" src="/media/mark.svg" alt=""><div>© 2026 Acme. All rights reserved. <a href="/terms/index.html">Terms</a> · <a href="/privacy/index.html">Privacy</a></div></footer></main></body></html>';
}

$plan = (new ArtifactCompiler())->compile(array('entrypoint' => 'website/index.html', 'files' => $files))->toArray()['source_reports']['wordpress_site_plan'];
WordPressSitePlan::assertValid($plan);
$parts = array_column($plan['template_parts'], null, 'slug');
$templates = array_column($plan['templates'], 'canonical_block_markup', 'slug');
$pages = array_column($plan['pages'], null, 'source_path');

foreach (array('website/index.html' => 'front-page', 'website/terms/index.html' => 'page', 'website/privacy/index.html' => 'page') as $source => $slug) {
    $markup = (string) ($pages[$source]['canonical_block_markup'] ?? '');
    $assert(!preg_match('/"tagName":"(?:header|footer)"|wp:template-part/', $markup), "{$source} content keeps no header or footer of its own: {$markup}");
    $template = (string) ($templates[$slug] ?? '');
    preg_match_all('/wp:template-part \{"slug":"([^"]+)"/', $template, $referenced);
    $areas = array_map(static fn(string $part): ?string => $parts[$part]['area'] ?? null, $referenced[1]);
    sort($areas);
    $assert(array('footer', 'header') === $areas && 1 === substr_count($template, 'wp:post-content'), "{$source} renders through {$slug}, referencing one header and one footer part around its content: {$template}");
    $assert(1 >= substr_count($template, '<main'), "{$slug} has one main landmark.");
    $own = array_map(static fn(string $part): string => (string) $parts[$part]['canonical_block_markup'], $referenced[1]);
    $assert(str_contains(implode('', $own), 'website/index.html' === $source ? 'Join our waitlist' : 'Back to home'), "{$source} renders its own header copy.");
    $assert(str_contains(implode('', $own), 'website/index.html' === $source ? 'footer-inner' : 'legal-mark'), "{$source} renders its own footer presentation.");
}
// The legal footer closes the legal <main>, so it keeps that position: the
// page template's main holds the content and then the footer part.
$assert(1 === preg_match('/<main\b.*wp:post-content.*"slug":"footer".*<\/main>/s', (string) $templates['page']), 'The legal footer renders inside the page main after the content: ' . $templates['page']);
// Generic fallbacks render the dominant shell once, with one main landmark.
foreach (array('index', 'single', '404') as $slug) {
    $template = (string) ($templates[$slug] ?? '');
    $assert(1 === substr_count($template, '"slug":"header"') && 1 === substr_count($template, '"slug":"footer"') && 1 === substr_count($template, '<main'), "{$slug} renders the shared header and footer once in one main: {$template}");
}
foreach ($plan['template_parts'] as $part) {
    if (!in_array($part['area'], array('header', 'footer'), true)) continue;
    $assert(array() !== array_filter($templates, static fn(string $template): bool => str_contains($template, '"slug":"' . $part['slug'] . '"')), "Part {$part['slug']} is referenced by a template.");
}
echo "route-shell-presentation-variants: ok\n";
