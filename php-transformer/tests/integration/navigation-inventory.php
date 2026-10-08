<?php
declare(strict_types=1);

// Execute in the disposable WordPress HTTP runtime used by the browser gate.
require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\WordPressSitePlanResolver;

$source = require dirname(__DIR__) . '/fixtures/' . (getenv('NAVIGATION_LIST_PANEL_TEST') ? 'navigation-list-panel-ownership.php' : (getenv('NAVIGATION_OWNERSHIP_TEST') ? 'navigation-list-host-ownership.php' : (getenv('NAVIGATION_OPENER_TEST') ? 'navigation-opener-presentation.php' : 'nested-header-menu.php')));
if (! username_exists('navigation-proof')) {
    $user = wp_insert_user(array('user_login' => 'navigation-proof', 'user_pass' => 'navigation-test-password', 'user_email' => 'navigation-proof@example.test', 'role' => 'administrator'));
    if (is_wp_error($user)) throw new RuntimeException($user->get_error_message());
}
$plan = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array(
    'index.html' => $source,
    'other.html' => str_replace('Editable section content.', 'Second route content.', $source),
)))->toWordPressSitePlanView()['wordpress_site_plan'];
$theme = getenv('NAVIGATION_LIST_PANEL_TEST') ? 'navigation-list-panel-proof' : (getenv('NAVIGATION_OWNERSHIP_TEST') ? 'navigation-ownership-proof' : (getenv('NAVIGATION_OPENER_TEST') ? 'navigation-opener-proof' : 'navigation-inventory-proof'));
$directory = WP_CONTENT_DIR . '/themes/' . $theme;
$resolved = (new WordPressSitePlanResolver())->resolve($plan, array('theme_uri' => home_url('/wp-content/themes/' . $theme)));
$references = array();
$menus = array();
foreach ($resolved['menus'] as $menu) {
    $id = wp_insert_post(array('post_type' => 'wp_navigation', 'post_status' => 'publish', 'post_title' => $menu['title'], 'post_content' => wp_slash($menu['resolved_block_markup'])), true);
    if (is_wp_error($id)) throw new RuntimeException($id->get_error_message());
    $references['"{{wordpress-site-plan:navigation:' . $menu['token'] . '}}"'] = (string) $id;
    $menus[] = $id;
}
foreach ($resolved['writes'] as $write) {
    $path = $directory . '/' . $write['target_path'];
    wp_mkdir_p(dirname($path));
    $content = 'base64' === $write['payload']['encoding'] ? base64_decode($write['payload']['data'], true) : strtr($write['payload']['data'], $references);
    file_put_contents($path, $content);
}
wp_clean_themes_cache();
if (getenv('NAVIGATION_OWNERSHIP_TEST')) file_put_contents($directory . '/source-proof.html', $source);
switch_theme($theme);
require $directory . '/functions.php';
$pages = array();
foreach ($resolved['pages'] as $page) {
    $slug = 'index.html' === $page['source_path'] ? 'navigation-home' : 'navigation-other';
    $existing = get_page_by_path($slug, OBJECT, 'page');
    $id = wp_insert_post(array('ID' => $existing?->ID ?? 0, 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => $page['title'], 'post_name' => $slug, 'post_content' => wp_slash(strtr($page['resolved_block_markup'], $references))), true);
    if (is_wp_error($id)) throw new RuntimeException($id->get_error_message());
    $pages[$page['source_path']] = $id;
}
update_option('show_on_front', 'page');
update_option('page_on_front', $pages['index.html']);
update_option('permalink_structure', '/%postname%/');
flush_rewrite_rules();
echo wp_json_encode(array('pages' => $pages, 'menus' => $menus, 'theme' => $theme)) . "\n";
