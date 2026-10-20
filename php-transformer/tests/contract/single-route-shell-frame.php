<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\WordPressSitePlan;

$assert = static function (bool $condition, string $message): void { if (!$condition) throw new RuntimeException($message); };

// Reduced fixture from #3172: an app-root wrapper must not make site chrome
// exclusive to the entry route.
$html = <<<'HTML'
<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><title>Shell Probe</title>
<style>body{margin:0;font-family:sans-serif}header{background:#111;color:#fff;padding:16px}header nav a{color:#fff;margin-right:12px}main{padding:32px}footer{background:#222;color:#eee;padding:24px}.min-h-screen{min-height:100vh}</style></head>
<body><div id="root"><div class="min-h-screen"><header><strong>Acme Studio</strong><nav><a href="#services">Services</a><a href="#contact">Contact</a></nav></header><main><section id="services"><h1>Hello there</h1><p>We build things.</p></section><section id="contact"><h2>Contact</h2><p>Write to us.</p></section></main><footer><p>© 2026 Acme Studio</p></footer></div></div></body></html>
HTML;
$plan = (new ArtifactCompiler())->compile(array('entrypoint' => 'shell.html', 'files' => array('shell.html' => $html)))->toArray()['source_reports']['wordpress_site_plan'];
WordPressSitePlan::assertValid($plan);
$templates = array_column($plan['templates'], 'canonical_block_markup', 'slug');
foreach (array('front-page', 'page', 'single', 'index', 'archive', 'search', '404') as $slug) {
    $markup = $templates[$slug] ?? '';
    $assert(1 === substr_count($markup, '"slug":"header"') && 1 === substr_count($markup, '"slug":"footer"'), "{$slug} references the shared header and footer exactly once.");
    $assert(str_contains($markup, 'min-h-screen'), "{$slug} retains the app frame.");
    $contentOffset = strpos($markup, in_array($slug, array('front-page', 'page'), true) ? 'wp:post-content' : '<main');
    $assert(false !== $contentOffset && strpos($markup, '"slug":"header"') < $contentOffset && $contentOffset < strpos($markup, '"slug":"footer"'), "{$slug} places its main content between the shells.");
}
$assert(str_contains($templates['single'], 'wp:post-title'), 'The single template retains its native title.');
foreach (array('index', 'archive', 'search') as $slug) $assert(str_contains($templates[$slug], 'wp:query '), "{$slug} retains its native query.");
$assert(str_contains($templates['404'], 'Page not found'), 'The 404 template retains its native content.');
foreach ($plan['pages'] as $page) {
    $assert(!str_contains($page['canonical_block_markup'], 'wp:template-part') && !str_contains($page['canonical_block_markup'], 'Acme Studio'), 'Entry content holds no shell references or copied chrome.');
    $assert(str_contains($page['canonical_block_markup'], 'We build things.'), 'Entry content is retained.');
}
echo "single-route-shell-frame: ok\n";
