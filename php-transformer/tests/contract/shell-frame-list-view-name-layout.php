<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\WordPressSitePlan;

$assert = static function (bool $condition, string $message): void { if (! $condition) throw new RuntimeException($message); };

// Each post is one article: its title heading, its body and a footer row that
// every post repeats. The footer becomes a shared part inside the article, so
// the article is the frame. Its List View label is taken from the heading,
// i.e. from each post's own title; that label must not make every post a
// layout of its own.
$post = static fn (string $title, string $day): string => '<!doctype html><html lang="en"><head><title>' . $title . '</title><style>.post{max-width:640px}.post-footer{border-top:1px solid #ddd}</style></head><body><article class="post"><header class="post-header"><h1 class="post-title">' . $title . '</h1><p class="post-meta">Jan ' . $day . ', 2020</p></header><p>Body of ' . $title . '.</p><footer class="post-footer"><a href="/index.html">All posts</a><span class="sep">·</span><a href="https://example.org/subscribe">Subscribe</a></footer></article></body></html>';
$files = array(array('path' => 'index.html', 'content' => '<!doctype html><html lang="en"><head><title>Home</title></head><body><h1>Home</h1><ul><li><a href="/2020/01/01/alpha.html">Alpha note</a></li><li><a href="/2020/01/02/beta.html">Beta note</a></li><li><a href="/2020/01/03/gamma.html">Gamma note</a></li></ul></body></html>'));
foreach (array('alpha' => 'Alpha note', 'beta' => 'Beta note', 'gamma' => 'Gamma note') as $slug => $title) {
    $day = (string) (count($files));
    $files[] = array('path' => '2020/01/0' . $day . '/' . $slug . '.html', 'metadata' => array('post_type' => 'post'), 'content' => $post($title, $day));
}
$plan = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => $files))->toArray()['source_reports']['wordpress_site_plan'];
WordPressSitePlan::assertValid($plan);

$templates = array_column($plan['templates'], null, 'slug');
$assert(isset($templates['single']), 'The posts have a single template.');
$single = $templates['single']['canonical_block_markup'];
$assert(str_contains($single, '<!-- wp:post-content') && str_contains($single, '"slug":"footer"'), 'The single template carries the article frame with the shared footer.');
foreach (array('Alpha note', 'Beta note', 'Gamma note') as $title) $assert(!str_contains($single, $title), 'The shared frame is not named after the post "' . $title . '".');
$routeTemplates = array_filter(array_keys($templates), static fn (string $slug): bool => str_starts_with($slug, 'single-'));
$assert(array() === $routeTemplates, 'Posts that differ only by their frame label share the single template, not ' . implode(', ', $routeTemplates) . '.');
foreach ($plan['pages'] as $page) {
    if ('post' !== $page['post_type']) continue;
    $assert('single' === ($page['route_template'] ?? 'single'), $page['source_path'] . ' renders through the single template.');
    $assert(!isset($page['metadata']['post_meta']['_wp_page_template']), $page['source_path'] . ' has no template pin.');
    $assert(!str_contains($page['canonical_block_markup'], '"slug":"footer"'), $page['source_path'] . ' content leaves the footer to the frame.');
}
fwrite(STDOUT, "shell-frame-list-view-name-layout: ok\n");
