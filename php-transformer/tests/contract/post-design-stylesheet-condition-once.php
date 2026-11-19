<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\WordPressSitePlan;

$assert = static function (bool $condition, string $message): void { if (! $condition) throw new RuntimeException($message); };

// Sibling posts share one design, so support CSS that only their article
// markup needs (an inline link row) is selected by that design's template. The bootstrap must test that condition, and list
// that design's editor scope, once rather than once per imported post.
$count = 6;
$files = array(array('path' => 'index.html', 'content' => '<html><body><p>Home</p></body></html>'));
for ($day = 1; $day <= $count; ++$day) {
    $files[] = array('path' => 'post-' . $day . '.html', 'metadata' => array('post_type' => 'post'), 'content' => '<html><head><title>Post ' . $day . '</title><meta property="article:published_time" content="2024-03-0' . $day . 'T00:00:00Z"><style>.article-design{color:#123456;max-width:720px}</style></head><body><header><p>Publication</p></header><main><article class="article-design"><h1>Post ' . $day . '</h1><p><time datetime="2024-03-0' . $day . '">March ' . $day . ', 2024</time></p><p>Unique body ' . $day . '.</p><div class="byline"><a href="/index.html">All posts</a><span class="sep">·</span><a href="https://example.org/subscribe">Subscribe</a></div></article></main><footer><p>Footer</p></footer></body></html>');
}
$plan = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => $files))->toArray()['source_reports']['wordpress_site_plan'];
WordPressSitePlan::assertValid($plan);
foreach ($plan['pages'] as $page) if ('post' === $page['post_type']) $assert('single' === ($page['route_template'] ?? 'single'), 'Sibling posts share the single design.');

$bootstrap = '';
foreach ($plan['writes'] as $write) if ('functions.php' === basename($write['target_path'])) $bootstrap = $write['payload']['data'];
$assert('' !== $bootstrap, 'The generated bootstrap is published.');
$condition = "(is_singular( 'post' ) && '' === get_page_template_slug( get_queried_object_id() ))";
$enqueues = array_filter(explode("\n", $bootstrap), static fn (string $line): bool => str_contains($line, 'wp_enqueue_style') && str_contains($line, $condition));
$assert(array() !== $enqueues, 'Support CSS shared by the posts is selected by the single design.');
foreach ($enqueues as $line) $assert(1 === substr_count($line, $condition), 'Each enqueue tests the single design once, not once per post.');
$assert(1 === preg_match('/\$blocks_engine_presentation_scopes_rows = (.*);/', $bootstrap, $rows), 'The editor scope table is published.');
$assert(1 === substr_count($rows[1], "'kind'=>'post_template'"), 'The single design has one editor scope, not one per post.');
$assert(!str_contains($rows[1], "'source_path'=>'post-"), 'A post design editor scope names its template, not a member post.');
fwrite(STDOUT, "post-design-stylesheet-condition-once: ok\n");
