<?php
declare(strict_types=1);

// A listing that renders the same posts twice (a "most recent" scroller beside
// the main list) becomes one query loop per list. Card prose that links to its
// own post is that post's authored excerpt, a linked category row beside the
// date binds per post, a wordless own link renders
// nothing, and shared "Read more" text links each card to its own post (#6017).
require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\WordPressSitePlan;

$assert = static function (bool $condition, string $message): void { if (!$condition) { fwrite(STDERR, "FAIL: {$message}\n"); exit(1); } };
$posts = array('one' => 'Moving boxes everywhere, and the daunting task of unp...', 'two' => 'A short summary the article body never repeats...', 'three' => 'Spring is here, so it is time to tidy up the garden...');
$files = array('index.html' => '<main><h1>Home</h1><a href="/blog/">Blog</a></main>');
$recent = '';
$main = '';
foreach (array_keys($posts) as $index => $slug) {
    $title = 'Post ' . $slug;
    $date = '2026-10-0' . (3 - $index);
    $recent .= '<article class="recent"><h3><a href="/blog/' . $slug . '/">' . $title . '</a></h3><div class="excerpt"><p><a href="/blog/' . $slug . '/"></a></p></div></article>';
    $main .= '<article class="entry"><h2><a href="/blog/' . $slug . '/">' . $title . '</a></h2><div class="meta"><p class="categories"><a href="/blog/category/' . $slug . '-topic/">' . ucfirst($slug) . ' topic</a></p><p><time datetime="' . $date . '">' . $date . '</time></p></div><div class="excerpt"><p><a href="/blog/' . $slug . '/">' . $posts[$slug] . '</a></p></div><p><a href="/blog/' . $slug . '/">Read more</a></p></article>';
    $files['blog/' . $slug . '/index.html'] = '<html><head><title>' . $title . '</title></head><body><main><article itemscope itemtype="https://schema.org/BlogPosting"><h1 itemprop="headline">' . $title . '</h1><time itemprop="datePublished" datetime="' . $date . '">' . $date . '</time><p>Full article for ' . $slug . '.</p></article></main></body></html>';
}
$files['blog/index.html'] = '<main><h1>Blog</h1><section class="recent-list"><h2>Most recent</h2>' . $recent . '</section><section class="main-list">' . $main . '</section></main>';
$result = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => $files))->toArray();
$pages = array_column((new WordPressSitePlan())->fromCompilerResult($result)['pages'], null, 'source_path');
$markup = $pages['blog/index.html']['canonical_block_markup'];

$assert(2 === substr_count($markup, '<!-- wp:query '), 'each list of the same posts becomes its own query loop');
$assert(!str_contains($markup, 'wp:post-content'), 'no card renders the article body');
$assert(1 === substr_count($markup, '<!-- wp:post-excerpt'), 'only the list that shows excerpts renders them');
foreach ($posts as $slug => $excerpt) {
    $assert($excerpt === ($pages['blog/' . $slug . '/index.html']['metadata']['excerpt'] ?? null), 'post ' . $slug . ' keeps its card excerpt');
    $assert(!str_contains($markup, $excerpt), 'the excerpt of post ' . $slug . ' is not frozen into the template');
}
$assert(1 === preg_match('/"className":"categories[^"]*blocks-engine-listing-bound-meta","metadata":{"bindings":{"content":{"source":"core\/post-meta"/', $markup), 'a linked category row beside the date binds per post');
$assert(!preg_match('#href="[^"]*/blog/(?:one|two|three)/?"#', $markup), 'no card link is frozen to one post');
$assert(1 === preg_match('/<!-- wp:read-more \{[^}]*"content":"Read more"/', $markup), 'shared read-more text links each card to its own post');
echo "listing repeated lists: ok\n";
