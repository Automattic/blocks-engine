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
foreach (array('untitled-main-landmark', 'titled-main-landmark', 'self-link-listing', 'control', 'duplicate-titles', 'short-media-body') as $fixture) {
    $titles = array('a' => 'Alpha notes', 'b' => 'Beta notes', 'c' => 'Gamma notes');
    if ('duplicate-titles' === $fixture) $titles['a'] = $titles['b'] = 'My Splendid Concubine';
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
            $thumbnail = 'short-media-body' === $fixture ? '<img src="/thumb-' . $other . '.jpg" alt="">' : '';
            $cards .= '<li><div class="card-0"><a href="/2024/01/' . $other . '.html">' . $thumbnail . $otherTitle . '</a></div></li>';
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
        $articleBody = '<p>' . $slug . ' opening paragraph.</p><p>' . $slug . ' middle paragraph.</p><p>' . $slug . ' closing paragraph.</p>';
        if ('short-media-body' === $fixture && 'a' === $slug) $articleBody = '<div class="separator"><img src="/cover-a.jpg" alt=""></div><p><a href="https://example.com/buy">Buy it today</a></p>';
        $html = <<<HTML
<!doctype html><html><head><title>{$documentTitle}</title><meta property="article:published_time" content="{$date}T00:00:00Z"><style>{$css}</style></head><body>
{$header}
{$open}<div class="main-content"><div class="grid">
<div class="col-main"><div class="blog"><article class="post"><div class="post-content">
{$heading}
<p class="post-date"><time datetime="{$date}">{$date}</time></p>
<div class="post-body">{$articleBody}</div>
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
    if ('duplicate-titles' === $fixture && !str_contains($templates[0]['canonical_block_markup'] ?? '', 'wp:post-title')) $failures[] = $fixture . ': template has a post-title slot';
    foreach ($posts as $post) {
        $slug = basename($post['source_path'], '.html');
        if (($post['route_template'] ?? 'single') !== ($templates[0]['slug'] ?? null)) $failures[] = $fixture . ': ' . $slug . ' uses shared template';
        $body = $post['canonical_block_markup'];
        $text = trim(preg_replace('/\s+/', ' ', strip_tags(preg_replace('/<!--.*?-->/s', '', $body))));
        $short = 'short-media-body' === $fixture && 'a' === $slug;
        $expectedText = $short ? 'Buy it today' : $slug . ' opening paragraph.' . $slug . ' middle paragraph.' . $slug . ' closing paragraph.';
        if ($text !== $expectedText) $failures[] = $fixture . ': ' . $slug . ' body only: ' . $text;
        if ('short-media-body' === $fixture) {
            if (substr_count($body, '<img ') !== ($short ? 1 : 0) || str_contains($body, '/thumb-')) $failures[] = $fixture . ': ' . $slug . ' body excludes related thumbnails';
            if ($short && (!str_contains($body, '<!-- wp:image ') || !str_contains($body, 'https://example.com/buy'))) $failures[] = $fixture . ': short body retains image and purchase link';
        }
        echo $fixture . ' ' . $slug . ': ' . strlen($body) . " B\n";
    }
    foreach ($plan['diagnostics'] as $finding) {
        if (str_contains($finding['code'] ?? '', 'post_chrome_order_retained')) $failures[] = $fixture . ': no order-retained findings';
    }
}
// Real UIkit/Blogger layout: Docs wrappers and per-post anchors must stay
// body-owned. Identical text-only related lists isolate this from #6159.
$realTitles = array('x' => 'X notes', 'y' => 'Y notes', 'z' => 'Z notes');
$realCss = '.uk-container{max-width:1200px;margin:0 auto}.uk-grid{display:flex;gap:24px;list-style:none;padding:0}.main{flex:2}.side{flex:1}.post_title{font-size:2rem}';
$related = '<div class="post_related"><h3 class="post_related_title">Related Posts</h3><ul>';
$popular = '<div class="widget PopularPosts"><h3>Popular Posts</h3><ul>';
$indexLinks = '';
foreach ($realTitles as $slug => $title) {
    $link = '<a href="/2023/11/' . $slug . '.html">' . $title . '</a>';
    $related .= '<li>' . $link . '</li>';
    $popular .= '<li><h4 class="post_title">' . $link . '</h4></li>';
    $indexLinks .= '<p>' . $link . '</p>';
}
$related .= '</ul></div>';
$popular .= '</ul></div>';
$files = array(array('path' => 'index.html', 'content' => '<!doctype html><html><head><title>Example Journal</title></head><body><div class="uk-container"><h1>Example Journal</h1>' . $indexLinks . '</div></body></html>'));
$expectedIds = array();
$i = 0;
foreach ($realTitles as $slug => $title) {
    $date = '2023-11-' . (10 + $i);
    $label = 'Nov ' . (10 + $i) . ', 2023';
    $pid = '627533715478939800' . $i++;
    $expectedIds[$slug] = 'post_body_' . $pid;
    $paragraphs = '';
    for ($n = 1; $n <= 8; ++$n) $paragraphs .= '<p dir="ltr"><span style="font-size:12pt">' . $slug . ' paragraph ' . $n . '.</span></p>';
    $docs = '<p></p><div class="separator" style="clear: both"><b id="docs-internal-guid-' . substr($pid, 0, 8) . '-7fff" style="font-weight:normal">' . $paragraphs . '</b></div>' . ('x' === $slug ? '<br>' : '') . '<p></p>';
    $html = <<<HTML
<!doctype html><html><head><title>{$title}</title><style>{$realCss}</style></head><body>
{$header}
<div class="uk-container"><div class="uk-grid">
<div class="main"><div id="single_post" class="single_post hfeed"><article class="post hentry"><div class="post_content">
<h1 class="post_title entry-title uk-article-title">{$title}</h1>
<div class="post_header uk-article-meta"><span class="post_timestamp"><time datetime="{$date}">{$label}</time></span></div>
<div class="post_body entry-content" id="post_body_{$pid}">{$docs}</div>
{$related}
</div></article></div></div>
<div class="side"><div class="widget About"><h3>About</h3><p>Sidebar text.</p></div>{$popular}</div>
</div></div>
{$footer}
</body></html>

HTML;
    $files[] = array(
        'path' => '2023/11/' . $slug . '.html',
        'metadata' => array('post_type' => 'post', 'declared_content' => array('title' => $title, 'slug' => $slug, 'published' => $date . 'T00:00:00Z', 'updated' => $date . 'T00:00:00Z', 'author' => 'Example Author', 'terms' => array('Notes'))),
        'content' => $html,
    );
}
$plan = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => $files))->toArray()['source_reports']['wordpress_site_plan'];
$posts = array_values(array_filter($plan['pages'], static fn(array $page): bool => 'post' === ($page['post_type'] ?? null)));
$templates = array_values(array_filter($plan['templates'], static fn(array $template): bool => str_starts_with($template['slug'], 'single')));
if (3 !== count($posts)) $failures[] = 'self-link-listing-real: three posts';
if (1 !== count($templates)) $failures[] = 'self-link-listing-real: one shared single-post template';
foreach ($posts as $post) {
    $slug = basename($post['source_path'], '.html');
    if (($post['route_template'] ?? 'single') !== ($templates[0]['slug'] ?? null)) $failures[] = 'self-link-listing-real: ' . $slug . ' uses shared template';
    $body = $post['canonical_block_markup'];
    $text = trim(preg_replace('/\s+/', ' ', strip_tags(preg_replace('/<!--.*?-->/s', '', $body))));
    $expectedText = '';
    for ($n = 1; $n <= 8; ++$n) $expectedText .= $slug . ' paragraph ' . $n . '.';
    if ($expectedText !== $text) $failures[] = 'self-link-listing-real: ' . $slug . ' body only: ' . $text;
    if (!str_contains($body, 'id="' . $expectedIds[$slug] . '"') || !str_contains($body, 'post_body')) $failures[] = 'self-link-listing-real: ' . $slug . ' retains body wrapper and anchor';
    if (str_contains($templates[0]['canonical_block_markup'] ?? '', $expectedIds[$slug])) $failures[] = 'self-link-listing-real: body anchor is not template-owned';
    echo 'self-link-listing-real ' . $slug . ': ' . strlen($body) . " B\n";
}
$findings = array_values(array_filter($plan['diagnostics'], static fn(array $finding): bool => 'info' !== ($finding['severity'] ?? null)));
if (array() !== $findings) $failures[] = 'self-link-listing-real: zero findings ' . json_encode($findings);

if (array() !== $failures) {
    fwrite(STDERR, 'FAIL: ' . implode("\nFAIL: ", $failures) . "\n");
    exit(1);
}
echo "post chrome body anchor: passed\n";
