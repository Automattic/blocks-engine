<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\WordPressSitePlan;

$assert = static function (bool $condition, string $message): void { if (! $condition) throw new RuntimeException($message); };

// Each post is one article whose footer row is shared, so the article becomes
// the template frame and post content starts with the article's children: the
// title/date header and a body of several paragraphs. The header must render
// from post data in the single template, as it does when the article stays in
// content, and the frame's marked slot must keep its attributes.
$post = static fn (string $title, int $day): string => '<!doctype html><html lang="en"><head><title>' . $title . '</title><meta property="article:published_time" content="2024-03-0' . $day . 'T00:00:00Z"></head><body><article class="post"><header class="post-header"><h1 class="post-title">' . $title . '</h1><p class="post-meta">March ' . $day . ', 2024</p></header><p>First paragraph of ' . $title . '.</p><p>Second paragraph of ' . $title . '.</p><footer class="post-footer"><a href="/index.html">All posts</a><span class="sep">·</span><a href="https://example.org/subscribe">Subscribe</a></footer></article></body></html>';
$files = array(array('path' => 'index.html', 'content' => '<!doctype html><html lang="en"><body><h1>Home</h1></body></html>'));
foreach (array(1 => 'Alpha note', 2 => 'Beta note', 3 => 'Gamma note') as $day => $title) {
    $files[] = array('path' => 'note-' . $day . '.html', 'metadata' => array('post_type' => 'post'), 'content' => $post($title, $day));
}
$plan = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => $files))->toArray()['source_reports']['wordpress_site_plan'];
WordPressSitePlan::assertValid($plan);

$single = array_column($plan['templates'], null, 'slug')['single']['canonical_block_markup'] ?? '';
$assert(str_contains($single, '<!-- wp:post-title {"level":1,"className":"post-title"} /-->'), 'The single template renders the title from post data.');
$assert(str_contains($single, '<!-- wp:post-date {"format":"F j, Y","className":"post-meta"} /-->'), 'The single template renders the date from post data.');
$assert(1 === substr_count($single, '<!-- wp:post-content'), 'The single template has one body slot.');
$assert(str_contains($single, '<!-- wp:post-content {"className":"' . WordPressSitePlan::FRAME_CONTENT_CLASS . '"} /-->'), 'The frame slot keeps its frame-content marker.');
$assert(strpos($single, 'wp:post-title') < strpos($single, 'wp:post-content') && strpos($single, 'wp:post-content') < strpos($single, '"slug":"footer"'), 'Header, body and footer keep the article order.');
foreach ($plan['pages'] as $page) {
    if ('post' !== $page['post_type']) continue;
    $markup = $page['canonical_block_markup'];
    $assert('single' === ($page['route_template'] ?? 'single'), $page['source_path'] . ' renders through the single template.');
    $assert(!str_contains($markup, 'post-title') && !str_contains($markup, 'post-meta'), $page['source_path'] . ' content carries no static title or date.');
    $assert(2 === substr_count($markup, '<!-- wp:paragraph'), $page['source_path'] . ' content keeps both body paragraphs.');
}
fwrite(STDOUT, "hoisted-article-post-chrome: ok\n");
