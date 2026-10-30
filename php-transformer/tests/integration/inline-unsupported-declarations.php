<?php
declare(strict_types=1);

// Run only inside a disposable WordPress installation: wp eval-file <this file>.
require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\WordPressSitePlanResolver;

$fixture = require dirname(__DIR__) . '/fixtures/inline-unsupported-declarations.php';
$plan = (new ArtifactCompiler())->compile($fixture)->toWordPressSitePlanView()['wordpress_site_plan'];
$theme = 'inline-unsupported-declarations-proof';
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
$ids = array();
foreach ($resolved['pages'] as $page) {
    // The generated theme scopes each route's carrier stylesheet to its source path.
    $slug = trim($page['route']['path'], '/') ?: 'home';
    $markup = serialize_blocks(parse_blocks($page['resolved_block_markup']));
    $existing = get_page_by_path($slug, OBJECT, 'page');
    $id = wp_insert_post(array('ID' => $existing?->ID ?? 0, 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => $page['title'], 'post_name' => $slug, 'post_content' => wp_slash($markup)), true);
    if (is_wp_error($id)) throw new RuntimeException($id->get_error_message());
    $ids[$page['source_path']] = $id;
}
update_option('show_on_front', 'page');
update_option('page_on_front', $ids['index.html']);
update_option('permalink_structure', '/%postname%/');
flush_rewrite_rules();
echo wp_json_encode(array('pages' => array_map('get_permalink', $ids), 'theme' => $theme)) . "\n";
