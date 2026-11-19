<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\ThemeTemplates;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\WordPressSitePlan;

$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};
$slot = '<!-- wp:post-content /-->';
$templates = array();
foreach (array('page', 'front-page', 'page-menu') as $slug) {
    $templates[] = array('slug' => $slug, 'canonical_block_markup' => $slot, 'content_hash' => WordPressSitePlan::contentHash($slot));
}
$pages = array(
    array('post_type' => 'page', 'entrypoint' => true),
    array('post_type' => 'page', 'route_template' => 'page-menu'),
);
$result = ThemeTemplates::withAuthoringFallback($templates, $pages);
$assert(str_contains($result[0]['canonical_block_markup'], '<!-- wp:post-title {"level":1} /-->'), 'An authoring-only fallback supplies a native title.');
$assert($templates[1] === $result[1] && $templates[2] === $result[2], 'Every captured template remains byte-identical.');
$assert(WordPressSitePlan::contentHash($result[0]['canonical_block_markup']) === $result[0]['content_hash'], 'The fallback hash tracks its markup.');
$assert($result === ThemeTemplates::withAuthoringFallback($result, $pages), 'Adding authoring landmarks is idempotent.');
$pages[1] = array('post_type' => 'page');
$assert($templates === ThemeTemplates::withAuthoringFallback($templates, $pages), 'A captured page using the generic template prevents any change.');
$assert(array($templates[0]) === ThemeTemplates::withAuthoringFallback(array($templates[0]), array($pages[0])), 'An entry route falling back to page.html is also protected.');

// Public compiler regression: captured pages that can share the generic layout
// must keep doing so, without a render filter or an SSI-unknown feature.
$plan = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array(
    'index.html' => '<html><body><main><h1>Home</h1><a href="menu.html">Menu</a></main></body></html>',
    'menu.html' => '<html><body><main><h1>Menu</h1><p>Lunch choices.</p></main></body></html>',
)))->toWordPressSitePlanView()['wordpress_site_plan'];
$markup = array_column($plan['templates'], 'canonical_block_markup', 'slug');
$assert(!str_contains($markup['page'], 'wp:post-title'), 'The compiler preserves an occupied page fallback.');
$assert(!in_array('fallback_page_title', array_column($plan['theme']['bootstrap_units'], 'feature'), true), 'Native title blocks need no new bootstrap feature.');
echo "page fallback title: passed\n";
