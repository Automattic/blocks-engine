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
// Exercise the emitted filter through the compiler's public artifact, with
// minimal WordPress doubles. Captured pages may deliberately have no H1.
final class WP_Block
{
    public array $context = array('postId' => 42);
}
final class WP_HTML_Tag_Processor
{
    public function __construct(private string $html) {}
    public function next_tag(string $tag): bool { return str_contains($this->html, '<' . strtolower($tag)); }
}
function get_post_meta($id, $key, $single) { return $GLOBALS['captured_identity']; }
function get_post_field($field, $id) { return $GLOBALS['saved_content']; }
function add_filter($name, $callback, ...$args) { $GLOBALS['title_filter'] = $callback; }
$start = strpos($bootstrap, "add_filter( 'render_block_core/post-title', static function ( string \$content, array \$block, WP_Block \$instance )");
$end = strpos($bootstrap, '}, 10, 3 );', $start);
eval(substr($bootstrap, $start, $end + strlen('}, 10, 3 );') - $start));
$block = array('attrs' => array('metadata' => array('blocksEngineFallbackTitle' => true)));
foreach (array(
    array('imported-page', '<p>No captured title.</p>', ''),
    array('imported-page', '<h1>Captured title</h1>', ''),
    array('', '<p>New page content.</p>', '<h1>New page</h1>'),
    array('', '<h1>Authored title</h1>', ''),
) as [$identity, $saved, $expected]) {
    $GLOBALS['captured_identity'] = $identity;
    $GLOBALS['saved_content'] = $saved;
    $assert($expected === ($GLOBALS['title_filter'])('<h1>New page</h1>', $block, new WP_Block()), 'Fallback titles must preserve captured presentation and supply new page titles.');
}
$assert('<h1>Explicit title</h1>' === ($GLOBALS['title_filter'])('<h1>Explicit title</h1>', array(), new WP_Block()), 'Explicit post-title blocks remain unchanged.');
echo "page fallback title: passed\n";
