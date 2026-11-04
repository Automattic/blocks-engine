<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\WordPressSitePlan;

$assert = static function (bool $condition, string $message): void { if (! $condition) throw new RuntimeException($message); };

// Posts imported under a listing: Core builds their trail from the post's
// category and full title, so the runtime filter must replace the whole trail
// with the source crumbs (labels and links).
$fixture = dirname(__DIR__) . '/fixtures/breadcrumb-trail-post-site/';
$files = array();
foreach (array('index.html', 'news/index.html', 'news/short-label/index.html', 'news/second-entry/index.html') as $path) {
    $files[$path] = file_get_contents($fixture . $path);
}
$plan = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => $files))->toArray()['source_reports']['wordpress_site_plan'];
WordPressSitePlan::assertValid($plan);
$post = null;
foreach ($plan['pages'] ?? array() as $page) {
    if ('/news/short-label' === $page['route']['path']) $post = $page;
}
$assert(null !== $post && 'post' === $post['post_type'], 'The dated child entry is imported as a post.');
$source = (string) (array_column($plan['writes'], null, 'target_path')['functions.php']['payload']['data'] ?? '');
$assert(str_contains($source, 'block_core_breadcrumbs_items'), 'The theme runtime filters Core breadcrumb items.');
$assert(isset($post['reconciliation_identity']) && str_contains($source, var_export($post['reconciliation_identity'], true) . ' =>') && str_contains($source, '_blocks_engine_reconciliation_identity') && str_contains($source, "'route' => NULL") && str_contains($source, "'label' => 'Short label'"), 'The post route records its source trail.');
$assert(str_contains($source, "'label' => 'News'") && str_contains($source, "'route' => '/news/'"), 'The trail keeps the listing crumb and its link.');

$assert(str_contains($source, "'label' => 'Second'"), 'A self-linked current crumb is recorded.');
echo "breadcrumb-trail-post-source-labels: ok\n";
