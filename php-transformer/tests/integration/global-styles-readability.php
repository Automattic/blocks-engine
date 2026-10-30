<?php
declare(strict_types=1);

// Run only inside a disposable WordPress installation: wp eval-file <this file>.
require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\WordPressSitePlanResolver;

$fixture = require dirname(__DIR__) . '/fixtures/global-styles-readability.php';
$plan = (new ArtifactCompiler())->compile($fixture)->toWordPressSitePlanView()['wordpress_site_plan'];
$theme = 'global-styles-readability-proof';
$directory = WP_CONTENT_DIR . '/themes/' . $theme;
$resolved = (new WordPressSitePlanResolver())->resolve($plan, array('theme_uri' => home_url('/wp-content/themes/' . $theme)));
foreach ($resolved['writes'] as $write) {
    $path = $directory . '/' . $write['target_path'];
    wp_mkdir_p(dirname($path));
    file_put_contents($path, 'base64' === $write['payload']['encoding'] ? base64_decode($write['payload']['data'], true) : $write['payload']['data']);
}
$themeJson = json_decode(file_get_contents($directory . '/theme.json'), true, 512, JSON_THROW_ON_ERROR);
$type = $themeJson['styles']['typography'];
$size = $type['fontSize'];
if (str_starts_with($size, 'var:preset|font-size|')) {
    $sizes = array_column($themeJson['settings']['typography']['fontSizes'], 'size', 'slug');
    $size = $sizes[substr($size, strlen('var:preset|font-size|'))];
}
if ('16px' !== $size) throw new RuntimeException('Global Styles must use readable body size, not the rem base.');
foreach (array('text' => '--ink', 'background' => '--paper') as $field => $variable) {
    if ('var(' . $variable . ')' !== ($themeJson['styles']['color'][$field] ?? null)) throw new RuntimeException('Editor palette must retain the source scheme variable.');
}
wp_clean_themes_cache();
switch_theme($theme);
require $directory . '/functions.php';
wp_set_current_user(1);
foreach (get_posts(array('post_type' => 'wp_navigation', 'numberposts' => -1)) as $navigation) wp_delete_post($navigation->ID, true);
foreach ($resolved['menus'] ?? array() as $menu) {
    wp_insert_post(array('post_type' => 'wp_navigation', 'post_status' => 'publish', 'post_title' => 'Fixture navigation', 'post_content' => wp_slash($menu['resolved_block_markup'] ?? $menu['block_markup'])));
}
$ids = array();
foreach ($resolved['pages'] as $page) {
    $markup = serialize_blocks(parse_blocks($page['resolved_block_markup']));
    $id = wp_insert_post(array('post_type' => 'page', 'post_status' => 'publish', 'post_title' => $page['title'], 'post_name' => trim($page['route']['path'], '/') ?: 'home', 'post_content' => wp_slash($markup)), true);
    if (is_wp_error($id)) throw new RuntimeException($id->get_error_message());
    $saved = get_post_field('post_content', $id);
    if (serialize_blocks(parse_blocks($saved)) !== $markup) throw new RuntimeException('WordPress save/reload changed the block document.');
    $ids[$page['source_path']] = $id;
}
update_option('show_on_front', 'page');
update_option('page_on_front', $ids['index.html']);
update_option('permalink_structure', '/%postname%/');
flush_rewrite_rules();
