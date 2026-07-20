<?php
declare(strict_types=1);

// Run with a standard WordPress test-suite installation, for example:
// WP_TESTS_DIR=/path/to/wordpress-tests-lib composer test:wordpress-integration
$testsDir = getenv('WP_TESTS_DIR');
if (!is_string($testsDir) || !is_file($testsDir . '/includes/bootstrap.php')) {
    fwrite(STDOUT, "wordpress-site-plan WordPress integration skipped: WP_TESTS_DIR is unavailable.\n");
    exit(0);
}

require dirname(__DIR__, 2) . '/vendor/autoload.php';
require $testsDir . '/includes/bootstrap.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\WordPressSitePlanResolver;

$assert = static function (bool $condition, string $message): void { if (!$condition) throw new RuntimeException($message); };
$theme = 'blocks-engine-site-plan-contract';
$themeDir = WP_CONTENT_DIR . '/themes/' . $theme;
if (!is_dir($themeDir) && !mkdir($themeDir, 0777, true) && !is_dir($themeDir)) throw new RuntimeException('Could not create integration theme directory.');

$result = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array(
    'index.html' => '<header><p>Integration Header</p></header><main><img src="assets/logo.svg"><h1>Home</h1></main><footer><p>Integration Footer</p></footer>',
    'assets/logo.svg' => '<svg xmlns="http://www.w3.org/2000/svg"/>',
)))->toArray();
$plan = $result['source_reports']['wordpress_site_plan'] ?? array();
$resolved = (new WordPressSitePlanResolver())->resolve($plan, array('theme_uri' => home_url('/wp-content/themes/' . $theme)));
foreach ($resolved['writes'] as $write) {
    $path = $themeDir . '/' . $write['target_path'];
    if (!is_dir(dirname($path))) mkdir(dirname($path), 0777, true);
    file_put_contents($path, 'base64' === $write['payload']['encoding'] ? base64_decode($write['payload']['data'], true) : $write['payload']['data']);
}
wp_clean_themes_cache();
$wpTheme = wp_get_theme($theme);
$assert($wpTheme->exists(), 'WordPress recognizes the materialized block theme.');
switch_theme($theme);
$pageIds = array();
foreach ($resolved['pages'] as $page) $pageIds[$page['reconciliation_identity']] = wp_insert_post(array('post_type' => 'page', 'post_status' => 'publish', 'post_title' => $page['title'], 'post_name' => $page['slug'], 'post_content' => $page['resolved_block_markup']), true);
foreach ($resolved['operations'] as $operation) if ('site_reading' === $operation['kind']) { update_option('show_on_front', $operation['show_on_front']); update_option('page_on_front', $pageIds[$operation['front_page_reconciliation_identity']]); }
$assert('page' === get_option('show_on_front') && $pageIds[$resolved['operations'][0]['front_page_reconciliation_identity']] === (int) get_option('page_on_front'), 'WordPress applied the front-page operation.');
$front = file_get_contents($themeDir . '/templates/front-page.html');
$assert(str_contains($front, '"slug":"header"') && str_contains($front, '"slug":"footer"'), 'WordPress theme template contains bound shell part references.');
$content = get_post_field('post_content', (int) get_option('page_on_front'));
$assert(str_contains($content, home_url('/wp-content/themes/' . $theme . '/assets/assets/logo.svg')), 'Materialized page content uses the resolved theme asset URL.');
fwrite(STDOUT, "wordpress-site-plan WordPress integration passed\n");
