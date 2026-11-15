<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

// #6152: repeated thumbnail cards and carousel controls must not retain post chrome.
$failures = array();
foreach (array('related-thumbs', 'related-thumbs-disjoint', 'related-carousel') as $case) {
    $titles = array('a' => 'Alpha notes', 'b' => 'Beta notes', 'c' => 'Gamma notes');
    $nav = '<header class="site-header"><nav><ul class="nav"><li><a href="/index.html">Home</a></li><li><a href="/p/about.html">About</a></li></ul></nav></header>';
    $footer = '<footer class="site-footer"><p>Footer text</p></footer>';
    $links = '';
    foreach ($titles as $slug => $title) $links .= '<p><a href="/2024/01/' . $slug . '.html">' . $title . '</a></p>';
    $files = array(array('path' => 'index.html', 'content' => '<!doctype html><html><head><title>Example Journal</title></head><body>' . $nav . '<div class="main-content"><h1>Example Journal</h1>' . $links . '</div>' . $footer . '</body></html>'));
    foreach (array_keys($titles) as $i => $slug) {
        $title = $titles[$slug];
        $date = sprintf('2024-01-%02d', 10 + 2 * $i);
        $relatedSlugs = 'related-thumbs-disjoint' === $case ? array(array('a' => 'b', 'b' => 'c', 'c' => 'a')[$slug]) : array_values(array_diff(array_keys($titles), array($slug)));
        $cards = '';
        foreach ($relatedSlugs as $other) $cards .= '<li><div class="card-0"><a class="thumb" href="/2024/01/' . $other . '.html"><img src="/media/' . $other . '.png" width="120" height="80" alt=""></a><a href="/2024/01/' . $other . '.html">' . $titles[$other] . '</a></div></li>';
        $list = '<ul class="related-list">' . $cards . '</ul>';
        if ('related-carousel' === $case) {
            $list = '<div class="slider">' . $list . '<ul class="dots">' . str_repeat('<li></li>', count($relatedSlugs)) . '</ul><a class="prev" href="#"><svg width="14" height="24" viewBox="0 0 14 24" xmlns="http://www.w3.org/2000/svg"><polyline fill="none" stroke="#000" points="12 1 1 12 12 23"></polyline></svg></a><a class="next" href="#"><svg width="14" height="24" viewBox="0 0 14 24" xmlns="http://www.w3.org/2000/svg"><polyline fill="none" stroke="#000" points="1 1 12 12 1 23"></polyline></svg></a></div>';
        }
        $related = '<div class="related"><h3 class="related-title">Related Posts</h3>' . $list . '</div>';
        $html = <<<HTML
<!doctype html><html><head><title>{$title}</title><meta property="article:published_time" content="{$date}T00:00:00Z"><style>.main-content{max-width:1200px;margin:0 auto;padding:0 16px}.grid{display:flex;gap:24px}.col-main{flex:2}.col-side{flex:1}.nav{display:flex;gap:12px;list-style:none}.nav>li>a{color:#007bff}</style></head><body>
{$nav}
<div class="main-content"><div class="grid">
<div class="col-main"><div class="blog"><article class="post"><div class="post-content">
<h1 class="post-title">{$title}</h1>
<p class="post-date"><time datetime="{$date}">{$date}</time></p>
<div class="post-body"><p>{$slug} opening paragraph.</p><p>{$slug} middle paragraph.</p><p>{$slug} closing paragraph.</p></div>
{$related}
</div></article></div></div>
<div class="col-side"><div class="sidebar"><h2>About</h2><p>Sidebar text.</p></div></div>
</div></div>
{$footer}
</body></html>
HTML;
        $files[] = array('path' => '2024/01/' . $slug . '.html', 'content' => $html, 'metadata' => array('post_type' => 'post', 'declared_content' => array('title' => $title, 'slug' => $slug, 'published' => $date . 'T00:00:00Z', 'updated' => $date . 'T00:00:00Z', 'author' => 'Example Author', 'terms' => array('Notes'))));
        $files[] = array('path' => 'media/' . $slug . '.png', 'content' => hex2bin('89504e470d0a1a0a0000000d49484452000000010000000108060000001f15c4890000000d4944415478da63000100000500010d0a2db40000000049454e44ae426082'));
    }
    $plan = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => $files))->toArray()['source_reports']['wordpress_site_plan'];
    $posts = array_values(array_filter($plan['pages'], static fn(array $page): bool => 'post' === ($page['post_type'] ?? null)));
    $templates = array_values(array_filter($plan['templates'], static fn(array $entry): bool => str_starts_with($entry['slug'], 'single')));
    if (3 !== count($posts) || 1 !== count($templates)) $failures[] = $case . ': three posts share one single template';
    $template = $templates[0]['canonical_block_markup'] ?? '';
    foreach (array('post-content', 'query', 'post-featured-image') as $block) {
        if (!str_contains($template, '<!-- wp:' . $block)) $failures[] = $case . ': template contains core/' . $block;
    }
    if ('related-carousel' === $case && !str_contains($template, '<!-- wp:custom/authored-carousel')) $failures[] = $case . ': preserves carousel wrapper';
    $findings = array_filter($plan['diagnostics'], static fn(array $entry): bool => str_contains($entry['code'] ?? '', 'post_chrome_order_retained'));
    if (array() !== $findings) $failures[] = $case . ': no order-retained findings';
    foreach ($posts as $post) {
        $slug = basename($post['source_path'], '.html');
        if (($post['route_template'] ?? 'single') !== ($templates[0]['slug'] ?? null)) $failures[] = $case . ': shared template for ' . $slug;
        $content = $post['canonical_block_markup'];
        $text = trim(preg_replace('/\s+/', ' ', strip_tags(preg_replace('/<!--.*?-->/s', '', $content))));
        if ($text !== $slug . ' opening paragraph.' . $slug . ' middle paragraph.' . $slug . ' closing paragraph.' || str_contains($content, '<img')) $failures[] = $case . ': body only for ' . $slug;
    }
    echo $case . ': ' . implode('/', array_map(static fn(array $post): int => strlen($post['canonical_block_markup']), $posts)) . ' bytes; ' . count($findings) . " findings\n";
}
if (array() !== $failures) {
    fwrite(STDERR, 'FAIL: ' . implode("\nFAIL: ", $failures) . "\n");
    exit(1);
}
echo "post chrome related projection: passed\n";
