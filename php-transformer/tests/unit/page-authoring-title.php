<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

// Captured headings remain content-owned; a new page using the default
// template must still render its WordPress title without source content.
$result = (new ArtifactCompiler())->compile(array(
    'entrypoint' => 'index.html',
    'files' => array(
        'index.html' => '<html><head><title>Home</title></head><body><main><h1>Welcome</h1><p>Home body</p></main></body></html>',
        'services/session/index.html' => '<html><head><title>Session</title></head><body><main><h1>Session</h1><p>Session body</p></main></body></html>',
        'contact/index.html' => '<html><head><title>Contact</title></head><body><main><h1>Contact</h1><p>Contact body</p></main></body></html>',
    ),
))->toArray();
$plan = $result['source_reports']['wordpress_site_plan'];
$templates = array_column($plan['templates'], 'canonical_block_markup', 'slug');
$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        fwrite(STDERR, "FAIL: $message\n");
        exit(1);
    }
};
$assert(str_contains($templates['page'], '<!-- wp:post-title {"level":1} /-->'), 'default page renders a native title');
$assert(1 === substr_count($templates['page'], '<!-- wp:post-content'), 'default page renders editable content once');
foreach ($plan['pages'] as $page) {
    $slug = $page['route_template'] ?? (!empty($page['entrypoint']) ? 'front-page' : 'page-' . $page['slug']);
    $assert(isset($templates[$slug]), 'captured page has a preserving template');
    $assert(!str_contains($templates[$slug], '<!-- wp:post-title'), 'captured heading is not duplicated');
    if (empty($page['synthetic'])) $assert(str_contains($page['canonical_block_markup'], '<h1'), 'captured heading stays in editable content');
}
echo "page authoring title: passed\n";
