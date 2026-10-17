<?php
declare(strict_types=1);

// Installs a two-route site whose header landmark is body-level, or framed in
// <div class="app"> when SHELL_LANDMARK_FRAMED=1. Either way the generated
// header part's landmark group is the one rendered header box.
require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\WordPressSitePlanResolver;

$framed = '1' === getenv('SHELL_LANDMARK_FRAMED');
$document = static function (string $body) use ($framed): string {
    $shell = '<header><nav><a href="/">Home</a><a href="/other.html">Other</a></nav></header><main><p>' . $body . '</p></main>';
    return '<!doctype html><html><head><title>' . $body . '</title><style>header{padding:12px;background:#fafafa}</style></head><body>' . ($framed ? '<div class="app">' . $shell . '</div>' : $shell) . '</body></html>';
};
$compiled = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array('index.html' => $document('Home body'), 'other.html' => $document('Other body'))));
$plan = $compiled->toWordPressSitePlanView()['wordpress_site_plan'];
$theme = $framed ? 'shell-landmark-framed-proof' : 'shell-landmark-body-proof';
$directory = WP_CONTENT_DIR . '/themes/' . $theme;
$resolved = (new WordPressSitePlanResolver())->resolve($plan, array('theme_uri' => home_url('/wp-content/themes/' . $theme)));
$references = array();
foreach ($resolved['menus'] as $menu) {
    $id = wp_insert_post(array('post_type' => 'wp_navigation', 'post_status' => 'publish', 'post_title' => $menu['title'], 'post_content' => wp_slash($menu['resolved_block_markup'])), true);
    if (is_wp_error($id)) throw new RuntimeException($id->get_error_message());
    $references['"{{wordpress-site-plan:navigation:' . $menu['token'] . '}}"'] = (string) $id;
}
foreach ($resolved['writes'] as $write) {
    $path = $directory . '/' . $write['target_path'];
    wp_mkdir_p(dirname($path));
    file_put_contents($path, 'base64' === $write['payload']['encoding'] ? base64_decode($write['payload']['data'], true) : strtr($write['payload']['data'], $references));
}
wp_clean_themes_cache();
switch_theme($theme);
$pages = array();
foreach ($resolved['pages'] as $page) {
    // Route-scoped stylesheets bind to the plan's page slugs.
    $slug = (string) $page['slug'];
    $existing = get_page_by_path($slug, OBJECT, 'page');
    $id = wp_insert_post(array('ID' => $existing?->ID ?? 0, 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => $page['title'], 'post_name' => $slug, 'post_content' => wp_slash(strtr($page['resolved_block_markup'], $references))), true);
    if (is_wp_error($id)) throw new RuntimeException($id->get_error_message());
    $pages[$page['source_path']] = $id;
}
update_option('show_on_front', 'page');
update_option('page_on_front', $pages['index.html']);
update_option('permalink_structure', '/%postname%/');
flush_rewrite_rules();
echo wp_json_encode(array('pages' => $pages, 'theme' => $theme)) . "\n";
