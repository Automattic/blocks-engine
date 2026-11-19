<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\ThemeTemplates;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\TemplateSlugs;

$view = (new ArtifactCompiler())->compile(array(
    'entrypoint' => 'index.html',
    'files' => array(
        'index.html' => '<html><head><title>Home</title></head><body><main><h1>Welcome</h1><p>Home content</p></main></body></html>',
        'about.html' => '<html><head><title>About</title></head><body><main><h1>About us</h1><p>Our story</p></main></body></html>',
    ),
))->toWordPressSitePlanView();
$plan = $view['wordpress_site_plan'];
$rendered = ThemeTemplates::rendered($plan);
$failures = array();
$dom = new DOMDocument();
@$dom->loadHTML($rendered['page']);
$xpath = new DOMXPath($dom);
$main = $xpath->query('//main')->item(0);
if (null === $main || !str_contains($dom->saveHTML($main), 'wp:post-title') || !str_contains($dom->saveHTML($main), 'wp:post-content')) {
    $failures[] = 'New pages own a main landmark containing both native title and content';
}
if (!str_contains($rendered['page'], '<!-- wp:post-title {"level":1} /-->')) $failures[] = 'New pages render their native title';
if (!str_contains($rendered['page'], '<!-- wp:post-content')) $failures[] = 'New pages retain their content slot';
foreach ($plan['pages'] as $page) {
    $template = $rendered[TemplateSlugs::forPage($page)] ?? $rendered['page'];
    if (str_contains($template, '<!-- wp:post-title')) $failures[] = 'Captured pages must not duplicate content-owned headings';
    if (!str_contains($page['canonical_block_markup'], '<h1')) $failures[] = 'Captured headings remain intact';
}
foreach ($failures as $failure) fwrite(STDERR, "FAIL: {$failure}\n");
exit($failures ? 1 : 0);
