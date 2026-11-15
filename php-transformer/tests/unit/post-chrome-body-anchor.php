<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

// #6153 and #6154: body anchors must not come from layout landmarks or sidebar links.
$failures = array();
$titles = array('a' => 'Alpha notes', 'b' => 'Beta notes', 'c' => 'Gamma notes');
$css = '.main-content{max-width:1200px;margin:0 auto;padding:0 16px}.grid{display:flex;gap:24px}.col-main{flex:2}.col-side{flex:1}.nav{display:flex;gap:12px;list-style:none}.nav>li>a{color:#007bff}';
$header = '<header class="site-header"><nav><ul class="nav"><li><a href="/index.html">Home</a></li><li><a href="/p/about.html">About</a></li></ul></nav></header>';
$footer = '<footer class="site-footer"><p>Footer text</p></footer>';
$links = '';
foreach ($titles as $slug => $title) $links .= '<p><a href="/2024/01/' . $slug . '.html">' . $title . '</a></p>';
foreach (array('untitled-main-landmark', 'titled-main-landmark', 'self-link-listing', 'control') as $fixture) {
    $untitled = 'untitled-main-landmark' === $fixture;
    $main = str_ends_with($fixture, 'main-landmark');
    $files = array(array('path' => 'index.html', 'content' => '<!doctype html><html><head><title>Example Journal</title></head><body>' . "\n" . $header . "\n" . '<div class="main-content"><h1>Example Journal</h1>' . $links . '</div>' . "\n" . $footer . "\n" . '</body></html>' . "\n"));
    $i = 0;
    foreach ($titles as $slug => $title) {
        $title = $untitled ? '' : $title;
        $date = '2024-01-' . (10 + 2 * $i++);
        $heading = '' === $title ? '' : '<h1 class="post-title">' . $title . '</h1>';
        $cards = '';
        foreach ($titles as $other => $otherTitle) {
            if ($other === $slug) continue;
            $cards .= '<li><div class="card-0"><a href="/2024/01/' . $other . '.html">' . $otherTitle . '</a></div></li>';
        }
        $popular = '';
        if ('self-link-listing' === $fixture) {
            $popular = '<div class="widget popular"><h2>Popular Posts</h2>';
            foreach ($titles as $other => $otherTitle) $popular .= '<p class="pop-item"><a href="/2024/01/' . $other . '.html">' . $otherTitle . '</a></p>';
            $popular .= '</div>';
        }
        $open = $main ? '<main><div class="top-strip"><p>Top strip</p></div>' : '';
        $close = $main ? '<div class="bottom-strip"><p>Bottom strip</p></div></main>' : '';
        $documentTitle = $title ?: 'Example Journal';
        $html = <<<HTML
<!doctype html><html><head><title>{$documentTitle}</title><meta property="article:published_time" content="{$date}T00:00:00Z"><style>{$css}</style></head><body>
{$header}
{$open}<div class="main-content"><div class="grid">
<div class="col-main"><div class="blog"><article class="post"><div class="post-content">
{$heading}
<p class="post-date"><time datetime="{$date}">{$date}</time></p>
<div class="post-body"><p>{$slug} opening paragraph.</p><p>{$slug} middle paragraph.</p><p>{$slug} closing paragraph.</p></div>
<div class="related"><h3 class="related-title">Related Posts</h3><ul class="related-list">{$cards}</ul></div>
</div></article></div></div>
<div class="col-side"><div class="sidebar"><h2>About</h2><p>Sidebar text.</p></div>{$popular}</div>
</div></div>{$close}
{$footer}
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
    $templates = array_values(array_filter($plan['templates'], static fn(array $template): bool => str_starts_with($template['slug'], 'single')));
    if (3 !== count($posts)) $failures[] = $fixture . ': three posts';
    if (1 !== count($templates)) $failures[] = $fixture . ': one shared single-post template';
    foreach ($posts as $post) {
        $slug = basename($post['source_path'], '.html');
        if (($post['route_template'] ?? 'single') !== ($templates[0]['slug'] ?? null)) $failures[] = $fixture . ': ' . $slug . ' uses shared template';
        $body = $post['canonical_block_markup'];
        $text = trim(preg_replace('/\s+/', ' ', strip_tags(preg_replace('/<!--.*?-->/s', '', $body))));
        if ($text !== $slug . ' opening paragraph.' . $slug . ' middle paragraph.' . $slug . ' closing paragraph.') $failures[] = $fixture . ': ' . $slug . ' body only: ' . $text;
        echo $fixture . ' ' . $slug . ': ' . strlen($body) . " B\n";
    }
    foreach ($plan['diagnostics'] as $finding) {
        if (str_contains($finding['code'] ?? '', 'post_chrome_order_retained')) $failures[] = $fixture . ': no order-retained findings';
    }
}
if (array() !== $failures) {
    fwrite(STDERR, 'FAIL: ' . implode("\nFAIL: ", $failures) . "\n");
    exit(1);
}
echo "post chrome body anchor: passed\n";
