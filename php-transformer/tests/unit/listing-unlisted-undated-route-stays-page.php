<?php
declare(strict_types=1);

// A route under a listing joins the listing's post type only when the listing
// links to it or it carries its own publication date. An undated article no
// listing card links to stays a page at its own path; as a post it would take
// the import time and lead the Query Loop (#2929).

require dirname(__DIR__, 2) . '/vendor/autoload.php';
use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\WordPressSitePlan;

$assert = static function (bool $condition, string $message, mixed $context = null): void { if (!$condition) { fwrite(STDERR, "FAIL: {$message}\n" . (null === $context ? '' : json_encode($context, JSON_UNESCAPED_SLASHES) . "\n")); exit(1); } };
$card = static fn(string $href, string $title, string $datetime): string => '<a class="card" href="' . $href . '" itemscope itemtype="https://schema.org/BlogPosting"><h2 itemprop="headline">' . $title . '</h2><p itemprop="description">About ' . $title . '.</p>' . ('' === $datetime ? '' : '<time itemprop="datePublished" datetime="' . $datetime . '">' . $datetime . '</time>') . '</a>';
$article = static fn(string $title, string $datetime, string $type = 'BlogPosting'): string => '<main><article itemscope itemtype="https://schema.org/' . $type . '"><nav><a href="/">Home</a><a href="/blog/">Blog</a><span>' . $title . '</span></nav><h1>' . $title . '</h1>' . ('' === $datetime ? '' : '<time itemprop="datePublished" datetime="' . $datetime . '">' . $datetime . '</time>') . '<p>Body of ' . $title . '.</p></article></main>';
$files = array(
    'index.html' => '<main><h1>Home</h1><a href="/blog/">Blog</a></main>',
    'blog/one/index.html' => $article('Post one', '2026-10-03T02:10:54+00:00'),
    'blog/two/index.html' => $article('Post two', '2026-10-01T02:11:07+00:00'),
    // Listed through a relative link, with no date of its own.
    'blog/four/index.html' => $article('Post four', ''),
);
$listing = '<main><h1>Blog</h1><section>' . $card('/blog/one/', 'Post one', '2026-10-03T02:10:54+00:00') . $card('https://example.test/blog/two', 'Post two', '2026-10-01T02:11:07+00:00') . $card('four/index.html', 'Post four', '') . '</section></main>';
$plan = static function (array $files): array {
    $result = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => $files))->toArray();
    return array_column((new WordPressSitePlan())->fromCompilerResult($result)['pages'], null, 'source_path');
};

$without = $plan($files + array('blog/index.html' => $listing));
$with = $plan($files + array(
    'blog/index.html' => $listing,
    // An undated Article under /blog that no listing card links to.
    'blog/three/index.html' => '<html><head><title>Post three</title></head><body>' . $article('Post three', '', 'Article') . '</body></html>',
));

$three = $with['blog/three/index.html'] ?? array();
$assert('page' === ($three['post_type'] ?? null), 'an undated route the listing does not link to stays a page', $three['content_decision'] ?? null);
$assert(!isset($three['publication_timestamp']), 'the unlisted route invents no publication date');
$assert('three' === ($three['slug'] ?? null) && 'blog/index.html' === ($three['parent_source_path'] ?? null), 'the unlisted page keeps its path under the listing page', array($three['slug'] ?? null, $three['parent_source_path'] ?? null));
foreach (array('one' => '2026-10-03T02:10:54Z', 'two' => '2026-10-01T02:11:07Z') as $slug => $timestamp) {
    $post = $with['blog/' . $slug . '/index.html'];
    $assert('post' === $post['post_type'] && $timestamp === ($post['publication_timestamp'] ?? null), "listed post {$slug} stays a post with its own date", $post['content_decision']);
}
$assert('post' === ($with['blog/four/index.html']['post_type'] ?? null), 'an undated route the listing links to (relatively) stays one of its posts', $with['blog/four/index.html']['content_decision'] ?? null);
$assert($without['blog/index.html']['canonical_block_markup'] === $with['blog/index.html']['canonical_block_markup'], 'the listing Query Loop is unchanged by an unlisted route');

fwrite(STDOUT, "listing unlisted undated route stays page tests: passed\n");
