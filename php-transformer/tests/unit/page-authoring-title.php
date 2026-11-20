<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\TemplateSlugs;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\ThemeTemplates;

$plan = (new ArtifactCompiler())->compile(array(
    'entrypoint' => 'index.html',
    'files' => array(
        'index.html' => '<!doctype html><html><head><title>Home</title></head><body><main><h1>Home</h1><p>Welcome.</p></main></body></html>',
        'faqs.html' => '<!doctype html><html><head><title>FAQs</title></head><body><main><h1>FAQs</h1><p>Answers.</p></main></body></html>',
        'contact.html' => '<!doctype html><html><head><title>Contact</title></head><body><main><h1>Contact</h1><p>Call us.</p></main></body></html>',
    ),
))->toWordPressSitePlanView()['wordpress_site_plan'];
$rendered = ThemeTemplates::rendered($plan);
$failures = array();
if (!str_contains($rendered['page'], '<!-- wp:post-title {"level":1} /-->')) $failures[] = 'New pages must render a native H1 title.';
foreach ($plan['pages'] as $page) {
    $slug = TemplateSlugs::forPage($page);
    if (str_contains($rendered[$slug] ?? $rendered['page'], '<!-- wp:post-title')) $failures[] = 'Captured pages must not gain duplicate titles.';
    if (!str_contains($page['canonical_block_markup'], '<h1')) $failures[] = 'Captured page titles must remain editable in content.';
}
if (substr_count($rendered['page'], '<!-- wp:post-content') !== 1) $failures[] = 'The authoring template must retain one content slot.';
foreach ($failures as $failure) fwrite(STDERR, "FAIL: {$failure}\n");
exit($failures ? 1 : 0);
