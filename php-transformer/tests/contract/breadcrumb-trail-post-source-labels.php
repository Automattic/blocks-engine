<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\WordPressSitePlan;

$assert = static function (bool $condition, string $message): void { if (! $condition) throw new RuntimeException($message); };

// Posts imported under a listing: Core builds their trail from the post's
// category and full title, so the runtime filter must replace the whole trail
// with the source crumbs (labels and links). The breadcrumb is shared chrome:
// it moves into one single template, so every post's trail comes from its own
// source breadcrumb rather than from the template's representative.
$fixture = dirname(__DIR__) . '/fixtures/breadcrumb-trail-post-site/';
$files = array();
foreach (array('index.html', 'news/index.html', 'news/short-label/index.html', 'news/second-entry/index.html', 'news/third-entry/index.html') as $path) {
    $files[$path] = file_get_contents($fixture . $path);
}
$plan = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => $files))->toArray()['source_reports']['wordpress_site_plan'];
WordPressSitePlan::assertValid($plan);
$posts = array();
foreach ($plan['pages'] ?? array() as $page) {
    if ('post' === $page['post_type']) $posts[$page['route']['path']] = $page;
}
$labels = array('/news/short-label' => 'Short label', '/news/second-entry' => 'Second', '/news/third-entry' => 'Third');
$assert(array_keys($labels) == array_intersect(array_keys($labels), array_keys($posts)), 'The dated child entries are imported as posts.');
$templates = array_column($plan['templates'], 'canonical_block_markup', 'slug');
$shared = array_unique(array_map(static fn (array $post): string => $post['route_template'] ?? 'single', array_intersect_key($posts, $labels)));
$assert(1 === count($shared) && str_contains($templates[reset($shared)] ?? '', '<!-- wp:breadcrumbs'), 'The breadcrumb is shared chrome in one single template.');
$source = (string) (array_column($plan['writes'], null, 'target_path')['functions.php']['payload']['data'] ?? '');
$assert(str_contains($source, 'block_core_breadcrumbs_items') && str_contains($source, '_blocks_engine_reconciliation_identity'), 'The theme runtime filters Core breadcrumb items by post identity.');
$assert(1 === preg_match('/\$blocks_engine_breadcrumb_post_trails = (array \(.*?\n\));\n/s', $source, $match), 'The runtime declares the post trail map.');
$trails = eval('return ' . $match[1] . ';');
foreach ($labels as $route => $label) {
    $post = $posts[$route];
    $assert(! str_contains($post['canonical_block_markup'], '<!-- wp:breadcrumbs'), "{$route}: the breadcrumb is not in post content.");
    $expected = array(array('label' => 'Home', 'route' => '/'), array('label' => 'News', 'route' => '/news/'), array('label' => $label, 'route' => null));
    $assert($expected === ($trails[$post['reconciliation_identity']] ?? null), "{$route}: its own source trail is keyed by its identity, with the self-linked current crumb unlinked.");
}
echo "breadcrumb-trail-post-source-labels: ok\n";
