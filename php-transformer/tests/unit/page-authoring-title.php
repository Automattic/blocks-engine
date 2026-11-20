<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

$files = array();
foreach (array('index' => 'Home', 'about' => 'About', 'contact' => 'Contact') as $slug => $title) {
    $files[$slug . '.html'] = '<!doctype html><html><head><title>' . $title . '</title></head><body><main><h1>' . $title . '</h1><p>Content for ' . $title . '.</p></main></body></html>';
}
$view = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => $files))->toWordPressSitePlanView();
$plan = $view['wordpress_site_plan'];
$templates = array_column($plan['templates'], 'canonical_block_markup', 'slug');
$failures = 0;
$assert = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) { ++$failures; fwrite(STDERR, "FAIL: $message\n"); }
};
$assert(str_contains($templates['page'], '<!-- wp:post-title {"level":1} /-->'), 'new pages render a native title');
$assert(1 === substr_count($templates['page'], '<!-- wp:post-content'), 'authoring template retains one content slot');
foreach ($plan['pages'] as $page) {
    if (!empty($page['entrypoint'])) continue;
    $assert(!isset($page['route_template'], $page['metadata']['post_meta']['_wp_page_template']), 'authoring fallback does not reassign captured routes after stylesheet placement');
    $slug = 'page-' . $page['slug'];
    $assert(isset($templates[$slug]) && !str_contains($templates[$slug], '<!-- wp:post-title'), 'captured page retains its title-free hierarchy override');
    $assert(str_contains($page['canonical_block_markup'], $page['title']), 'captured title remains content-owned');
}
exit($failures ? 1 : 0);
