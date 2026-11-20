<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\ThemeBootstrap;

$view = (new ArtifactCompiler())->compile(array(
    'entrypoint' => 'index.html',
    'files' => array(
        'index.html' => '<title>Home | Studio</title><main><h1>Welcome</h1><p>Home body.</p></main>',
        'access.html' => '<title>Accessibility | Studio</title><main><section><div class="title-column"><h1>Accessibility</h1></div><div class="body-column"><p>Access body.</p></div></section></main>',
        'about.html' => '<title>About | Studio</title><main><p>An intentionally heading-free page.</p></main>',
    ),
))->toWordPressSitePlanView();
$plan = $view['wordpress_site_plan'];
$source = ThemeBootstrap::functionsPhp($plan['assets'], array(), $plan['template_parts'], array(), $plan['templates'], $plan['pages']);
$failures = 0;
$assert = static function (bool $ok, string $message) use (&$failures): void {
    if (!$ok) { ++$failures; fwrite(STDERR, "FAIL: $message\n"); }
};
$assert(str_contains($source, '$blocks_engine_page_authoring_layout = '), 'new default pages receive a projected authoring layout');
$assert(str_contains($source, 'wp:post-title') && str_contains($source, 'title-column') && str_contains($source, 'body-column'), 'the layout retains title and body column ancestors');
$assert(str_contains($source, "get_post_meta( get_the_ID(), '_blocks_engine_reconciliation_identity', true )"), 'captured pages do not receive the new-page layout');
$pages = array_column($plan['pages'], null, 'source_path');
$assert(str_contains($pages['access.html']['canonical_block_markup'], 'Accessibility</h1>'), 'captured headings keep their original presentation');
$assert(!str_contains($pages['about.html']['canonical_block_markup'], 'wp:post-title'), 'heading-free pages remain heading-free');
$assert(!str_contains($pages['access.html']['canonical_block_markup'], 'wp:post-title'), 'captured titles are not duplicated');
// Execute the emitted public bootstrap adapter, including its ownership guard.
$adapter = substr($source, strpos($source, '$blocks_engine_page_authoring_layout = '));
$adapter = substr($adapter, 0, strpos($adapter, '}, 20, 2 );') + strlen('}, 20, 2 );'));
function add_filter(string $hook, callable $callback, int $priority, int $arguments): void { $GLOBALS['authoring_filter'] = $callback; }
function is_singular(string $type): bool { return true; }
function get_the_ID(): int { return 42; }
function get_queried_object_id(): int { return 42; }
function get_post_meta(int $id, string $key, bool $single): string { return $GLOBALS['captured_identity'] ?? ''; }
function get_page_template_slug(int $id): string { return ''; }
function do_blocks(string $markup): string { return str_replace('<!-- wp:post-title {"level":1} /-->', '<h1>New page</h1>', $markup); }
eval($adapter);
$authored = ($GLOBALS['authoring_filter'])('<div class="wp-block-post-content"><p>New body</p></div>', array());
$assert(str_contains($authored, '<h1>New page</h1>') && str_contains($authored, 'body-column') && 1 === substr_count($authored, 'New body'), 'the served new page has one native title and one body in its source column');
$assert(!str_contains($authored, 'Access body.'), 'the layout does not copy captured prose');
$GLOBALS['captured_identity'] = 'captured-page';
$assert('original captured content' === ($GLOBALS['authoring_filter'])('original captured content', array()), 'the runtime leaves imported pages byte-for-byte unchanged');
exit($failures ? 1 : 0);
