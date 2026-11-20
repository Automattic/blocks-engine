<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

function add_filter(string $hook, mixed $callback, int $priority = 10, int $args = 1): bool { $GLOBALS['title_filters'][$hook][] = $callback; return true; }
function add_action(string $hook, mixed $callback, int $priority = 10, int $args = 1): bool { return true; }
function remove_action(string $hook, mixed $callback, int $priority = 10): bool { return true; }
function get_post_meta(int $id, string $key, bool $single): string { return 41 === $id && '_blocks_engine_reconciliation_identity' === $key ? 'captured-page' : ''; }
class WP_Block { public function __construct(public array $context) {} }

$assert = static function (bool $condition, string $message): void {
    if (!$condition) { fwrite(STDERR, "FAIL: $message\n"); exit(1); }
};
$compiled = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array(
    'index.html' => '<html><head><title>Home</title></head><body><main><h1>Welcome</h1><p>Home content</p></main></body></html>',
    'booking/index.html' => '<html><head><title>Booking</title></head><body><main><h1>Book an event</h1><p>Booking content</p></main></body></html>',
)))->toArray();
$plan = $compiled['source_reports']['wordpress_site_plan'];
$templates = array_column($plan['templates'], null, 'slug');
$marker = '"blocksEngineUncapturedTitle":true';
$assert(str_contains($templates['page']['canonical_block_markup'], '<!-- wp:post-title {"level":1,') && str_contains($templates['page']['canonical_block_markup'], $marker), 'the default page lifecycle supplies a native H1 title independently of captured content');
$writes = array_column($plan['writes'], null, 'target_path');
eval('?>' . $writes['functions.php']['payload']['data']);
$filters = $GLOBALS['title_filters']['render_block_core/post-title'] ?? array();
$assert(count($filters) > 0, 'the exported theme registers title rendering');
$render = static function (int $id, bool $marked = true) use ($filters): string {
    $html = '<h1 class="wp-block-post-title">Authored title</h1>';
    $block = array('attrs' => $marked ? array('metadata' => array('blocksEngineUncapturedTitle' => true)) : array());
    foreach ($filters as $filter) $html = $filter($html, $block, new WP_Block(array('postId' => $id)));
    return $html;
};
$assert(str_contains($render(42), 'Authored title'), 'new pages render the native title');
$assert('' === $render(41), 'captured pages keep their existing heading without an extra title');
$assert(str_contains($render(41, false), 'Authored title'), 'explicit native title blocks are never suppressed');
$assert(str_contains($render(42), '<h1'), 'title suppression is evaluated per block context, not cached across pages');
fwrite(STDOUT, "generated-page-title: passed\n");
