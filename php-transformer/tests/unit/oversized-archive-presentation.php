<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\ArchiveEntities;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\WordPressSitePlan;

// A declared term archive whose captured page exceeds the plan's presentation
// bound keeps its term and renders through the default archive template.
$files = array();
$add = static function (string $path, string $route, string $body, array $metadata = array()) use (&$files): void {
    $files[] = array('path' => $path, 'content' => '<html><head><title>' . substr(strip_tags($body), 0, 40) . '</title></head><body><main>' . $body . '</main></body></html>', 'metadata' => $metadata + array('route_path' => $route));
};
$add('index.html', '/', '<h1>Home</h1>');
foreach (array('one', 'two') as $post) $add('blog/' . $post . '.html', '/blog/' . $post, '<h1>Story ' . $post . '</h1><p>Body.</p>', array('post_type' => 'post', 'declared_content' => array('slug' => $post, 'published' => '2021-01-01T00:00:00Z', 'terms' => array('Wide'))));
$add('blog/tag/wide.html', '/blog/tag/wide', '<h1>Wide</h1>' . str_repeat('<p>' . str_repeat('Long captured listing text. ', 40) . '</p>', 1100), array('declared_content' => array('term_archive' => array('name' => 'Wide'))));
$result = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => $files))->toArray();
$plan = $result['source_reports']['wordpress_site_plan'] ?? null;
if (!is_array($plan)) throw new RuntimeException('An oversized archive must not invalidate the plan: ' . substr(json_encode($result['diagnostics'] ?? array()), 0, 800));
WordPressSitePlan::assertValid($plan);
$terms = array_column($plan['taxonomy_entities'], null, 'slug');
if (!isset($terms['wide']['archive']) || array_key_exists('presentation_markup', $terms['wide']['archive'])) throw new RuntimeException('The term keeps its archive without a presentation: ' . json_encode(array_keys($terms)));
if (array() === array_filter($plan['diagnostics'] ?? $result['diagnostics'] ?? array(), static fn(array $d): bool => 'wordpress_site_plan_taxonomy_archive_presentation_oversized' === ($d['code'] ?? null))) throw new RuntimeException('The dropped presentation is diagnosed.');
echo "ok\n";
