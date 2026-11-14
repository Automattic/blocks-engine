<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\WordPressSitePlan;

// A declared term archive whose term no post carries keeps its own captured
// listing. It is never offered a section's fixed query loop, which an
// archive presentation cannot hold.
$files = array();
$add = static function (string $path, string $route, string $body, array $metadata = array()) use (&$files): void {
    $files[] = array('path' => $path, 'content' => '<html><head><title>' . strip_tags($body) . '</title></head><body><main>' . $body . '</main></body></html>', 'metadata' => $metadata + array('route_path' => $route));
};
$cards = static fn(array $posts): string => implode('', array_map(static fn(string $p): string => '<article><h2><a href="/blog/' . $p . '">Story ' . $p . '</a></h2><p>Excerpt ' . $p . '.</p></article>', $posts));
$add('index.html', '/', '<h1>Home</h1>');
foreach (array('blog' => array('one', 'two', 'three'), 'news' => array('four', 'five')) as $section => $posts) foreach ($posts as $i => $post) {
    $add($section . '/' . $post . '.html', '/' . $section . '/' . $post, '<h1>Story ' . $post . '</h1><p>Body.</p>', array('post_type' => 'post', 'declared_content' => array('slug' => $post, 'published' => '2021-0' . (5 - $i) . '-01T00:00:00Z', 'terms' => array('Travel'))));
}
$add('blog.html', '/blog', '<h1>Blog</h1>' . $cards(array('one', 'two', 'three')));
$add('terms/quiet.html', '/terms/quiet', '<h1>Quiet</h1>' . $cards(array('one', 'two', 'three')), array('declared_content' => array('term_archive' => array('name' => 'Quiet'))));
$result = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => $files))->toArray();
$plan = $result['source_reports']['wordpress_site_plan'] ?? null;
if (!is_array($plan)) throw new RuntimeException('An empty declared term archive must not invalidate the plan: ' . substr(json_encode($result['diagnostics'] ?? array_keys($result)), 0, 1500));
WordPressSitePlan::assertValid($plan);
$terms = array_column($plan['taxonomy_entities'], null, 'slug');
if (!isset($terms['quiet']) && !in_array('terms/quiet.html', array_column($plan['pages'], 'source_path'), true)) throw new RuntimeException('The empty term archive is neither a term nor a page.');
echo "ok\n";
