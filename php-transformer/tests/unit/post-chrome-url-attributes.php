<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

// #6030: a per-post comments URL must not split a shared article layout.
$files = array('index.html' => '<!doctype html><html><head><title>Home</title></head><body><h1>Home</h1><p>Welcome.</p></body></html>');
$titles = array('alpha' => 'Alpha notes', 'beta' => 'Beta notes');
foreach ($titles as $slug => $title) {
    $id = 'alpha' === $slug ? '1001' : '1002';
    $date = 'alpha' === $slug ? '2024-01-10' : '2024-01-11';
    $files[$slug . '/index.html'] = '<!doctype html><html><head><title>' . $title . '</title><script type="application/ld+json">{"@context":"https://schema.org","@type":"BlogPosting","headline":"' . $title . '","datePublished":"' . $date . '"}</script></head><body><header class="site-header"><p>Shared header</p></header><main class="site-main"><article class="post"><h1 class="post-title">' . $title . '</h1><p class="post-date"><time datetime="' . $date . '">' . $date . '</time></p><div class="post-body"><p>' . $title . ' opening paragraph.</p><p>' . $title . ' middle paragraph.</p><p>' . $title . ' closing paragraph.</p></div><div class="comments" id="comments"><h3>Comments</h3><iframe class="comment-frame" src="https://comments.example.test/frame?post=' . $id . '" width="100%" height="90"></iframe></div></article></main><footer class="site-footer"><p>Footer text</p></footer></body></html>';
}
$plan = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => $files))->toArray()['source_reports']['wordpress_site_plan'];
$failures = array();
$posts = array_values(array_filter($plan['pages'], static fn(array $page): bool => 'post' === ($page['post_type'] ?? null)));
if (2 !== count($posts)) $failures[] = 'two posts';
$templates = array_values(array_filter($plan['templates'], static fn(array $entry): bool => str_starts_with($entry['slug'], 'single')));
if (1 !== count($templates)) $failures[] = 'one shared single-post template';
$template = $templates[0]['canonical_block_markup'] ?? '';
if (!str_contains($template, '<!-- wp:post-content')) $failures[] = 'shared template contains core/post-content';
if (!str_contains($template, '<!-- wp:post-title')) $failures[] = 'shared template factors article chrome';
foreach (array('1001', '1002') as $id) {
    if (str_contains($template, 'post=' . $id)) $failures[] = 'shared template excludes frame URL for ' . $id;
}
foreach ($posts as $post) {
    $slug = basename(dirname($post['source_path']));
    $content = $post['canonical_block_markup'];
    if (($post['route_template'] ?? 'single') !== ($templates[0]['slug'] ?? null)) $failures[] = 'both posts use the shared template';
    foreach (array('opening', 'middle', 'closing') as $paragraph) {
        if (!str_contains($content, $titles[$slug] . ' ' . $paragraph . ' paragraph.')) $failures[] = $slug . ' retains its ' . $paragraph . ' paragraph';
    }
    if (preg_match('/Shared header|Footer text|site-header|site-footer/', $content)) $failures[] = $slug . ' post_content excludes header and footer';
    $content = \Automattic\BlocksEngine\PhpTransformer\Tests\Support\IframeEmbedRendererFixture::render($content);
    $rendered = preg_replace('/<!-- wp:post-content(?:\s+\{.*?\})?\s*\/-->/', $content, $template);
    $id = 'alpha' === $slug ? '1001' : '1002';
    $otherId = 'alpha' === $slug ? '1002' : '1001';
    if (1 !== substr_count($rendered, '<iframe') || 1 !== preg_match_all('~<iframe\\b[^>]*src="https://comments\\.example\\.test/frame\\?post=' . $id . '"~', $rendered)) $failures[] = $slug . ' renders its own frame URL exactly once';
    if (str_contains($rendered, 'post=' . $otherId)) $failures[] = $slug . ' does not render another post\'s frame URL';
    if (!preg_match('~closing paragraph\..*<iframe~s', $content)) $failures[] = $slug . ' retains the frame after its body';
}
if (array() !== $failures) {
    fwrite(STDERR, 'FAIL: ' . implode("\nFAIL: ", $failures) . "\n");
    exit(1);
}
echo "post chrome URL attributes: passed\n";
