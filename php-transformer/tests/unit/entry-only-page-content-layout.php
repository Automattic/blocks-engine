<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\WordPressSitePlan;

$source = '<html><head><title>Home</title><style>.wrap{max-width:1200px;margin:auto}</style></head><body><main><section class="wrap"><h1>Home</h1><p>Welcome to our site.</p></section></main></body></html>';
$result = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array('index.html' => $source)))->toArray();
$plan = (new WordPressSitePlan())->fromCompilerResult($result);
$templates = array_column($plan['templates'], 'canonical_block_markup', 'slug');
if (str_contains($templates['page'], '"type":"constrained"')) throw new RuntimeException('The captured front page fallback must retain its source layout.');
$writes = array_column($plan['writes'], null, 'target_path');
$bootstrap = $writes['functions.php']['payload']['data'];
// Execute the public plan's emitted WordPress filter, not an internal helper.
$callbacks = array();
function add_filter($hook, $callback) { $GLOBALS['callbacks'][] = $callback; }
function is_singular($type) { return 'page' === $type; }
function get_queried_object_id() { return 42; }
function get_post_meta($id, $key, $single) { return $GLOBALS['identity']; }
function wp_get_global_settings($path) { return '680px'; }
if (!preg_match('~add_filter\( \'render_block_data\', static function \( array \\$block \): array \{\n    if \( \'core/post-content\'.*?\n\} \);~s', $bootstrap, $match)) throw new RuntimeException('Missing authored page layout filter.');
eval($match[0]);
$filter = $callbacks[0];
$slot = array('blockName' => 'core/post-content', 'attrs' => array());
$identity = 'captured-home';
if ($filter($slot) !== $slot) throw new RuntimeException('Captured page geometry changed.');
$identity = '';
if (($filter($slot)['attrs']['layout']['contentSize'] ?? '') !== '680px') throw new RuntimeException('New page must use the native content column.');
$explicit = $slot;
$explicit['attrs']['layout'] = array('type' => 'default');
if ($filter($explicit) !== $explicit) throw new RuntimeException('Explicit layout changed.');
$framed = $slot;
$framed['attrs']['className'] = WordPressSitePlan::FRAME_CONTENT_CLASS;
if ($filter($framed) !== $framed) throw new RuntimeException('Hoisted frame changed.');
echo "entry-only-page-content-layout: passed\n";
