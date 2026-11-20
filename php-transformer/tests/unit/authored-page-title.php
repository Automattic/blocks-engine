<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\WordPressSitePlan;

$compiled = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array(
    'index.html' => '<html><head><title>Home</title></head><body><h1>Welcome</h1><p>Home content.</p></body></html>',
    'about.html' => '<html><head><title>About</title></head><body><h1>About us</h1><p>Our story.</p></body></html>',
)))->toArray();
$plan = (new WordPressSitePlan())->fromCompilerResult($compiled);
$templates = array_column($plan['templates'], 'canonical_block_markup', 'slug');
if (!str_contains($templates['page'] ?? '', 'blocksEngineAuthoredPageTitle')) {
    throw new RuntimeException('New pages need a native title in the default page template.');
}

// Exercise the emitted theme's public WordPress render filter, including the
// block context (not the queried object, which can belong to another post).
$filters = array();
function add_action(...$args): void {}
function remove_action(...$args): void {}
function remove_filter(...$args): void {}
function add_filter($hook, $callback, ...$args): void { $GLOBALS['filters'][$hook][] = $callback; }
function get_post_meta($id, $key, $single) { return 17 === $id ? 'captured-document' : ''; }
class WP_Block { public array $context = array(); }
$source = array_column($plan['writes'], null, 'target_path')['functions.php']['payload']['data'];
eval(preg_replace('/^<\?php\s*/', '', $source));
$block = array('attrs' => array('metadata' => array('blocksEngineAuthoredPageTitle' => true)));
$instance = new WP_Block();
foreach (array(17 => '', 18 => '<h1>New page</h1>') as $id => $expected) {
    $instance->context = array('postId' => $id);
    $actual = '<h1>New page</h1>';
    foreach ($filters['render_block_core/post-title'] ?? array() as $callback) $actual = $callback($actual, $block, $instance);
    if ($expected !== $actual) throw new RuntimeException('Title ownership is wrong for post ' . $id);
}
$instance->context = array('postId' => 17);
foreach ($filters['render_block_core/post-title'] ?? array() as $callback) {
    if ('explicit title' !== $callback('explicit title', array('attrs' => array()), $instance)) throw new RuntimeException('Explicit title blocks must remain intact.');
}
echo "authored-page-title: passed\n";
