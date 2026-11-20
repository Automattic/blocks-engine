<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\TemplateSlugs;

$view = (new ArtifactCompiler())->compile(array(
    'entrypoint' => 'index.html',
    'files' => array(
        'index.html' => '<html><head><title>Home</title></head><body><main><h1>Home</h1><p>Welcome.</p><a href="menu.html">Menu</a></main></body></html>',
        'menu.html' => '<html><head><title>Menu</title></head><body><main><h1>Menu</h1><p>Seasonal dishes.</p></main></body></html>',
    ),
))->toWordPressSitePlanView();
$plan = $view['wordpress_site_plan'];
$templates = array_column($plan['templates'], 'canonical_block_markup', 'slug');
$failures = array();
if (1 !== substr_count($templates['page'], '<!-- wp:post-title')) $failures[] = 'New pages must render their native title exactly once.';
if (1 !== substr_count($templates['page'], '<!-- wp:post-content')) $failures[] = 'New pages must retain their editable content slot.';
foreach ($plan['pages'] as $page) {
    $slug = TemplateSlugs::forPage($page);
    if (!isset($templates[$slug])) $failures[] = 'Captured pages must retain a content-only route template.';
    elseif (str_contains($templates[$slug], '<!-- wp:post-title')) $failures[] = 'Captured titles must not be duplicated by the authoring fallback.';
    if (!str_contains($page['canonical_block_markup'], '<h1')) $failures[] = 'Captured headings must remain in editable content.';
}
foreach ($failures as $failure) fwrite(STDERR, "FAIL: $failure\n");
exit($failures ? 1 : 0);
