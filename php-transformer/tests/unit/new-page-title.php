<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

$plan = (new ArtifactCompiler())->compile(array(
    'entrypoint' => 'index.html',
    'files' => array(
        'index.html' => '<html><head><title>Home</title></head><body><main><p>Welcome</p></main></body></html>',
        'policy.html' => '<html><head><title>Policy</title></head><body><main><h1>Policy</h1><p>Policy text</p></main></body></html>',
    ),
))->toWordPressSitePlanView()['wordpress_site_plan'];
$templates = array_column($plan['templates'], 'canonical_block_markup', 'slug');
$writes = array_column($plan['writes'], null, 'target_path');
$bootstrap = $writes['functions.php']['payload']['data'];
$failures = 0;
$assert = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) { ++$failures; fwrite(STDERR, "FAIL: {$message}\n"); }
};
$assert(!str_contains($templates['page'], '<!-- wp:post-title'), 'captured generic template structure is unchanged');

$filters = array();
function add_filter(string $name, callable $callback, int $priority = 10, int $accepted = 1): void { global $filters; $filters[$name] = $callback; }
function get_the_ID(): int { return 42; }
function get_queried_object_id(): int { global $queried; return $queried; }
function is_singular(string $type): bool { global $singular; return $singular; }
function get_post_meta(int $id, string $key, bool $single): string { global $identity; return $identity; }
function render_block(array $block): string { return 'core/post-title' === $block['blockName'] && 1 === $block['attrs']['level'] ? '<h1>New page</h1>' : ''; }
$hook = '';
foreach (explode("\n", $bootstrap) as $i => $line) {
    if (str_starts_with($line, "add_filter( 'render_block_core/post-content', static function ( string \$content ):")) {
        $hook = implode("\n", array_slice(explode("\n", $bootstrap), $i, 6));
    }
}
$assert('' !== $hook, 'generated theme supplies missing generic page chrome');
if ('' !== $hook) {
    eval($hook);
    $filter = $filters['render_block_core/post-content'];
    // WordPress populates the underscored global during template rendering.
    $_wp_current_template_id = 'theme//page';
    $singular = true;
    $queried = 42;
    $identity = '';
    $content = '<div><p>Content</p></div>';
    $assert('<h1>New page</h1>' . $content === $filter($content), 'new generic pages render a native title');
    $identity = 'imported-page';
    $assert($content === $filter($content), 'captured pages retain their exact content');
    $identity = '';
    $assert('<h1>Authored</h1>' === $filter('<h1>Authored</h1>'), 'an authored title is not duplicated');
    $_wp_current_template_id = 'theme//page-custom';
    $assert($content === $filter($content), 'custom templates retain their own title ownership');
    $_wp_current_template_id = 'theme//front-page';
    $assert($content === $filter($content), 'front pages are unchanged');
    $_wp_current_template_id = 'theme//page';
    $queried = 99;
    $assert($content === $filter($content), 'query loop content is unchanged');
    $queried = 42;
    $singular = false;
    $assert($content === $filter($content), 'other post types are unchanged');
}
exit($failures ? 1 : 0);
