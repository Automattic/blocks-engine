<?php

// Execute via `wp eval-file` on a disposable WordPress site only.
require dirname(__DIR__, 3) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\WordPressSitePlanResolver;

$source = (string) file_get_contents(__DIR__ . '/native-emoji.html');
$result = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array('index.html' => $source)))->toArray();
$plan = $result['source_reports']['wordpress_site_plan'];
$theme = 'blocks-engine-native-emoji';
$themeDir = WP_CONTENT_DIR . '/themes/' . $theme;
$resolved = (new WordPressSitePlanResolver())->resolve($plan, array('theme_uri' => home_url('/wp-content/themes/' . $theme)));
foreach ($resolved['writes'] as $write) {
    $path = $themeDir . '/' . $write['target_path'];
    if (!is_dir(dirname($path))) wp_mkdir_p(dirname($path));
    file_put_contents($path, 'base64' === $write['payload']['encoding'] ? base64_decode($write['payload']['data'], true) : $write['payload']['data']);
}
file_put_contents($themeDir . '/native-emoji.html', $source);
wp_clean_themes_cache();
switch_theme($theme);
$existing = get_page_by_path('native-emoji-fixture');
$postId = wp_insert_post(array('ID' => $existing instanceof WP_Post ? $existing->ID : 0, 'post_type' => 'page', 'post_status' => 'publish', 'post_name' => 'native-emoji-fixture', 'post_title' => 'Native emoji fixture', 'post_content' => wp_slash($resolved['pages'][0]['resolved_block_markup'])), true);
if (is_wp_error($postId)) throw new RuntimeException($postId->get_error_message());
update_option('show_on_front', 'page');
update_option('page_on_front', $postId);
echo wp_json_encode(array('post_id' => $postId, 'theme' => $theme, 'source_url' => home_url('/wp-content/themes/' . $theme . '/native-emoji.html'), 'url' => home_url('/'), 'wordpress' => get_bloginfo('version')), JSON_UNESCAPED_SLASHES) . "\n";
