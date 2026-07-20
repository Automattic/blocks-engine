<?php
declare(strict_types=1);

// Run with a standard WordPress test-suite installation, for example:
// WP_TESTS_DIR=/path/to/wordpress-tests-lib composer test:wordpress-integration
$testsDir = getenv('WP_TESTS_DIR');
if (!is_string($testsDir) || !is_file($testsDir . '/includes/bootstrap.php')) {
    if ('1' === getenv('REQUIRE_WP_TESTS')) {
        fwrite(STDERR, "WP_TESTS_DIR must point to a WordPress test-suite installation.\n");
        exit(1);
    }
    fwrite(STDOUT, "wordpress-site-plan WordPress integration skipped: WP_TESTS_DIR is unavailable.\n");
    exit(0);
}

require dirname(__DIR__, 2) . '/vendor/autoload.php';
require $testsDir . '/includes/bootstrap.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\WordPressSitePlanResolver;

$assert = static function (bool $condition, string $message): void { if (!$condition) throw new RuntimeException($message); };
$theme = 'blocks-engine-site-plan-contract-' . substr(hash('sha256', __FILE__), 0, 8);
$themeDir = WP_CONTENT_DIR . '/themes/' . $theme;
$previousTheme = get_stylesheet();
$previousTemplate = get_template();
$previousShowOnFront = get_option('show_on_front');
$previousPageOnFront = get_option('page_on_front');
$pageIds = array();
try {
if (!is_dir($themeDir) && !mkdir($themeDir, 0777, true) && !is_dir($themeDir)) throw new RuntimeException('Could not create integration theme directory.');
$result = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array(
    'index.html' => '<header><p>Integration Header</p></header><main><img src="assets/logo.svg"><h1>Home</h1></main><footer><p>Integration Footer</p></footer>',
    'assets/logo.svg' => '<svg xmlns="http://www.w3.org/2000/svg"/>',
)))->toArray();
$plan = $result['source_reports']['wordpress_site_plan'] ?? array();
$resolved = (new WordPressSitePlanResolver())->resolve($plan, array('theme_uri' => home_url('/wp-content/themes/' . $theme)));
foreach ($resolved['writes'] as $write) {
    $path = $themeDir . '/' . $write['target_path'];
    if (!is_dir(dirname($path)) && !mkdir(dirname($path), 0777, true) && !is_dir(dirname($path))) throw new RuntimeException('Could not create theme write directory.');
    if (false === file_put_contents($path, 'base64' === $write['payload']['encoding'] ? base64_decode($write['payload']['data'], true) : $write['payload']['data'])) throw new RuntimeException('Could not write materialized theme file.');
}
wp_clean_themes_cache();
$wpTheme = wp_get_theme($theme);
$assert($wpTheme->exists(), 'WordPress recognizes the materialized block theme.');
switch_theme($theme);
foreach ($resolved['pages'] as $page) { $id = wp_insert_post(array('post_type' => 'page', 'post_status' => 'publish', 'post_title' => $page['title'], 'post_name' => $page['slug'], 'post_content' => $page['resolved_block_markup']), true); if (is_wp_error($id)) throw new RuntimeException($id->get_error_message()); $pageIds[$page['reconciliation_identity']] = $id; }
foreach ($resolved['operations'] as $operation) if ('site_reading' === $operation['kind']) { update_option('show_on_front', $operation['show_on_front']); update_option('page_on_front', $pageIds[$operation['front_page_reconciliation_identity']]); }
$assert('page' === get_option('show_on_front') && $pageIds[$resolved['operations'][0]['front_page_reconciliation_identity']] === (int) get_option('page_on_front'), 'WordPress applied the front-page operation.');
$front = file_get_contents($themeDir . '/templates/front-page.html'); if (false === $front) throw new RuntimeException('Could not read front-page template.');
$post = get_post((int) get_option('page_on_front')); if (!$post) throw new RuntimeException('Could not load front page.');
global $wp_query; $wp_query->is_front_page = true; $wp_query->is_page = true; $wp_query->post = $post; $wp_query->posts = array($post); setup_postdata($post);
$rendered = do_blocks($front); wp_reset_postdata();
$assert(1 === substr_count($rendered, 'Integration Header') && 1 === substr_count($rendered, 'Integration Footer'), 'WordPress renders each bound template part exactly once.');
$assert(str_contains($rendered, 'Home') && str_contains($rendered, home_url('/wp-content/themes/' . $theme . '/assets/assets/logo.svg')), 'WordPress renders front-page content with a browser-valid resolved image URL.');
fwrite(STDOUT, "wordpress-site-plan WordPress integration passed\n");
} finally {
    foreach ($pageIds as $id) wp_delete_post((int) $id, true);
    update_option('show_on_front', $previousShowOnFront); update_option('page_on_front', $previousPageOnFront);
    if ('' !== $previousTheme) switch_theme($previousTheme);
    wp_clean_themes_cache();
    if (is_dir($themeDir)) { $items = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($themeDir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST); foreach ($items as $item) $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname()); rmdir($themeDir); }
    wp_clean_themes_cache();
}
