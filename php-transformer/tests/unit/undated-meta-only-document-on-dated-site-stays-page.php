<?php
declare(strict_types=1);

// Publish-time meta is also emitted on pages. A consistent dated post route
// pattern distinguishes those pages without platform or body-class heuristics.
require dirname(__DIR__, 2) . '/vendor/autoload.php';
use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\WordPressSitePlan;

$assert = static function (bool $condition, string $message, mixed $context = null): void { if (!$condition) { fwrite(STDERR, "FAIL: {$message}\n" . (null === $context ? '' : json_encode($context, JSON_UNESCAPED_SLASHES) . "\n")); exit(1); } };
$article = static fn(string $extra = ''): string => '<html><head><meta property="article:published_time" content="2025-04-23T12:00:00+00:00"><meta property="og:type" content="article">' . $extra . '</head><body><main><h1>Document</h1><p>Document body.</p></main></body></html>';
$plan = static function (array $files): array {
    $result = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => $files))->toArray();
    return array_column((new WordPressSitePlan())->fromCompilerResult($result)['pages'], null, 'source_path');
};
$home = array('index.html' => '<main><h1>Home</h1></main>');
$datedPosts = array(
    '2025/04/23/one/index.html' => $article(),
    '2025/04/24/two/index.html' => $article(),
);
$candidate = array('books/index.html' => $article());
$dated = $plan($home + $candidate + $datedPosts);
$page = $dated['books/index.html'];
$assert('page' === $page['post_type'], 'an undated meta-only document on a dated site stays a page', $page['content_decision']);
$assert('inferred' === $page['content_decision']['state'] && in_array('site:dated-post-routes', array_column($page['content_decision']['evidence'], 'source'), true), 'the page decision records the site-level reason', $page['content_decision']);
$assert('books' === $page['slug'] && '/books' === $page['route']['path'], 'the page keeps its undated route and slug');
foreach (array_keys($datedPosts) as $path) {
    $assert('post' === $dated[$path]['post_type'], 'dated posts remain posts', $dated[$path]['content_decision']);
}
$reordered = $plan($home + $datedPosts + $candidate);
$assert($page['content_decision'] === $reordered['books/index.html']['content_decision'], 'site evidence is independent of document order');

$undated = $plan($home + $candidate + array('one/index.html' => $article(), 'two/index.html' => $article()));
$assert('post' === $undated['books/index.html']['post_type'], 'the same meta-only document on an undated site remains a post', $undated['books/index.html']['content_decision']);

$strong = $plan($home + $datedPosts + array('story/index.html' => $article('<script type="application/ld+json">{"@context":"https://schema.org","@type":"BlogPosting"}</script>')));
$assert('post' === $strong['story/index.html']['post_type'], 'undated BlogPosting evidence remains a post on a dated site', $strong['story/index.html']['content_decision']);

// A stronger undated post makes the site's inferred route pattern mixed.
$mixed = $plan($home + $datedPosts + $candidate + array('story/index.html' => $article('<script type="application/ld+json">{"@context":"https://schema.org","@type":"BlogPosting"}</script>')));
$assert('post' === $mixed['books/index.html']['post_type'], 'mixed post routes do not establish a consistently dated pattern');

$declaredResult = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array(
    array('path' => 'index.html', 'content' => $home['index.html']),
    array('path' => '2025/04/23/one/index.html', 'content' => $article()),
    array('path' => '2025/04/24/two/index.html', 'content' => $article()),
    array('path' => 'declared/index.html', 'content' => $article(), 'metadata' => array('post_type' => 'post')),
)))->toArray();
$declaredPages = array_column((new WordPressSitePlan())->fromCompilerResult($declaredResult)['pages'], null, 'source_path');
$declared = $declaredPages['declared/index.html'];
$assert('post' === $declared['post_type'] && 'declared' === $declared['content_decision']['state'], 'a declared meta-only post is never overridden', $declared['content_decision']);

fwrite(STDOUT, "undated meta-only document on dated site stays page tests: passed\n");
