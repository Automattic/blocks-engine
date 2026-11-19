<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\TemplateSlugs;

$view = (new ArtifactCompiler())->compile(array(
    'entrypoint' => 'index.html',
    'files' => array(
        'index.html' => '<html><body><main><h1>Welcome</h1><p>Home content.</p></main></body></html>',
        'about.html' => '<html><body><main><h1>About us</h1><p>Our story.</p></main></body></html>',
    ),
))->toWordPressSitePlanView();
$plan = $view['wordpress_site_plan'];
$templates = array_column($plan['templates'], 'canonical_block_markup', 'slug');
if (!str_contains($templates['page'], '<!-- wp:post-title {"level":1} /-->') || !str_contains($templates['page'], '<!-- wp:post-content')) {
    fwrite(STDERR, "FAIL: new pages must render their title and content\n");
    exit(1);
}
foreach ($plan['pages'] as $page) {
    $slug = TemplateSlugs::forPage($page);
    if (!isset($templates[$slug]) || str_contains($templates[$slug], '<!-- wp:post-title') || !str_contains($page['canonical_block_markup'], '<h1')) {
        fwrite(STDERR, "FAIL: imported pages must keep their captured heading without a duplicate title\n");
        exit(1);
    }
}
