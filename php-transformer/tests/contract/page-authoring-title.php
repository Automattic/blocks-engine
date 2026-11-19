<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\WordPressSitePlan;

$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};
$files = array();
foreach (array('index' => 'Welcome', 'about' => 'Meet the team', 'policy' => 'Our policy') as $slug => $headline) {
    $files[] = array('path' => $slug . '.html', 'metadata' => array('post_type' => 'page'),
        'content' => '<html><head><title>' . ucfirst($slug) . ' | Example</title></head><body><main><h1>' . $headline . '</h1><p>Body for ' . $slug . '.</p></main></body></html>');
}
$plan = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => $files))->toArray()['source_reports']['wordpress_site_plan'];
WordPressSitePlan::assertValid($plan);
$templates = array_column($plan['templates'], 'canonical_block_markup', 'slug');
$assert(str_contains($templates['page'], '<!-- wp:post-title'), 'Fresh pages render their native title outside authored body content.');
foreach ($plan['pages'] as $page) {
    $slug = pathinfo($page['source_path'], PATHINFO_FILENAME);
    $headline = array('index' => 'Welcome', 'about' => 'Meet the team', 'policy' => 'Our policy')[$slug];
    $assert($headline === $page['title'], 'The page title follows the visible H1, not the SEO label.');
    $assert(ucfirst($slug) . ' | Example' === $page['document_metadata']['title'], 'SEO document metadata is preserved.');
    $template = $templates[$page['route_template'] ?? ($page['entrypoint'] ? 'front-page' : 'page-' . $page['slug'])] ?? '';
    $assert('' !== $template && !str_contains($template, '<!-- wp:post-title'), 'Captured routes retain a body-only template without a duplicate title.');
    $assert(str_contains($page['canonical_block_markup'], $headline), 'Captured headline content is retained for visual parity.');
}
fwrite(STDOUT, "page-authoring-title: ok\n");
