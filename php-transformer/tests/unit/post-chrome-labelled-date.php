<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

// Blogger/UIkit bylines in a WordPress-named content document (#6417).
$failures = array();
foreach (array('labelled' => true, 'date-only' => false) as $case => $labelled) {
    $files = array('index.html' => '<!doctype html><html><head><title>Home</title></head><body><a href="/first.html">First story</a><a href="/second.html">Second story</a></body></html>');
    foreach (array('first' => '2023-11-28', 'second' => '2023-11-29') as $slug => $date) {
        $time = '<time class="published" datetime="' . $date . 'T11:44:00-08:00">November ' . ('first' === $slug ? '28' : '29') . ', 2023</time>';
        $byline = $labelled
            ? '<span class="post_timestamp uk-text-small"><span class="post_timestamp_label"><span class="uk-icon"><svg width="16" height="16" viewBox="0 0 20 20"><circle cx="10" cy="10" r="9"></circle><path d="M10 4v6h4"></path></svg></span>Posted at</span><a class="timestamp-link" href="/' . $slug . '.html">' . $time . '</a></span>'
            : $time;
        $files[$slug . '.html'] = '<!doctype html><html><head><title>' . ucfirst($slug) . ' story</title><script type="application/ld+json">{"@context":"https://schema.org","@type":"BlogPosting","headline":"' . ucfirst($slug) . ' story","datePublished":"' . $date . 'T11:44:00-08:00"}</script></head><body><main><article><h1>' . ucfirst($slug) . ' story</h1>' . $byline . '<div class="wp-block-post-content"><p>Body of the ' . $slug . ' story.</p></div></article></main></body></html>';
    }
    $plan = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => $files))->toArray()['source_reports']['wordpress_site_plan'];
    $templates = array_values(array_filter($plan['templates'], static fn(array $entry): bool => 'single' === $entry['slug']));
    if (1 !== count($templates)) $failures[] = "$case: one single template";
    $template = $templates[0]['canonical_block_markup'] ?? '';
    if (1 !== substr_count($template, '<!-- wp:post-date')) $failures[] = "$case: one dynamic date";
    if (2 !== count(array_filter($plan['pages'], static fn(array $page): bool => 'post' === ($page['post_type'] ?? null)))) $failures[] = "$case: two posts";
    if ($labelled) {
        foreach (array('Posted at', '<img', 'post_timestamp', 'post_timestamp_label', 'uk-icon', 'timestamp-link', 'published') as $kept) {
            if (!str_contains($template, $kept)) $failures[] = "$case: preserve $kept";
        }
        if (strpos($template, 'Posted at') > strpos($template, '<!-- wp:post-date')) $failures[] = "$case: label precedes date";
    } elseif (str_contains($template, '<!-- wp:paragraph')) {
        $failures[] = "$case: date alone needs no label paragraph";
    }
    foreach ($plan['diagnostics'] as $diagnostic) {
        if ('wordpress_site_plan_content_lost' === ($diagnostic['code'] ?? null)) $failures[] = "$case: " . json_encode($diagnostic);
    }
}
if (array() !== $failures) {
    fwrite(STDERR, 'FAIL: ' . implode("\nFAIL: ", $failures) . "\n");
    exit(1);
}
echo "post chrome labelled date: passed\n";
