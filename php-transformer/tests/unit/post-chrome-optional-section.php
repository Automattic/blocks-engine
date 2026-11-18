<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

// #6155: optional chrome must not strand a singleton's complete article.
$failures = array();
foreach (array('author-box-singleton', 'optional-author-box', 'hidden-related-singleton-disjoint') as $case) {
    $titles = array('a' => 'Alpha notes', 'b' => 'Beta notes', 'c' => 'Gamma notes', 'd' => 'Delta notes');
    $header = '<header class="site-header"><nav><ul class="nav"><li><a href="/index.html">Home</a></li><li><a href="/p/about.html">About</a></li></ul></nav></header>';
    $footer = '<footer class="site-footer"><p>Footer text</p></footer>';
    $links = '';
    foreach ($titles as $slug => $title) $links .= '<p><a href="/2024/01/' . $slug . '.html">' . $title . '</a></p>';
    $files = array(array('path' => 'index.html', 'content' => '<!doctype html><html><head><title>Example Journal</title></head><body>' . "\n" . $header . "\n" . '<div class="main-content"><h1>Example Journal</h1>' . $links . '</div>' . "\n" . $footer . "\n" . '</body></html>'));
    foreach ($titles as $slug => $title) {
        $date = array('a' => '2024-01-10', 'b' => '2024-01-12', 'c' => '2024-01-14', 'd' => '2024-01-16')[$slug];
        $relatedSlugs = array_slice(array_values(array_diff(array_keys($titles), array($slug))), 0, 2);
        if ('hidden-related-singleton-disjoint' === $case) $relatedSlugs = array('a' => array('b'), 'b' => array('c'), 'c' => array('a'), 'd' => array())[$slug];
        $cards = '';
        foreach ($relatedSlugs as $other) $cards .= '<li><div class="card-0"><a href="/2024/01/' . $other . '.html">' . $titles[$other] . '</a></div></li>';
        $related = '<div class="related"><h3 class="related-title">Related Posts</h3><ul class="related-list">' . $cards . '</ul></div>';
        if (array() === $relatedSlugs) $related = '<div class="related" style="display: none"><div class="spinner"><svg width="30" height="30" viewBox="0 0 30 30" xmlns="http://www.w3.org/2000/svg"><circle fill="none" stroke="#000" cx="15" cy="15" r="14"></circle></svg></div></div>';
        $box = '';
        if (('author-box-singleton' === $case && 'd' !== $slug) || ('optional-author-box' === $case && in_array($slug, array('a', 'b'), true))) {
            $box = '<div class="author-box"><div class="author-photo"><a href="https://profiles.example.test/42"><img src="/media/author.png" width="60" height="60" alt="author photo"></a></div><div class="author-desc"><p>Writes about examples.</p></div></div>';
        }
        $html = <<<HTML
<!doctype html><html><head><title>{$title}</title><meta property="article:published_time" content="{$date}T00:00:00Z"><style>.main-content{max-width:1200px;margin:0 auto;padding:0 16px}.grid{display:flex;gap:24px}.col-main{flex:2}.col-side{flex:1}.nav{display:flex;gap:12px;list-style:none}.nav>li>a{color:#007bff}</style></head><body>
{$header}
<div class="main-content"><div class="grid">
<div class="col-main"><div class="blog"><article class="post"><div class="post-content">
<h1 class="post-title">{$title}</h1>
<p class="post-date"><time datetime="{$date}">{$date}</time></p>
<div class="post-body"><p>{$slug} opening paragraph.</p><p>{$slug} middle paragraph.</p><p>{$slug} closing paragraph.</p></div>
{$related}
{$box}</div></article></div></div>
<div class="col-side"><div class="sidebar"><h2>About</h2><p>Sidebar text.</p></div></div>
</div></div>
{$footer}
</body></html>
HTML;
        $files[] = array('path' => '2024/01/' . $slug . '.html', 'content' => $html, 'metadata' => array('post_type' => 'post', 'declared_content' => array('title' => $title, 'slug' => $slug, 'published' => $date . 'T00:00:00Z', 'updated' => $date . 'T00:00:00Z', 'author' => 'Example Author', 'terms' => array('Notes'))));
    }
    $plan = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => $files))->toArray()['source_reports']['wordpress_site_plan'];
    $posts = array_values(array_filter($plan['pages'], static fn(array $page): bool => 'post' === ($page['post_type'] ?? null)));
    if (4 !== count($posts)) $failures[] = $case . ': four posts';
    $templates = array_column($plan['templates'], null, 'slug');
    foreach ($posts as $post) {
        $slug = basename($post['source_path'], '.html');
        $template = $templates[$post['route_template'] ?? 'single']['canonical_block_markup'] ?? '';
        foreach (array('post-title', 'post-date', 'post-content') as $block) {
            if (!str_contains($template, '<!-- wp:' . $block)) $failures[] = $case . '/' . $slug . ': single template contains ' . $block;
        }
        $text = trim(preg_replace('/\s+/', ' ', strip_tags(preg_replace('/<!--.*?-->/s', '', $post['canonical_block_markup']))));
        if ($text !== $slug . ' opening paragraph.' . $slug . ' middle paragraph.' . $slug . ' closing paragraph.') $failures[] = $case . '/' . $slug . ': body only: ' . $text;
        // #6438: d lists no cards, so it is outside the term proof; as a
        // member of the proven term its hidden section keeps the peers' query.
        if ('hidden-related-singleton-disjoint' === $case && !str_contains($template, '{{wordpress-site-plan:term:post_tag:notes}}')) $failures[] = $case . '/' . $slug . ': related query scoped to its term';
        if ('d' === $slug && str_contains($template, 'author-box')) $failures[] = $case . ': absent author box stays absent';
    }
    foreach ($plan['diagnostics'] ?? array() as $finding) {
        if (str_contains($finding['code'] ?? '', 'post_chrome_order_retained')) $failures[] = $case . ': no stray findings';
    }
}
if (array() !== $failures) {
    fwrite(STDERR, 'FAIL: ' . implode("\nFAIL: ", $failures) . "\n");
    exit(1);
}
echo "post chrome optional section: passed\n";
