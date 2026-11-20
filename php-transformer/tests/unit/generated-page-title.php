<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

function add_filter(string $hook, mixed $callback, int $priority = 10, int $args = 1): bool { $GLOBALS['title_filters'][$hook][] = $callback; return true; }
function add_action(string $hook, mixed $callback, int $priority = 10, int $args = 1): bool { return true; }
function remove_action(string $hook, mixed $callback, int $priority = 10): bool { return true; }
function is_page(): bool { return true; }
function get_queried_object_id(): int { return 42; }
function get_post(int $id): object { return (object) array('post_content' => $GLOBALS['test_body'] ?? '<p>New content</p>'); }
function get_post_meta(int $id, string $key, bool $single): string { return $GLOBALS['test_identity'] ?? ''; }
function get_page_uri(int $id): string { return $GLOBALS['test_route'] ?? 'new-page'; }
function get_page_template_slug(int $id): string { return $GLOBALS['test_template'] ?? ''; }

$assert = static function (bool $condition, string $message): void {
    if (!$condition) { fwrite(STDERR, "FAIL: $message\n"); exit(1); }
};
$compiled = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array(
    'index.html' => '<html><head><title>Home</title></head><body><main><h1>Welcome</h1><p>Home content</p></main></body></html>',
    'booking/index.html' => '<html><head><title>Booking</title></head><body><main><h1>Book an event</h1><p>Booking content</p></main></body></html>',
)))->toArray();
$plan = $compiled['source_reports']['wordpress_site_plan'];
$templates = array_column($plan['templates'], null, 'slug');
$assert(str_contains($templates['page']['canonical_block_markup'], '<!-- wp:post-content /-->'), 'the saved content slot remains unchanged');
$writes = array_column($plan['writes'], null, 'target_path');
eval('?>' . $writes['functions.php']['payload']['data']);
$render = static function (array $block): array {
    foreach ($GLOBALS['title_filters']['render_block_data'] ?? array() as $filter) $block = $filter($block);
    return $block;
};
$slot = array('blockName' => 'core/post-content', 'attrs' => array(), 'innerContent' => array());
$new = $render($slot);
$assert('core/post-title' === ($new['innerBlocks'][0]['blockName'] ?? ''), 'a newly authored page gets a native title');
$assert($render($new['innerBlocks'][1]) === $new['innerBlocks'][1], 'the content child is not wrapped recursively');
foreach (array('identity' => 'captured-page', 'route' => 'booking', 'template' => 'custom', 'body' => '<h1>Authored heading</h1>') as $key => $value) {
    $GLOBALS['test_' . $key] = $value;
    $assert($slot === $render($slot), "$key prevents an extra title");
    unset($GLOBALS['test_' . $key]);
}
$heading = array('blockName' => 'core/post-title', 'attrs' => array(), 'innerContent' => array());
$assert($heading === $render($heading), 'explicit titles remain untouched');
fwrite(STDOUT, "generated-page-title: passed\n");
