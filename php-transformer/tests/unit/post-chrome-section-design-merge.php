<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

// #6874: posts of one section share one design even when their hero chrome
// varies, so they share one single template instead of one per variant.
$files = array('index.html' => '<!doctype html><html><head><title>Home</title></head><body><h1>Home</h1><p>Welcome.</p></body></html>');
$posts = array('alpha' => true, 'beta' => true, 'gamma' => false, 'delta' => false);
foreach ($posts as $slug => $overlay) {
    $title = ucfirst($slug) . ' event';
    $date = '2024-01-1' . strlen($slug);
    $heading = '<h1 class="post-title">' . $title . '</h1>';
    $hero = '<section class="hero">' . ($overlay ? '<div class="overlay">' . $heading . '</div>' : $heading) . '</section>';
    $files['events/' . $slug . '/index.html'] = '<!doctype html><html><head><title>' . $title . '</title><script type="application/ld+json">{"@context":"https://schema.org","@type":"BlogPosting","headline":"' . $title . '","datePublished":"' . $date . '"}</script></head><body><header class="site-header"><p>Shared header</p></header><main class="site-main"><article class="post">' . $hero . '<p class="post-date"><time datetime="' . $date . '">' . $date . '</time></p><div class="post-body"><p>' . $title . ' opening paragraph.</p><p>' . $title . ' closing paragraph.</p></div><div class="contact"><h3>Contact us</h3><p>Shared contact banner.</p></div></article></main><footer class="site-footer"><p>Footer text</p></footer></body></html>';
}
$plan = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => $files))->toArray()['source_reports']['wordpress_site_plan'];
$failures = array();
$templates = array_values(array_filter($plan['templates'], static fn(array $entry): bool => str_starts_with($entry['slug'], 'single')));
if (array('single') !== array_column($templates, 'slug')) $failures[] = 'one shared single template, got ' . implode(', ', array_column($templates, 'slug'));
$template = $templates[0]['canonical_block_markup'] ?? '';
foreach (array('<!-- wp:post-content', 'Shared contact banner.') as $needle) {
    if (!str_contains($template, $needle)) $failures[] = 'template keeps ' . $needle;
}
foreach ($plan['pages'] as $page) {
    if ('post' !== ($page['post_type'] ?? null)) continue;
    $slug = basename(dirname($page['source_path']));
    $content = $page['canonical_block_markup'];
    if ('single' !== ($page['route_template'] ?? 'single')) $failures[] = $slug . ' uses single';
    if (!str_contains($content, ucfirst($slug) . ' event opening paragraph.')) $failures[] = $slug . ' keeps its body';
    if ($posts[$slug] !== str_contains($content, 'overlay')) $failures[] = $slug . ' keeps its own hero variant';
    foreach (array('Shared contact banner.', 'Shared header', 'Footer text') as $excluded) {
        if (str_contains($content, $excluded)) $failures[] = $slug . ' post_content excludes ' . $excluded;
    }
}
if (array() !== $failures) {
    fwrite(STDERR, 'FAIL: ' . implode("\nFAIL: ", $failures) . "\n");
    exit(1);
}
echo "post chrome section design merge: passed\n";
