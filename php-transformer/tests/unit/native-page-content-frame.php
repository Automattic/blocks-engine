<?php
declare(strict_types=1);

require getenv('BLOCKS_ENGINE_AUTOLOAD') ?: dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

// Native pages borrow the text column, not the imported copy or its media.
$files = array();
foreach (array('index', 'about', 'contact') as $slug) {
    $files[] = array('path' => $slug . '.html', 'content' => '<html><body><div class="site"><header><nav><a href="index.html">Home</a><a href="about.html">About</a></nav></header><main><div class="text-column" style="max-width:710px;margin-left:auto"><h1>' . $slug . '</h1><p>Route-owned copy.</p></div><div class="aside"><p>Separate column.</p></div></main><footer><p>Shared footer</p></footer></div></body></html>');
}
$plan = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => $files))->toWordPressSitePlanView()['wordpress_site_plan'];
$templates = array_column($plan['templates'], null, 'slug');
$generic = $templates['page']['canonical_block_markup'];
if (!str_contains($generic, 'text-column') || str_contains($generic, 'Route-owned copy') || str_contains($generic, 'Separate column') || str_contains($generic, 'class="wp-block-group aside')) {
    throw new RuntimeException('The native page template must put its content slot in the text column without route content or siblings.');
}
foreach ($plan['pages'] as $page) {
    if (!str_contains($page['canonical_block_markup'], 'Route-owned copy.') || 1 !== substr_count($page['canonical_block_markup'], 'class="wp-block-group text-column')) throw new RuntimeException('Captured content must retain exactly one text column.');
    $slug = $page['metadata']['post_meta']['_wp_page_template'] ?? ($page['entrypoint'] ? 'front-page' : 'page');
    if (str_contains($templates[$slug]['canonical_block_markup'], 'text-column')) throw new RuntimeException('Captured routes must not receive the native content frame twice.');
}
// A majority shell is not a majority content design. Distinct inner-page
// columns must not collapse just because their surrounding chrome agrees.
$files = array();
foreach (array('index', 'about', 'legal', 'privacy', 'terms') as $slug) {
    $column = in_array($slug, array('index', 'about'), true) ? 'editorial-column' : 'legal-column';
    $files[] = array('path' => $slug . '.html', 'content' => '<html><body><div class="site"><header><nav><a href="index.html">Home</a><a href="about.html">About</a></nav></header><main><div class="' . $column . '"><p>Body copy for ' . $slug . '.</p></div></main><footer><p>Shared footer</p></footer></div></body></html>');
}
$plan = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => $files))->toWordPressSitePlanView()['wordpress_site_plan'];
$templates = array_column($plan['templates'], null, 'slug');
// Three legal columns prove a dominant authoring layout despite the earlier
// editorial page. The entry route and other native lifecycles keep just chrome.
if (!str_contains($templates['page']['canonical_block_markup'], 'legal-column')) throw new RuntimeException('Choose the dominant content frame, not just the dominant shell.');
foreach (array('front-page', 'index', 'archive', 'single', '404') as $slug) {
    if (str_contains($templates[$slug]['canonical_block_markup'] ?? '', 'legal-column')) throw new RuntimeException('Body ancestry must not leak into other lifecycle templates.');
}
echo "Native page content frame: passed\n";
