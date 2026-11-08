<?php
declare(strict_types=1);

// Hosted builders (Squarespace) render a term archive as a bare listing: its
// only headings title the listed entries, its document title leads with the
// term name, and entries link back with rel="tag" to the URL-encoded term
// path, while the archive was captured at the form-encoded spelling. Paged
// and filtered views (`?offset=`, `?author=`) link the section by query and
// must not name it.

require dirname(__DIR__, 2) . '/vendor/autoload.php';
use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\WordPressSitePlan;

$assert = static function (bool $condition, string $message, mixed $context = null): void {
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n" . (null === $context ? '' : json_encode($context, JSON_UNESCAPED_SLASHES) . "\n"));
        exit(1);
    }
};
$origin = 'https://example.test';
$post = static fn(string $title, string $date, array $tags): string => '<html><head><title>' . $title . ' — News — Site</title><script type="application/ld+json">{"@type":"Article","datePublished":"' . $date . '"}</script></head><body><main><article><h1>' . $title . '</h1><p><a href="' . $origin . '/news?author=5">Jane Writer</a></p><p>Body of ' . $title . '.</p>'
    . implode('', array_map(static fn(string $tag): string => '<a href="' . $origin . '/news/tag/' . rawurlencode($tag) . '" rel="tag">' . $tag . '</a>', $tags)) . '</article></main></body></html>';
$card = static fn(string $slug, string $title): string => '<article><h1 class="blog-title"><a href="/news/' . $slug . '">' . $title . '</a></h1><p>Excerpt.</p></article>';
$archive = static fn(string $name, string $cards): string => '<html><head><title>' . $name . ' — News — Site</title></head><body><main>' . $cards . '</main></body></html>';
$files = array(
    'index.html' => '<html><head><title>Site</title></head><body><main><h1>Home</h1></main></body></html>',
    'news/index.html' => $archive('News', $card('one', 'Post one') . $card('two', 'Post two') . '<a href="' . $origin . '/news?offset=2">Older Posts</a>'),
    'blog/a/index.html' => $post('Blog a', '2021-01-01T00:00:00Z', array()),
    'blog/b/index.html' => $post('Blog b', '2021-02-01T00:00:00Z', array()),
    'news/one/index.html' => $post('Post one', '2020-05-22T16:47:00Z', array('granola', 'Community Service')),
    'news/two/index.html' => $post('Post two', '2019-01-02T10:00:00Z', array('granola', 'Community Service')),
    'news/tag/granola/index.html' => $archive('granola', $card('one', 'Post one') . $card('two', 'Post two')),
    'news/tag/Community+Service/index.html' => $archive('Community Service', $card('one', 'Post one') . $card('two', 'Post two')),
);
$result = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => $files, 'provenance' => array('source_url' => $origin . '/')))->toArray();
$plan = (new WordPressSitePlan())->fromCompilerResult($result);
$terms = array();
foreach ($plan['taxonomy_entities'] as $entity) $terms[$entity['taxonomy'] . ':' . $entity['name']] = count($entity['membership_source_paths']);
ksort($terms);
$assert(array('category:Blog' => 2, 'category:News' => 2, 'post_tag:Community Service' => 2, 'post_tag:granola' => 2) === $terms, 'titled archives project their tags and query links never name a section', $terms);
$assert(array() === array_filter($plan['pages'], static fn(array $page): bool => str_contains($page['source_path'], '/tag/')), 'no tag archive materializes as a page');

fwrite(STDOUT, "taxonomy archive titled term without heading: passed\n");
