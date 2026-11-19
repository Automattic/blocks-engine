<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\ThemeTemplates;

function add_filter(string $hook, mixed $callback, int $priority = 10, int $args = 1): bool { $GLOBALS['filters'][$hook][] = $callback; return true; }
function add_action(string $hook, mixed $callback, int $priority = 10, int $args = 1): bool { return true; }
function remove_action(string $hook, mixed $callback, int $priority = 10): bool { return true; }
function is_singular(string $type): bool { return 'page' === $GLOBALS['type']; }
function get_the_ID(): int { return $GLOBALS['post_id']; }
function get_queried_object_id(): int { return 10; }
function get_post_meta(int $id, string $key, bool $single): string { return $GLOBALS['identity']; }
function get_page_template_slug(int $id): string { return $GLOBALS['template']; }
function do_blocks(string $markup): string {
    if ('<!-- wp:post-title {"level":1} /-->' !== $markup) throw new RuntimeException('Expected native title block');
    return '<h1>New page</h1>';
}

$plan = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array(
    'index.html' => '<main><h1>Home</h1><p>Welcome</p></main>',
    'about.html' => '<main><h1>About</h1><p>Our story</p></main>',
)))->toWordPressSitePlanView()['wordpress_site_plan'];
$writes = array_column($plan['writes'], null, 'target_path');
eval('?>' . $writes['functions.php']['payload']['data']);
$filter = $GLOBALS['filters']['render_block_data'][0];
$slot = array('blockName' => 'core/post-content', 'attrs' => array('className' => 'source-frame'), 'innerContent' => array());
$assert = static function (bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); };
$GLOBALS['type'] = 'page'; $GLOBALS['post_id'] = 10; $GLOBALS['identity'] = ''; $GLOBALS['template'] = '';
$authored = $filter($slot);
$assert(!empty($authored['attrs']['metadata']['blocksEngineAuthoredPage']), 'A newly authored page gets the title fallback');
$renderer = $GLOBALS['filters']['render_block_core/post-content'][0];
$assert('<main class="wp-block-group"><h1>New page</h1><p>Body</p></main>' === $renderer('<p>Body</p>', $authored), 'A native title and body render inside main');
$assert('<p>Body</p>' === $renderer('<p>Body</p>', $slot), 'Unmarked captured content renders unchanged');
$assert('source-frame' === $authored['attrs']['className'], 'The original content slot retains its frame attributes');
$assert($authored === $filter($authored), 'The content slot is marked only once');
$assert(1 === count($GLOBALS['filters']['render_block_core/post-content']), 'Only one fallback renderer is registered');
foreach (array('identity' => 'imported', 'template' => 'custom', 'type' => 'post', 'post_id' => 11) as $key => $value) {
    $previous = $GLOBALS[$key]; $GLOBALS[$key] = $value;
    $assert($slot === $filter($slot), 'Captured, custom, non-page and nested content stays unchanged: ' . $key);
    $GLOBALS[$key] = $previous;
}
$assert(!str_contains(ThemeTemplates::rendered($plan)['page'], 'wp:post-title'), 'The saved template stays content-only for captured pages');
echo "Page authoring title: passed\n";
