<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

// A source without author archives (Blogger) names each post's author only
// through a `rel="author"` byline. Those authors become entities, and the one
// shared single template renders each post's own author.
$authors = array('a' => 'Ada Lovelace', 'b' => 'Ada Lovelace', 'c' => 'Grace Hopper');
$files = array(array('path' => 'index.html', 'content' => '<!doctype html><html><head><title>Journal</title></head><body><header class="site-header"><p>Shared header</p></header><main><h1>Journal</h1><p><a href="/2024/01/a.html">A</a></p></main></body></html>'));
foreach ($authors as $slug => $author) {
    $date = '2024-01-1' . ord($slug) % 10;
    $files[] = array(
        'path' => '2024/0' . (ord($slug) % 3 + 1) . '/' . $slug . '.html',
        'metadata' => array('post_type' => 'post', 'declared_content' => array('title' => 'Post ' . $slug, 'slug' => $slug, 'published' => $date . 'T00:00:00Z')),
        'content' => '<!doctype html><html><head><title>Post ' . $slug . '</title></head><body><header class="site-header"><p>Shared header</p></header><main><article class="post"><h1 class="post-title">Post ' . $slug . '</h1>'
            . '<div class="post-header"><span class="byline"><span class="label">Posted by</span> <span class="fn"><a class="g-profile" href="https://www.blogger.com/profile/' . md5($author) . '" rel="author" title="author profile"><span>' . $author . '</span></a></span></span>'
            . '<p class="post-date"><time datetime="' . $date . '">' . $date . '</time></p></div>'
            . '<div class="post-body"><p>' . $slug . ' body one.</p><p>' . $slug . ' body two.</p></div></article></main><footer><p>Footer</p></footer></body></html>',
    );
}
$plan = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => $files))->toArray()['source_reports']['wordpress_site_plan'];
$failures = array();
$entities = array_column($plan['author_entities'], null, 'slug');
if (array('ada-lovelace', 'grace-hopper') !== array_keys($entities)) $failures[] = 'each byline author is an entity: ' . json_encode(array_keys($entities));
if (2 !== count($entities['ada-lovelace']['membership_source_paths'] ?? array()) || 'Grace Hopper' !== ($entities['grace-hopper']['name'] ?? null)) $failures[] = 'entities name their author and posts';
if (isset($entities['ada-lovelace']['archive']) || array('membership' => true, 'name' => true) !== ($entities['ada-lovelace']['evidence'] ?? null)) $failures[] = 'a byline author claims no archive';
$singles = array_values(array_filter($plan['templates'], static fn(array $template): bool => str_starts_with($template['slug'], 'single')));
$template = $singles[0]['canonical_block_markup'] ?? '';
if (1 !== count($singles)) $failures[] = 'posts by different authors share one template, got ' . count($singles);
if (!str_contains($template, '<!-- wp:post-author-name {"isLink":true,"className":"g-profile"} /-->') || !str_contains($template, 'Posted by')) $failures[] = 'the template keeps the label and renders the author';
if (str_contains($template, 'Ada Lovelace') || str_contains($template, 'Grace Hopper')) $failures[] = 'no author name is frozen in the template';
if (array() !== $failures) {
    fwrite(STDERR, 'FAIL: ' . implode("\nFAIL: ", $failures) . "\n");
    exit(1);
}
echo "ok\n";
