<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\WordPressSitePlan;

$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};
$files = array(
    'index.html' => '<html><body><main><h1>Welcome</h1><a href="/category.html">Category</a></main></body></html>',
    'category.html' => '<html><body><main><h1>Our categories</h1><p>Captured content.</p></main></body></html>',
);
$view = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => $files))->toWordPressSitePlanView();
$plan = $view['wordpress_site_plan'];
WordPressSitePlan::assertValid($plan);
$templates = array_column($plan['templates'], 'canonical_block_markup', 'slug');
$page = $templates['page'] ?? '';
$assert(str_contains($page, '<!-- wp:post-title {"level":1,"metadata":{"blocksEngineFallbackTitle":true}} /-->'), 'New pages must get a native level-one title.');
$assert(1 === substr_count($page, '<!-- wp:post-content'), 'The fallback must keep one content slot.');
$assert(strpos($page, '<!-- wp:post-title') < strpos($page, '<!-- wp:post-content'), 'The title precedes new content.');
$pages = array_column($plan['pages'], null, 'slug');
$captured = $pages['category']['canonical_block_markup'];
$assert(str_contains($captured, '<h1') && str_contains($captured, 'Our categories'), 'Captured headings stay content-owned.');
$assert(!str_contains($templates['front-page'] ?? '', 'blocksEngineFallbackTitle'), 'The front-page lifecycle is unchanged.');
$writes = array_column($plan['writes'], null, 'target_path');
$bootstrap = $writes['functions.php']['payload']['data'];
$assert(str_contains($bootstrap, "empty( \$block['attrs']['metadata']['blocksEngineFallbackTitle'] )"), 'Only the generated fallback title is conditional.');
$assert(str_contains($bootstrap, "get_post_field( 'post_content', \$post_id )") && str_contains($bootstrap, "\$tags->next_tag( 'H1' ) ? '' : \$content"), 'Captured H1 headings suppress the fallback without rerendering content.');
echo "page fallback title: passed\n";
