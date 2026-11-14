<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\WordPressSitePlan;

$one = '<script type="application/ld+json">{"@type":"BlogPosting","headline":"One","url":"https://example.com/2024/01/one.html","datePublished":"2024-01-01T12:00:00Z"}</script>';
$two = '<script type="application/ld+json">{"@type":"BlogPosting","headline":"Two","url":"https://example.com/2024/02/two.html","datePublished":"2024-02-02T12:00:00Z"}</script>';
$withCitation = str_replace('"headline":"One"', '"headline":"One","citation":{"@type":"ScholarlyArticle","url":"https://example.com/paper","datePublished":"2020-01-01"}', $one);
$files = array(
    'index.html' => '<html><body><main><h1>Home</h1><a href="/search/">All posts</a></main></body></html>',
    'search/index.html' => '<html><head>' . $one . $two . '</head><body><main><h1>Search</h1><article><h2><a href="/2024/01/one.html">One</a></h2><p>First summary.</p></article><article><h2><a href="/2024/02/two.html">Two</a></h2><p>Second summary.</p></article></main></body></html>',
    '2024/01/one.html' => '<html><head>' . $one . '</head><body><main><article><h1>One</h1><p>First publication.</p></article></main></body></html>',
    '2024/01/citation.html' => '<html><head>' . $withCitation . '</head><body><main><article><h1>One with citation</h1><p>A publication citing a paper.</p></article></main></body></html>',
);
$plan = (new WordPressSitePlan())->fromCompilerResult((new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => $files))->toArray());
$pages = array_column($plan['pages'], null, 'source_path');
$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};
$assert(isset($pages['search/index.html']), 'Search remains a routed listing page');
$assert('post' !== $pages['search/index.html']['post_type'], 'Multiple distinct BlogPosting nodes are not a single post');
$assert(array() === array_filter($plan['templates'], static fn(array $template): bool => 'search/index.html' === ($template['source_path'] ?? null) && str_starts_with((string) ($template['slug'] ?? ''), 'single-post-')), 'Search does not generate a single-post template');
$assert('post' === $pages['2024/01/one.html']['post_type'], 'A single BlogPosting remains a post');
$assert('post' === $pages['2024/01/citation.html']['post_type'], 'A single BlogPosting with a nested cited Article remains a post');
fwrite(STDOUT, "structured-article-listing: passed\n");
