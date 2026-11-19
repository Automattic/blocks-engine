<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\ThemeBootstrap;

$filters = array();
function add_filter($hook, $callback, ...$args) { $GLOBALS['filters'][$hook][] = $callback; }
function add_action(...$args) {}
function remove_action(...$args) {}
function remove_filter(...$args) {}
function is_page($id) { return 42 === $id; }
function get_post_type($id) { return 'page'; }
function get_post_meta(...$args) { return $GLOBALS['identity']; }
function render_block($block) { return '<h1 class="wp-block-post-title">Authored title</h1>'; }
class WP_Block { public array $context = array('postId' => 42); }

$templates = array(
    array('slug' => 'page', 'canonical_block_markup' => '<!-- wp:post-content /-->'),
    array('slug' => 'page-policy', 'canonical_block_markup' => '<h1>Policy</h1><!-- wp:post-content /-->'),
);
$php = ThemeBootstrap::functionsPhp(array(), array(), array(), array(), $templates);
eval(substr($php, 5));
$assert = static function (bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); };
$callbacks = $filters['render_block_core/post-content'] ?? array();
$assert(1 === count($callbacks), 'A titled route layout supplies the missing generic page title.');
$render = $callbacks[0];
$GLOBALS['identity'] = '';
$assert(str_starts_with($render('<p>New body</p>', array(), new WP_Block()), '<h1'), 'Newly authored pages render a native title.');
$GLOBALS['identity'] = 'captured-page';
$assert('<p>Captured body</p>' === $render('<p>Captured body</p>', array(), new WP_Block()), 'Captured routes remain byte-identical.');
$GLOBALS['identity'] = '';
$assert('<h1>Own heading</h1>' === $render('<h1>Own heading</h1>', array(), new WP_Block()), 'An authored heading is not duplicated.');
$plain = ThemeBootstrap::functionsPhp(array(), array(), array(), array(), array($templates[0]));
$assert(!str_contains($plain, "add_filter( 'render_block_core/post-content'"), 'Sites without titled route layouts retain existing behavior.');
echo "page-authoring-title: ok\n";
