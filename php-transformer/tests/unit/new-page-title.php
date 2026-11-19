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
$assert(str_contains($templates['page'], '<!-- wp:post-title {"level":1,"className":"blocks-engine-new-page-title"} /-->'), 'new pages have a native level-one title');
$assert(!str_contains($templates['front-page'] ?? '', 'blocks-engine-new-page-title'), 'the captured front page does not inherit the new-page title');

// Exercise the generated runtime hook: imported content keeps its exact title
// ownership, while a new page gets the native title. Ordinary title blocks are
// unaffected, including titles in query loops and authored custom templates.
$filters = array();
function add_filter(string $name, callable $callback, int $priority = 10, int $accepted = 1): void { global $filters; $filters[$name] = $callback; }
function get_the_ID(): int { return 42; }
function get_post_meta(int $id, string $key, bool $single): string { global $identity; return $identity; }
$hook = '';
foreach (explode("\n", $bootstrap) as $line) {
    if (str_starts_with($line, "add_filter( 'render_block_core/post-title'") && str_contains($line, 'blocks-engine-new-page-title')) $hook = $line;
}
$assert('' !== $hook, 'generated theme includes the title ownership filter');
if ('' !== $hook) {
    eval($hook);
    $filter = $filters['render_block_core/post-title'];
    $block = array('attrs' => array('className' => 'blocks-engine-new-page-title'));
    $identity = 'imported-page';
    $assert('' === $filter('<h1>Policy</h1>', $block), 'captured pages do not gain duplicate titles');
    $identity = '';
    $assert('<h1>New page</h1>' === $filter('<h1>New page</h1>', $block), 'new pages render their title');
    $identity = 'imported-page';
    $assert('<h1>Custom title</h1>' === $filter('<h1>Custom title</h1>', array()), 'ordinary title slots remain untouched');
}
exit($failures ? 1 : 0);
