<?php
declare(strict_types=1);

// Run only inside a disposable WordPress installation: wp eval-file <this file>.
require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\WordPressSitePlanResolver;

$fixture = require dirname(__DIR__) . '/fixtures/stylesheet-activation.php';
$plan = (new ArtifactCompiler())->compile($fixture)->toWordPressSitePlanView()['wordpress_site_plan'];
$theme = 'stylesheet-activation-proof';
$directory = WP_CONTENT_DIR . '/themes/' . $theme;
$resolved = (new WordPressSitePlanResolver())->resolve($plan, array('theme_uri' => home_url('/wp-content/themes/' . $theme)));
foreach ($resolved['writes'] as $write) {
    $path = $directory . '/' . $write['target_path'];
    wp_mkdir_p(dirname($path));
    file_put_contents($path, 'base64' === $write['payload']['encoding'] ? base64_decode($write['payload']['data'], true) : $write['payload']['data']);
}
wp_clean_themes_cache();
switch_theme($theme);
require $directory . '/functions.php';
wp_set_current_user(1);
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
// Exercise Core's actual iframe asset collection, including the generated hooks.
require_once ABSPATH . 'wp-admin/includes/admin.php';
$GLOBALS['post'] = get_post($ids['index.html']);
set_current_screen('post');
get_current_screen()->is_block_editor(true);
$context = new WP_Block_Editor_Context(array('post' => $GLOBALS['post']));
wp_scripts();
$settings = get_block_editor_settings(array(), $context);
$editorAssets = $settings['__unstableResolvedAssets']['styles'] ?? '';
if (!str_contains($editorAssets, '/dark.css') || str_contains($editorAssets, '/light.css') || str_contains($editorAssets, '/other.css')) throw new RuntimeException('Editor iframe stylesheet activation differs from source defaults.');
$editorCss = implode("\n", array_column($settings['styles'] ?? array(), 'css'));
if (str_contains($editorCss, 'background:white') || str_contains($editorCss, 'background:red')) throw new RuntimeException('Inactive stylesheet leaked into editor defaults.');
echo wp_json_encode(array('pages' => array_map('get_permalink', $ids), 'theme' => $theme, 'save_reload' => 'passed', 'editor_activation' => 'passed')) . "\n";
