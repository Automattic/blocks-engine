<?php
declare(strict_types=1);

// Run only inside a disposable WordPress site (see design-system-restyle.sh):
// `wp eval-file` with RESTYLE_DISPOSABLE=1.
//
// RESTYLE_PHASE=install compiles RESTYLE_FIXTURE, installs its theme, menus
// and pages, and writes the page URLs and the palette to RESTYLE_ROUTES.
// RESTYLE_PHASE=recolor gives every palette entry a new value in the user's
// Global Styles, the way a site owner edits the palette in the Site Editor.
require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\WordPressSitePlanResolver;

if ('1' !== getenv('RESTYLE_DISPOSABLE')) throw new RuntimeException('An explicit disposable runtime is required.');
$theme = 'blocks-engine-restyle-proof';
// Global Styles entries are tied to their theme through a taxonomy term that
// only a user who may assign terms can set.
wp_set_current_user(1);

// The entry WordPress renders the active theme's user Global Styles from.
$userStyles = static function (): int {
    $id = (int) WP_Theme_JSON_Resolver::get_user_global_styles_post_id();
    if (0 === $id) throw new RuntimeException('Global Styles has no user entry for the theme.');
    return $id;
};

if ('recolor' === getenv('RESTYLE_PHASE')) {
    $routes = json_decode((string) file_get_contents((string) getenv('RESTYLE_ROUTES')), true, 512, JSON_THROW_ON_ERROR);
    $palette = array();
    foreach ($routes['palette'] as $index => $entry) {
        // A distinct saturated hue per entry, never the entry's own value.
        $hue = fmod(37.0 + 137.508 * $index, 360.0);
        $color = sprintf('hsl(%d, 85%%, %d%%)', (int) round($hue), 0 === $index % 2 ? 35 : 65);
        $palette[] = array('slug' => $entry['slug'], 'name' => $entry['name'], 'color' => $color);
    }
    $content = array('version' => 3, 'isGlobalStylesUserThemeJSON' => true, 'settings' => array('color' => array('palette' => array('theme' => $palette))));
    $result = wp_update_post(array('ID' => $userStyles(), 'post_content' => wp_slash(wp_json_encode($content))), true);
    if (is_wp_error($result)) throw new RuntimeException($result->get_error_message());
    wp_clean_theme_json_cache();
    echo wp_json_encode(array('palette' => $palette)) . "\n";
    return;
}

$fixture = (string) getenv('RESTYLE_FIXTURE');
$artifact = require $fixture;
$plan = (new ArtifactCompiler())->compile($artifact)->toWordPressSitePlanView()['wordpress_site_plan'];
$directory = WP_CONTENT_DIR . '/themes/' . $theme;
if (is_dir($directory)) {
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $file) $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
}
// Each fixture starts from an empty site: no earlier pages, menus or styles.
foreach (get_posts(array('post_type' => array('page', 'wp_navigation', 'wp_global_styles', 'wp_template', 'wp_template_part'), 'post_status' => 'any', 'numberposts' => -1, 'fields' => 'ids')) as $id) wp_delete_post($id, true);
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
    $content = 'base64' === $write['payload']['encoding'] ? base64_decode($write['payload']['data'], true) : strtr($write['payload']['data'], $references);
    if (!is_string($content) || strlen($content) !== file_put_contents($path, $content)) throw new RuntimeException('Theme write failed: ' . $path);
}
wp_clean_themes_cache();
switch_theme($theme);
wp_clean_theme_json_cache();
$userStyles();
$pages = array();
foreach ($resolved['pages'] as $page) {
    $id = wp_insert_post(array('post_type' => 'page', 'post_status' => 'publish', 'post_title' => $page['title'], 'post_name' => $page['slug'], 'post_content' => wp_slash(strtr($page['resolved_block_markup'], $references))), true);
    if (is_wp_error($id)) throw new RuntimeException($id->get_error_message());
    update_post_meta($id, '_blocks_engine_reconciliation_identity', $page['reconciliation_identity']);
    if ($page['entrypoint']) { update_option('show_on_front', 'page'); update_option('page_on_front', $id); }
    $pages[] = array('route' => $page['source_path'], 'url' => home_url('/?page_id=' . $id));
}
$themeJson = json_decode((string) file_get_contents($directory . '/theme.json'), true, 512, JSON_THROW_ON_ERROR);
$routes = array('fixture' => basename($fixture, '.php'), 'pages' => $pages, 'palette' => $themeJson['settings']['color']['palette'] ?? array(), 'design_system' => $plan['theme']['design_system']['metrics'] ?? array());
file_put_contents((string) getenv('RESTYLE_ROUTES'), wp_json_encode($routes, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
echo wp_json_encode(array('fixture' => $routes['fixture'], 'pages' => count($pages), 'palette' => count($routes['palette']))) . "\n";
