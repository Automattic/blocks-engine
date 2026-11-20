<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\WordPressSitePlan;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\ThemeBootstrap;

$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};
$artifact = array('entrypoint' => 'index.html', 'files' => array(array('path' => 'index.html', 'content' => '<html><body><p>Home</p></body></html>')));
foreach (array('first', 'second', 'third') as $index => $slug) {
    $day = $index + 1;
    $artifact['files'][] = array('path' => $slug . '.html', 'metadata' => array('post_type' => 'post'), 'content' => '<html><head><title>' . $slug . '</title><meta property="article:published_time" content="2024-03-0' . $day . 'T00:00:00Z"><style>.article-design { color: #123456; max-width: 720px; }</style></head><body><header><p>Publication</p></header><main><article class="article-design"><h1>' . $slug . '</h1><p><time datetime="2024-03-0' . $day . '">March ' . $day . ', 2024</time></p><p>Unique body of ' . $slug . '.</p></article></main><footer><p>Footer</p></footer></body></html>');
}
$plan = (new ArtifactCompiler())->compile($artifact)->toArray()['source_reports']['wordpress_site_plan'] ?? array();
$assert(array() !== $plan, 'Public compilation produces a site plan.');
WordPressSitePlan::assertValid($plan);
$templates = array_column($plan['templates'], null, 'slug');
$single = $templates['single']['canonical_block_markup'];
foreach (array('post-title', 'post-date', 'post-content') as $block) $assert(str_contains($single, '<!-- wp:' . $block), 'The default single carries ' . $block . '.');
foreach ($plan['pages'] as $post) {
    if ('post' !== $post['post_type']) continue;
    $assert('single' === ($post['route_template'] ?? 'single'), 'Ordinary posts use the hierarchy slot.');
    $assert(!isset($post['metadata']['post_meta']['_wp_page_template']), 'Ordinary posts have no template pin.');
}
$firstStyles = ThemeBootstrap::documentStylesheets($plan, 'first.html');
$assert(array() !== $firstStyles, 'The canonical design has an ordered presentation sequence.');
$assert($firstStyles === ThemeBootstrap::documentStylesheets($plan, 'second.html'), 'Imported siblings replay the same design sequence.');
$bootstrap = '';
foreach ($plan['writes'] as $write) if ('functions.php' === basename($write['target_path'])) $bootstrap = $write['payload']['data'];
$assert('' !== $bootstrap, 'The generated bootstrap is published.');
$assert(!preg_match("/is_singular\( 'post' \) && '[a-f0-9]{64}'/", $bootstrap), 'No stylesheet sequence is selected by imported post identity.');
$assert(str_contains($bootstrap, "'post_template'"), 'Editor stylesheet scopes belong to the post design.');
$assert(str_contains($bootstrap, "get_page_template_slug( get_queried_object_id() )"), 'New posts without identity metadata select the default design styles.');
fwrite(STDOUT, "single-post-authoring-design: ok\n");
