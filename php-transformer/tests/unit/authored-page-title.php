<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\WordPressSitePlan;

$compiled = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array(
    'index.html' => '<html><head><title>Home</title><style>.column{max-width:80rem;margin:auto;padding:20px}</style></head><body><main><section class="column"><h1>Welcome</h1><p>Home content.</p></section></main></body></html>',
    'about.html' => '<html><head><title>About</title></head><body><h1>About us</h1><p>Our story.</p></body></html>',
)))->toArray();
$plan = (new WordPressSitePlan())->fromCompilerResult($compiled);
$templates = array_column($plan['templates'], 'canonical_block_markup', 'slug');
if (str_contains($templates['page'] ?? '', 'wp:post-title')) throw new RuntimeException('Captured template slots must stay neutral.');

$filters = array();
function add_action(...$args): void {}
function remove_action(...$args): void {}
function remove_filter(...$args): void {}
function add_filter($hook, $callback, ...$args): void { $GLOBALS['filters'][$hook][] = $callback; }
function get_post_meta($id, $key, $single) { return 17 === $id ? 'captured-document' : ''; }
function get_post_type($id) { return 19 === $id ? 'post' : 'page'; }
function get_post($id) { return (object) array('post_content' => 20 === $id ? '<h1>Explicit heading</h1>' : '<p>Body</p>'); }
function has_block($name, $content) { return false; }
class WP_Block {
    public array $context;
    public function __construct(array $block = array(), array $context = array()) { $this->context = $context; }
    public function render(): string { return '<h1>New page</h1>'; }
}
$source = array_column($plan['writes'], null, 'target_path')['functions.php']['payload']['data'];
eval(preg_replace('/^<\?php\s*/', '', $source));
foreach (array(17, 18, 19, 20) as $id) {
    $instance = new WP_Block(array(), array('postId' => $id));
    $actual = '<div class="wp-block-post-content"><p>Body</p></div>';
    $original = $actual;
    foreach ($filters['render_block_core/post-content'] ?? array() as $callback) $actual = $callback($actual, array(), $instance);
    if (18 === $id) {
        if (!str_contains($actual, '<h1>New page</h1>') || !str_contains($actual, 'column') || !str_contains($actual, '<p>Body</p>')) throw new RuntimeException('New pages need their native title and captured column.');
    } elseif ($actual !== $original) throw new RuntimeException('Captured pages, posts and explicit headings must remain intact.');
}
echo "authored-page-title: passed\n";
