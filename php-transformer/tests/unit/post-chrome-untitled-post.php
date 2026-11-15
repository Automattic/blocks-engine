<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

// #6105: the untitled/ reproduction, including an explicitly declared empty title.
$failures = array();
foreach (array(array('a', 'b', 'u'), array('u', 'a', 'b')) as $order) {
    $titles = array('a' => 'Alpha notes', 'b' => 'Beta notes', 'u' => '');
    $header = '<header class="site-header"><p>Shared header</p></header>';
    $footer = '<footer class="site-footer"><p>Footer text</p></footer>';
    $links = '';
    foreach ($titles as $slug => $title) $links .= '<p><a href="/2024/01/' . $slug . '.html">' . ($title ?: 'Untitled') . '</a></p>';
    $files = array(array('path' => 'index.html', 'content' => '<!doctype html><html><head><title>Example Journal</title></head><body>' . "\n" . $header . "\n" . '<div class="main-content"><h1>Example Journal</h1>' . $links . '</div>' . "\n" . $footer . "\n" . '</body></html>' . "\n"));
    // Generator defaults: cardDepth=1 and filled related sections on every post.
    foreach ($order as $slug) {
        $title = $titles[$slug];
        $date = array('a' => '2024-01-10', 'b' => '2024-01-12', 'u' => '2024-01-14')[$slug];
        $heading = '' === $title ? '' : '<h1 class="post-title">' . $title . '</h1>';
        $cards = '';
        foreach ($titles as $otherSlug => $otherTitle) {
            if ($otherSlug === $slug) continue;
            $cards .= '<li><div class="card-0"><a href="/2024/01/' . $otherSlug . '.html">' . ($otherTitle ?: 'Untitled') . '</a></div></li>';
        }
        $related = '<div class="related"><h3 class="related-title">Related Posts</h3><ul class="related-list">' . $cards . '</ul></div>';
        $documentTitle = $title ?: 'Example Journal';
        $html = <<<HTML
<!doctype html><html><head><title>{$documentTitle}</title><meta property="article:published_time" content="{$date}T00:00:00Z"><style>.main-content{max-width:1200px;margin:0 auto;padding:0 16px}.grid{display:flex;gap:24px}.col-main{flex:2}.col-side{flex:1}</style></head><body>
<header class="site-header"><p>Shared header</p></header>
<div class="main-content"><div class="grid">
<div class="col-main"><div class="blog"><article class="post"><div class="post-content">
{$heading}
<p class="post-date"><time datetime="{$date}">{$date}</time></p>
<div class="post-body"><p>{$slug} opening paragraph.</p><p>{$slug} middle paragraph.</p><p>{$slug} closing paragraph.</p></div>
{$related}
</div></article></div></div>
<div class="col-side"><div class="sidebar"><h2>About</h2><p>Sidebar text.</p></div></div>
</div></div>
<footer class="site-footer"><p>Footer text</p></footer>
</body></html>

HTML;
        $files[] = array(
            'path' => '2024/01/' . $slug . '.html',
            'metadata' => array('post_type' => 'post', 'declared_content' => array('title' => $title, 'slug' => $slug, 'published' => $date . 'T00:00:00Z', 'updated' => $date . 'T00:00:00Z', 'author' => 'Example Author', 'terms' => array('Notes'))),
            'content' => $html,
        );
    }
    $plan = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => $files))->toArray()['source_reports']['wordpress_site_plan'];
    $posts = array_values(array_filter($plan['pages'], static fn(array $page): bool => 'post' === ($page['post_type'] ?? null)));
    if (3 !== count($posts)) $failures[] = 'three posts';
    $templates = array_values(array_filter($plan['templates'], static fn(array $template): bool => str_starts_with($template['slug'], 'single')));
    if (1 !== count($templates)) $failures[] = 'one shared single-post template';
    $template = $templates[0]['canonical_block_markup'] ?? '';
    foreach (array('post-title', 'post-date', 'post-content') as $block) {
        if (!str_contains($template, '<!-- wp:' . $block)) $failures[] = 'template contains core/' . $block;
    }
    foreach ($posts as $post) {
        $slug = basename($post['source_path'], '.html');
        if ($post['title'] !== $titles[$slug]) $failures[] = $slug . ' preserves its declared title';
        if (($post['route_template'] ?? 'single') !== ($templates[0]['slug'] ?? null)) $failures[] = $slug . ' uses the shared template';
        $text = trim(preg_replace('/\s+/', ' ', strip_tags(preg_replace('/<!--.*?-->/s', '', $post['canonical_block_markup']))));
        if ($text !== $slug . ' opening paragraph.' . $slug . ' middle paragraph.' . $slug . ' closing paragraph.') $failures[] = $slug . ' post_content contains only body paragraphs: ' . $text;
    }
}
if (array() !== $failures) {
    fwrite(STDERR, 'FAIL: ' . implode("\nFAIL: ", $failures) . "\n");
    exit(1);
}
echo "post chrome untitled post: passed\n";
