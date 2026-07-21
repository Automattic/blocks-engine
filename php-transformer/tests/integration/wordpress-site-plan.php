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
    'index.html' => '<!doctype html><html><head><script src="/assets/head.js?head=1#top"></script><script src="assets/defer.js" defer></script></head><body><header><p>Integration Header</p></header><main><img src="assets/logo.svg"><h1>Home</h1></main><footer><p>Integration Footer</p></footer><script src="assets/async.js" async defer></script><script src="assets/module.js" type="module"></script><script src="assets/legacy.js" nomodule integrity="sha384-test" crossorigin="anonymous" referrerpolicy="no-referrer"></script><script src="https://cdn.example.test/external.js?build=1#run" async></script></body></html>',
    'assets/logo.svg' => '<svg xmlns="http://www.w3.org/2000/svg"/>',
    'assets/head.js' => 'window.headAsset=true;',
    'assets/defer.js' => 'window.deferAsset=true;',
    'assets/async.js' => 'window.asyncAsset=true;',
    'assets/module.js' => 'window.moduleAsset=true;',
    'assets/legacy.js' => 'window.legacyAsset=true;',
    'about.html' => '<!doctype html><html><body><main><h1>Root About</h1></main><script src="assets/root-about.js"></script><script src="assets/shared.js"></script></body></html>',
    'nested/about.html' => '<!doctype html><html><head><script src="assets/about-head.js" defer></script></head><body><main><h1>About</h1></main><script src="https://cdn.example.test/about.js" async></script><script src="assets/shared.js"></script></body></html>',
    'nested/deep/about.html' => '<!doctype html><html><body><main><h1>Deep About</h1></main><script src="assets/deep-about.js"></script></body></html>',
    'assets/about-head.js' => 'window.aboutHeadAsset=true;',
    'assets/root-about.js' => 'window.rootAboutAsset=true;',
    'assets/deep-about.js' => 'window.deepAboutAsset=true;',
    'assets/shared.js' => 'window.sharedAsset=true;',
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
require $themeDir . '/functions.php';
$pagesBySource = array(); foreach ($resolved['pages'] as $page) { $id = wp_insert_post(array('post_type' => 'page', 'post_status' => 'publish', 'post_title' => $page['title'], 'post_name' => $page['slug'], 'post_content' => $page['resolved_block_markup']), true); if (is_wp_error($id)) throw new RuntimeException($id->get_error_message()); $pageIds[$page['reconciliation_identity']] = $id; $pagesBySource[$page['source_path']] = $id; }
$nestedParent = wp_insert_post(array('post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Nested', 'post_name' => 'nested'), true); if (is_wp_error($nestedParent)) throw new RuntimeException($nestedParent->get_error_message()); $deepParent = wp_insert_post(array('post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Deep', 'post_name' => 'deep', 'post_parent' => $nestedParent), true); if (is_wp_error($deepParent)) throw new RuntimeException($deepParent->get_error_message()); wp_update_post(array('ID' => $pagesBySource['nested/about.html'], 'post_parent' => $nestedParent)); wp_update_post(array('ID' => $pagesBySource['nested/deep/about.html'], 'post_parent' => $deepParent)); $pageIds[] = $nestedParent; $pageIds[] = $deepParent;
foreach ($resolved['operations'] as $operation) if ('site_reading' === $operation['kind']) { update_option('show_on_front', $operation['show_on_front']); update_option('page_on_front', $pageIds[$operation['front_page_reconciliation_identity']]); }
$assert('page' === get_option('show_on_front') && $pageIds[$resolved['operations'][0]['front_page_reconciliation_identity']] === (int) get_option('page_on_front'), 'WordPress applied the front-page operation.');
$frontPage = get_post((int) get_option('page_on_front')); if (!$frontPage) throw new RuntimeException('Could not load front page.');
global $wp_query; $wp_query->is_front_page = true; $wp_query->is_page = true; $wp_query->post = $frontPage; $wp_query->posts = array($frontPage); setup_postdata($frontPage);
do_action('wp_enqueue_scripts'); $scripts = wp_scripts();
$handleFor = static function (string $needle) use ($scripts): string { foreach ($scripts->registered as $handle => $script) if (str_contains((string) $script->src, $needle)) return $handle; throw new RuntimeException("Missing registered script {$needle}."); };
$head = $handleFor('head.js?head=1#top'); $defer = $handleFor('defer.js'); $async = $handleFor('async.js'); $module = $handleFor('module.js'); $legacy = $handleFor('legacy.js'); $external = $handleFor('external.js?build=1#run'); $aboutHead = $handleFor('about-head.js'); $aboutExternal = $handleFor('about.js'); $rootAbout = $handleFor('root-about.js'); $deepAbout = $handleFor('deep-about.js'); $shared = $handleFor('shared.js');
$assert($scripts->registered[$head]->src === get_theme_file_uri('assets/assets/head.js') . '?head=1#top' && $scripts->registered[$external]->src === 'https://cdn.example.test/external.js?build=1#run' && $scripts->registered[$defer]->deps === array() && $scripts->registered[$module]->deps === array(), 'Generated theme preserves local root-relative suffixes, external URLs, and strategy-safe empty dependencies.');
$assert(in_array($head, $scripts->queue, true) && in_array($external, $scripts->queue, true) && !in_array($aboutHead, $scripts->queue, true) && !in_array($aboutExternal, $scripts->queue, true), 'Front-page enqueue scope excludes nested-page declarations.');
ob_start(); wp_print_head_scripts(); $headTags = (string) ob_get_clean(); ob_start(); wp_print_footer_scripts(); $footerTags = (string) ob_get_clean();
$tag = static function (string $tags, string $needle): string { if (!preg_match('~<script\b[^>]*\bsrc=["\'][^"\']*' . preg_quote($needle, '~') . '[^"\']*["\'][^>]*></script>~', $tags, $match)) throw new RuntimeException("Missing rendered script {$needle}."); return $match[0]; };
$headTag = $tag($headTags, 'head.js?head=1#top'); $deferTag = $tag($headTags, 'defer.js'); $asyncTag = $tag($footerTags, 'async.js'); $moduleTag = $tag($footerTags, 'module.js'); $legacyTag = $tag($footerTags, 'legacy.js'); $externalTag = $tag($footerTags, 'external.js?build=1#run');
$assert(!str_contains($headTag, ' async') && !str_contains($headTag, ' defer') && str_contains($deferTag, ' defer') && str_contains($asyncTag, ' async') && str_contains($asyncTag, ' defer') && str_contains($moduleTag, 'type="module"') && str_contains($legacyTag, ' nomodule') && str_contains($legacyTag, 'integrity="sha384-test"') && str_contains($legacyTag, 'crossorigin="anonymous"') && str_contains($legacyTag, 'referrerpolicy="no-referrer"') && str_contains($externalTag, ' async'), 'Each rendered handle preserves its exact loading and tag attributes.');
$frontOrder = array(strpos($headTags, 'head.js?head=1#top'), strpos($headTags, 'defer.js'), strpos($footerTags, 'async.js'), strpos($footerTags, 'module.js'), strpos($footerTags, 'legacy.js'), strpos($footerTags, 'external.js?build=1#run'));
$assert($frontOrder === array_values(array_filter($frontOrder, static fn(int $offset): bool => $offset >= 0)), 'Rendered front-page scripts retain their declared order without dependency edges.');
$about = get_post($pagesBySource['nested/about.html']); if (!$about) throw new RuntimeException('Could not load nested about page.');
$wp_query->is_front_page = false; $wp_query->is_page = true; $wp_query->post = $about; $wp_query->posts = array($about); setup_postdata($about); $scripts->queue = array(); $scripts->to_do = array(); $scripts->done = array(); do_action('wp_enqueue_scripts');
$assert(in_array($aboutHead, $scripts->queue, true) && in_array($aboutExternal, $scripts->queue, true) && in_array($shared, $scripts->queue, true) && !in_array($rootAbout, $scripts->queue, true) && !in_array($deepAbout, $scripts->queue, true) && !in_array($head, $scripts->queue, true) && !in_array($external, $scripts->queue, true), 'Nested-page URI scope excludes front-page and duplicate-slug sibling declarations while enqueuing the shared handle.');
$rootAboutPage = get_post($pagesBySource['about.html']); if (!$rootAboutPage) throw new RuntimeException('Could not load root about page.'); $wp_query->post = $rootAboutPage; $wp_query->posts = array($rootAboutPage); setup_postdata($rootAboutPage); $scripts->queue = array(); $scripts->to_do = array(); $scripts->done = array(); do_action('wp_enqueue_scripts');
$assert(in_array($rootAbout, $scripts->queue, true) && in_array($shared, $scripts->queue, true) && !in_array($aboutHead, $scripts->queue, true) && !in_array($deepAbout, $scripts->queue, true), 'Root about URI scope is isolated from nested duplicate slugs and reuses the shared registration.');
$deepAboutPage = get_post($pagesBySource['nested/deep/about.html']); if (!$deepAboutPage) throw new RuntimeException('Could not load deep about page.'); $wp_query->post = $deepAboutPage; $wp_query->posts = array($deepAboutPage); setup_postdata($deepAboutPage); $scripts->queue = array(); $scripts->to_do = array(); $scripts->done = array(); do_action('wp_enqueue_scripts');
$assert(in_array($deepAbout, $scripts->queue, true) && !in_array($rootAbout, $scripts->queue, true) && !in_array($aboutHead, $scripts->queue, true), 'Deeper nested page URI scope is isolated from duplicate leaf slugs.');
$front = file_get_contents($themeDir . '/templates/front-page.html'); if (false === $front) throw new RuntimeException('Could not read front-page template.');
$post = $frontPage; $wp_query->is_front_page = true; $wp_query->is_page = true; $wp_query->post = $post; $wp_query->posts = array($post); setup_postdata($post);
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
