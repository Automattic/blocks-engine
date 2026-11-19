<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

$document = static fn(string $title): string => '<!doctype html><html><head><title>' . $title . '</title></head><body><header><nav><a href="index.html">Home</a><a href="about.html">About</a></nav></header><main><h1>' . $title . '</h1><p>' . str_repeat('Page content. ', 30) . '</p></main><footer>Shared footer</footer></body></html>';
$plan = (new ArtifactCompiler())->compile(array(
    'entrypoint' => 'index.html',
    'files' => array('index.html' => $document('Welcome'), 'about.html' => $document('About us')),
))->toWordPressSitePlanView()['wordpress_site_plan'];
$functions = array_column($plan['writes'], 'payload', 'target_path')['functions.php']['data'];
// Exercise the generated public WordPress hook, with only the core APIs stubbed.
$filters = array();
function add_filter(string $hook, callable $callback, int $priority = 10, int $arguments = 1): void {
    $GLOBALS['filters'][$hook][] = $callback;
}
function is_singular(string $type): bool { return $GLOBALS['postType'] === $type; }
function get_the_ID(): int { return $GLOBALS['postId']; }
function get_queried_object_id(): int { return 42; }
function get_post_meta(int $id, string $key, bool $single): string { return $GLOBALS['identity']; }
$start = strpos($functions, "add_filter( 'render_block_data',");
$end = strpos($functions, '}, 10, 1 );', $start) + strlen('}, 10, 1 );');
eval(substr($functions, $start, $end - $start));
$filter = $filters['render_block_data'][0];
$slot = array('blockName' => 'core/post-content', 'attrs' => array('className' => 'original-slot'), 'innerBlocks' => array(), 'innerHTML' => '', 'innerContent' => array());
$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};
$postType = 'page'; $postId = 42; $identity = '';
$authored = $filter($slot);
$assert('core/group' === $authored['blockName'], 'New page receives a title and content group.');
$assert('core/post-title' === $authored['innerBlocks'][0]['blockName'] && 1 === $authored['innerBlocks'][0]['attrs']['level'], 'The title is a native level-one post title.');
$markedSlot = $authored['innerBlocks'][1];
$assert('original-slot' === $markedSlot['attrs']['className'], 'Content slot attributes survive.');
$assert($markedSlot === $filter($markedSlot), 'Rendering inner content cannot insert another title.');
$identity = $plan['pages'][0]['reconciliation_identity'];
$assert($slot === $filter($slot), 'Imported page content slots remain unchanged.');
$identity = ''; $postType = 'post';
$assert($slot === $filter($slot), 'Post lifecycles remain unchanged.');
$postType = 'page'; $postId = 99;
$assert($slot === $filter($slot), 'A nested query content slot is not the authored page.');
$templates = array_column($plan['templates'], 'canonical_block_markup', 'slug');
$assert(!str_contains($templates['page'], 'wp:post-title'), 'Captured generic page template is unchanged.');
echo "Page authoring title: 8 assertions passed\n";
