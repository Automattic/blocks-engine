<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

// Pages that share the article layout of their posts (Blogger static pages)
// own their title, byline and body like posts do, so a page authored in
// WordPress renders the same layout. Each page also carries its own author CSS,
// which gives the same chrome a different richtext marker per document.
$article = static function (string $slug, string $title, string $body, string $css, string $date = ''): string {
    $time = '' === $date ? '' : '<span class="post_date"><time datetime="' . $date . '">' . $date . '</time></span>';
    return '<!doctype html><html><head><title>' . $title . '</title><style>.post_author{margin-right:20px}.post_author .label{margin-right:5px;font-weight:700}' . $css . '</style></head><body>'
        . '<header class="site-header"><p>Shared header</p></header><main><div class="layout"><div class="post_content"><h1 class="post_title">' . $title . '</h1>'
        . '<div class="post_header"><span class="post_author"><span class="label">Posted By</span><span class="fn"><a class="g-profile" href="https://www.blogger.com/profile/1" rel="author" title="author profile"><span>Ada Lovelace</span></a></span></span>' . $time . '</div>'
        . '<div class="post_body" id="post_body_' . md5($slug) . '">' . $body . '</div>'
        . '<div class="post_footer"><span class="post_share"><span class="label">Share</span></span></div></div><aside class="sidebar"><p>Sidebar</p></aside></div></main>'
        . '<footer><p>Footer</p></footer></body></html>';
};
$files = array(array('path' => 'index.html', 'content' => '<!doctype html><html><head><title>Journal</title></head><body><header class="site-header"><p>Shared header</p></header><main><h1>Journal</h1></main><footer><p>Footer</p></footer></body></html>'));
foreach (array('a' => '2024-01-10', 'b' => '2024-01-12') as $slug => $date) {
    $files[] = array('path' => '2024/01/' . $slug . '.html', 'metadata' => array('post_type' => 'post', 'declared_content' => array('title' => 'Post ' . $slug, 'slug' => $slug, 'published' => $date . 'T00:00:00Z')), 'content' => $article($slug, 'Post ' . $slug, '<p>' . $slug . ' post body.</p>', '', $date));
}
$pages = array('about' => array('About Me', '<p>About body one.</p><p>About body two.</p>', '.about-only{color:red}'), 'books' => array('Books', '<p>Books body.</p>', '.books-only{color:blue}'));
foreach ($pages as $slug => [$title, $body, $css]) {
    $files[] = array('path' => 'p/' . $slug . '.html', 'metadata' => array('post_type' => 'page', 'declared_content' => array('title' => $title, 'slug' => $slug)), 'content' => $article($slug, $title, $body, $css));
}
$plan = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => $files))->toArray()['source_reports']['wordpress_site_plan'];
$failures = array();
$templates = array_column($plan['templates'], 'canonical_block_markup', 'slug');
$used = array();
foreach ($plan['pages'] as $page) {
    if ('page' !== $page['post_type'] || !str_starts_with($page['source_path'], 'p/')) continue;
    [$title, $body] = $pages[basename($page['source_path'], '.html')];
    $used[] = $page['route_template'] ?? 'page';
    $text = trim(preg_replace('/\s+/', ' ', strip_tags(preg_replace('/<!--.*?-->/s', '', $page['canonical_block_markup']))));
    if ($text !== strip_tags($body)) $failures[] = $page['source_path'] . ' post_content holds only its body: ' . $text;
}
$used = array_unique($used);
$template = $templates[$used[0] ?? ''] ?? '';
if (1 !== count($used)) $failures[] = 'both pages share one template: ' . json_encode($used);
foreach (array('post-title', 'post-author-name', 'post-content') as $block) {
    if (!str_contains($template, '<!-- wp:' . $block)) $failures[] = 'the page template renders core/' . $block;
}
if (!str_contains($template, 'Sidebar') || !str_contains($template, 'Share') || str_contains($template, 'About Me') || str_contains($template, 'Ada Lovelace')) $failures[] = 'the page template keeps the layout chrome and no page values';
if (!in_array('p/about.html', array_column($plan['author_entities'], 'membership_source_paths')[0] ?? array(), true)) $failures[] = 'the byline author owns the pages';
if (array() !== $failures) {
    fwrite(STDERR, 'FAIL: ' . implode("\nFAIL: ", $failures) . "\n");
    exit(1);
}
echo "ok\n";
