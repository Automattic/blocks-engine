<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

// #6874: posts of one section share one design even when their hero chrome
// varies, so they share one single template instead of one per variant. Each
// post keeps its own hero image, not one layout member's.
// A section whose posts all share one layout (differing only in hero image)
// and a section whose hero chrome varies take the same path.
$failures = array();
$cases = array(
    'variant heroes' => array('alpha' => true, 'beta' => true, 'gamma' => false, 'delta' => false),
    'one layout' => array('alpha' => true, 'beta' => true, 'gamma' => true),
);
foreach ($cases as $case => $posts) {
    $files = array('index.html' => '<!doctype html><html><head><title>Home</title></head><body><h1>Home</h1><p>Welcome.</p></body></html>');
    foreach ($posts as $slug => $overlay) {
        $title = ucfirst($slug) . ' event';
        $date = '2024-01-1' . strlen($slug);
        $heading = '<h1 class="post-title">' . $title . '</h1>';
        $files['media/' . $slug . '.png'] = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==') . $slug;
        $hero = '<section class="hero" style="background-image:url(/media/' . $slug . '.png)">' . ($overlay ? '<div class="overlay">' . $heading . '</div>' : $heading) . '</section>';
        $files['events/' . $slug . '/index.html'] = '<!doctype html><html><head><title>' . $title . '</title><script type="application/ld+json">{"@context":"https://schema.org","@type":"BlogPosting","headline":"' . $title . '","datePublished":"' . $date . '"}</script></head><body><header class="site-header"><p>Shared header</p></header><main class="site-main"><article class="post">' . $hero . '<p class="post-date"><time datetime="' . $date . '">' . $date . '</time></p><div class="post-body"><p>' . $title . ' opening paragraph.</p><p>' . $title . ' closing paragraph.</p></div><div class="contact"><h3>Contact us</h3><p>Shared contact banner.</p></div></article></main><footer class="site-footer"><p>Footer text</p></footer></body></html>';
    }
    $plan = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => $files))->toArray()['source_reports']['wordpress_site_plan'];
    $templates = array_values(array_filter($plan['templates'], static fn(array $entry): bool => str_starts_with($entry['slug'], 'single')));
    if (array('single') !== array_column($templates, 'slug')) $failures[] = $case . ': one shared single template, got ' . implode(', ', array_column($templates, 'slug'));
    $template = $templates[0]['canonical_block_markup'] ?? '';
    foreach (array('<!-- wp:post-content', 'Shared contact banner.') as $needle) {
        if (!str_contains($template, $needle)) $failures[] = $case . ': template keeps ' . $needle;
    }
    foreach ($plan['pages'] as $page) {
        if ('post' !== ($page['post_type'] ?? null)) continue;
        $slug = basename(dirname($page['source_path']));
        $content = $page['canonical_block_markup'];
        if ('single' !== ($page['route_template'] ?? 'single')) $failures[] = $case . ': ' . $slug . ' uses single';
        if (!str_contains($content, ucfirst($slug) . ' event opening paragraph.')) $failures[] = $case . ': ' . $slug . ' keeps its body';
        if ($posts[$slug] !== str_contains($content, 'overlay')) $failures[] = $case . ': ' . $slug . ' keeps its own hero variant';
        foreach (array('Shared contact banner.', 'Shared header', 'Footer text') as $excluded) {
            if (str_contains($content, $excluded)) $failures[] = $case . ': ' . $slug . ' post_content excludes ' . $excluded;
        }
    }
    // The hero's background is a post-scoped stylesheet rule on its own class.
    $css = json_encode($plan, JSON_UNESCAPED_SLASHES);
    foreach ($plan['pages'] as $page) {
        if ('post' !== ($page['post_type'] ?? null)) continue;
        $slug = basename(dirname($page['source_path']));
        preg_match('/<section class="[^"]*\\b(be-inline-geometry-[0-9a-f]+)/', $page['canonical_block_markup'], $hero);
        if (!str_contains($css, '.' . ($hero[1] ?? '-') . '{background-image:url(media/' . $slug . '.png)')) $failures[] = $case . ': ' . $slug . ' keeps its own hero image';
    }
}
if (array() !== $failures) {
    fwrite(STDERR, 'FAIL: ' . implode("\nFAIL: ", $failures) . "\n");
    exit(1);
}
echo "post chrome section design merge: passed\n";
