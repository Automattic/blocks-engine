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
// Real Blogger/UIkit capture: two layout groups, cross-group cards, and
// route-dependent slider state plus responsive thumbnail geometry.
$case = 'related-carousel-real';
$width = 'data-blocks-engine-capture-width-class-9bda9046a11b';
$states = array(
    'a' => array('c' => '', 'd' => 'uk-active', 'e' => 'uk-active', 'b' => $width),
    'b' => array('d' => '', 'e' => 'uk-active', 'c' => $width, 'a' => ''),
    'c' => array('a' => 'uk-active', 'b' => 'uk-active', 'd' => $width, 'e' => ''),
    'd' => array('e' => '', 'a' => 'uk-active', 'c' => 'uk-active', 'b' => $width),
    'e' => array('b' => '', 'c' => 'uk-active', 'a' => $width, 'd' => ''),
);
$css = '.uk-container{max-width:1200px;margin:0 auto}.uk-grid{display:flex;gap:24px;list-style:none;padding:0}.main{flex:2}.side{flex:1}.uk-slider-items{display:flex;list-style:none;padding:0}.uk-slider-items>li{flex:0 0 33%}.thumb img{width:100%;height:auto}.uk-slidenav{position:absolute;top:40%}.post_title{font-size:2rem}';
$nav = '<header class="site-header"><nav><ul class="uk-grid"><li><a href="/index.html">Home</a></li><li><a href="/p/about.html">About</a></li></ul></nav></header>';
$links = '';
$files = array();
foreach (array_keys($states) as $i => $slug) {
    $title = strtoupper($slug) . ' notes';
    $date = '2023-11-' . (10 + $i);
    $label = 'Nov ' . (10 + $i) . ', 2023';
    $pid = (string) (6275337154789398000 + $i);
    $links .= '<p><a href="/2023/11/' . $slug . '.html">' . $title . '</a></p>';
    $cards = '';
    foreach ($states[$slug] as $other => $state) {
        $otherTitle = strtoupper($other) . ' notes';
        $otherLabel = 'Nov ' . (10 + array_search($other, array_keys($states), true)) . ', 2023';
        $path = '/2023/11/' . $other . '.html';
        $class = '' === $state ? '' : ' class="' . $state . '"';
        $cards .= '<li' . $class . '><div class="uk-card uk-card-hover uk-card-default"><div class="post_thumbnail uk-card-media-top uk-position-relative" data-title="' . $otherTitle . '"><a href="' . $path . '"><div class="thumb thumb_default" style=""><img width="510" height="286" alt="preview" src="/media/t510.png" sizes="(min-width: 1200px) 225px, 510px" srcset="/media/t225.png 225w, /media/t510.png 510w" style="aspect-ratio: 222.047 / 124.516"></div><div class="uk-position-cover uk-overlay-primary"></div></a></div><div class="post_content uk-card-small uk-card-body"><h3 class="post_title entry-title uk-margin"><a title="" href="' . $path . '">' . $otherTitle . '</a></h3><div class="post_meta uk-grid uk-grid-small"><div class="post_date uk-width-auto"><span>' . $otherLabel . '</span></div></div></div></div></li>';
    }
    $controls = '';
    foreach (array('previous' => '12 1 1 12 12 23', 'next' => '1 1 12 12 1 23') as $direction => $points) {
        $position = 'previous' === $direction ? 'left' : 'right';
        $controls .= '<a class="uk-position-center-' . $position . ' uk-slidenav-' . $direction . ' uk-slidenav" href="#" data-uk-slider-item="' . $direction . '"><svg width="14" height="24" viewBox="0 0 14 24" xmlns="http://www.w3.org/2000/svg"><polyline fill="none" stroke="#000" stroke-width="1.4" points="' . $points . '"></polyline></svg></a>';
    }
    $related = '<div class="post_related" data-count="5" data-id="' . $pid . '" data-title="Related Posts"><h3 class="post_related_title">Related Posts</h3><div class="uk-position-relative uk-slider uk-slider-container" data-uk-slider="autoplay:true;finite:true"><ul class="blog_posts uk-slider-items uk-grid uk-grid-medium" style="transform: translateX(0px)">' . $cards . '</ul>' . $controls . '</div></div>';
    $body = '';
    for ($paragraph = 1; $paragraph <= 8; ++$paragraph) $body .= '<p>' . $slug . ' paragraph ' . $paragraph . '.</p>';
    $widget = in_array($slug, array('c', 'd', 'e'), true) ? '<div class="widget Archive"><h3>Archive</h3><p>Archive text.</p></div>' : '';
    $html = '<!doctype html><html><head><title>' . $title . '</title><meta property="article:published_time" content="' . $date . 'T00:00:00Z"><style>' . $css . '</style></head><body>' . $nav . '<div class="uk-container"><div class="uk-grid"><div class="main"><div id="single_post" class="single_post hfeed"><article class="post hentry"><div class="post_content"><h1 class="post_title entry-title uk-article-title">' . $title . '</h1><div class="post_header uk-article-meta"><span class="post_timestamp"><time datetime="' . $date . '">' . $label . '</time></span></div><div class="post_body entry-content" id="post_body_' . $pid . '">' . $body . '</div>' . $related . '</div></article></div></div><div class="side"><div class="widget About"><h3>About</h3><p>Sidebar text.</p></div>' . $widget . '</div></div></div><footer class="site-footer"><p>Footer text</p></footer></body></html>';
    $files[] = array('path' => '2023/11/' . $slug . '.html', 'content' => $html, 'metadata' => array('post_type' => 'post', 'declared_content' => array('title' => $title, 'slug' => $slug, 'published' => $date . 'T00:00:00Z', 'updated' => $date . 'T00:00:00Z', 'author' => 'Example Author', 'terms' => array('Notes'))));
}
$files[] = array('path' => 'index.html', 'content' => '<!doctype html><html><head><title>Example Journal</title></head><body><div class="uk-container"><h1>Example Journal</h1>' . $links . '</div></body></html>');
foreach (array('t225', 't510', 'mq') as $image) $files[] = array('path' => 'media/' . $image . '.png', 'content' => hex2bin('89504e470d0a1a0a0000000d49484452000000010000000108060000001f15c4890000000d4944415478da63000100000500010d0a2db40000000049454e44ae426082'));
// Importers own the generated-block namespace, as SSI does with ssi-<site>.
$plan = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => $files, 'block_namespace' => 'ssi-example-journal'))->toArray()['source_reports']['wordpress_site_plan'];
$posts = array_values(array_filter($plan['pages'], static fn(array $page): bool => 'post' === ($page['post_type'] ?? null)));
$findings = array_filter($plan['diagnostics'], static fn(array $entry): bool => str_contains($entry['code'] ?? '', 'post_chrome_order_retained'));
if (5 !== count($posts)) $failures[] = $case . ': five posts';
if (array() !== $findings) $failures[] = $case . ': no order-retained findings';
foreach ($posts as $post) {
    $slug = basename($post['source_path'], '.html');
    $expected = '';
    for ($paragraph = 1; $paragraph <= 8; ++$paragraph) $expected .= $slug . ' paragraph ' . $paragraph . '.';
    $content = $post['canonical_block_markup'];
    $text = trim(preg_replace('/\s+/', ' ', strip_tags(preg_replace('/<!--.*?-->/s', '', $content))));
    if ($expected !== $text || str_contains($content, '<img')) $failures[] = $case . ': body only for ' . $slug;
    $dateLabel = 'Nov ' . (10 + array_search($slug, array_keys($states), true)) . ', 2023';
    if (!in_array('<span>' . $dateLabel . '</span>', $post['metadata']['post_meta'] ?? array(), true)) $failures[] = $case . ': projected card date for ' . $slug;
}
echo $case . ': ' . implode('/', array_map(static fn(array $post): int => strlen($post['canonical_block_markup']), $posts)) . ' bytes; ' . count($findings) . " findings\n";

if (array() !== $failures) {
    fwrite(STDERR, 'FAIL: ' . implode("\nFAIL: ", $failures) . "\n");
    exit(1);
}
echo "post chrome related projection: passed\n";
