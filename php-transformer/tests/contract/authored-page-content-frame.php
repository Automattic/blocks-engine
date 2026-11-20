<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\WordPressSitePlan;

$assert = static function (bool $condition, string $message): void { if (!$condition) throw new RuntimeException($message); };
$document = static fn(string $title): string => '<html><head><style>.container{max-width:1200px;margin:auto}.intro{max-width:576px}.hero{padding:40px;background:#123;color:#fff}</style></head><body><header>Shared header</header><main><section class="hero"><div class="container"><h1>' . $title . '</h1><p class="intro">Captured intro for ' . $title . '</p></div></section><section><p>Other captured content</p></section></main><footer>Shared footer</footer></body></html>';
$plan = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array('index.html' => $document('Home'), 'about.html' => $document('About'), 'contact.html' => $document('Contact'))))->toWordPressSitePlanView()['wordpress_site_plan'];
WordPressSitePlan::assertValid($plan);
$bootstrap = '';
foreach ($plan['writes'] as $write) if (str_ends_with($write['target_path'], 'functions.php')) $bootstrap = $write['payload']['data'];
$assert(str_contains($bootstrap, '$blocks_engine_authored_page = '), 'The public compiler supplies a native page content frame.');
$start = strpos($bootstrap, '$blocks_engine_authored_page = ');
$end = strpos($bootstrap, '}, 11 );', $start);
$assert(false !== $end, 'The fallback is installed as a post-content render filter.');

// Exercise the emitted WordPress callback with a minimal runtime. Captured
// pages must take the byte-identical return path, including custom templates.
$identity = ''; $template = ''; $type = 'page'; $front = 0; $filter = null;
function get_the_ID(): int { return 42; }
function get_post_type($id): string { return $GLOBALS['type']; }
function get_page_template_slug($id): string { return $GLOBALS['template']; }
function get_option($key): int { return $GLOBALS['front']; }
function get_post_meta($id, $key, $single): string { return $GLOBALS['identity']; }
function add_filter($name, $callback, $priority): void { $GLOBALS['filter'] = $callback; }
function do_blocks($markup): string { return $markup; }
eval(substr($bootstrap, $start, $end + strlen('}, 11 );') - $start));
$body = '<div class="wp-block-post-content"><p>New page body</p></div>';
$output = $filter($body);
$assert(str_contains($output, 'wp:post-title') && str_contains($output, 'container') && str_contains($output, 'intro'), 'New pages inherit the title and source column boxes.');
$assert(1 === substr_count($output, $body) && !str_contains($output, 'Captured intro') && !str_contains($output, 'Other captured content'), 'Only the new body renders, exactly once.');
foreach ($plan['pages'] as $page) $assert(str_contains($page['canonical_block_markup'], '<h1') && !str_contains($page['canonical_block_markup'], 'wp:post-title'), 'Imported headings remain content-owned.');
$identity = 'captured-page';
$assert($body === $filter($body), 'Captured default pages are untouched.');
$identity = ''; $template = 'page-custom';
$assert($body === $filter($body), 'Custom templates are untouched.');
$template = ''; $front = 42;
$assert($body === $filter($body), 'The static front page is untouched.');
$front = 0; $type = 'post';
$assert($body === $filter($body), 'Other post types are untouched.');
echo "authored-page-content-frame: ok\n";
