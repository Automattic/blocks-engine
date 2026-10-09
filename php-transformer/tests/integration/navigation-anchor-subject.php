<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\WordPressSitePlanResolver;

$scopedDropdown = '1' === getenv('SCOPED_DROPDOWN_TEST');
$source = require dirname(__DIR__) . ($scopedDropdown ? '/fixtures/scoped-mixed-dropdown.php' : '/fixtures/navigation-anchor-subject.php');
if (!username_exists('navigation-proof')) {
    $user = wp_insert_user(array('user_login' => 'navigation-proof', 'user_pass' => 'navigation-test-password', 'user_email' => 'navigation-proof@example.test', 'role' => 'administrator'));
    if (is_wp_error($user)) throw new RuntimeException($user->get_error_message());
}
$files = array('index.html' => $source, 'other.html' => $source);
if ($scopedDropdown) {
    $files['capture-receipt.json'] = json_encode(array('schema' => 'data-liberation/capture-receipt/v1', 'routes' => array(array('url' => 'https://example.test/', 'path' => 'index.html'), array('url' => 'https://example.test/other', 'path' => 'other.html'))));
    $pages = array();
    foreach (array('https://example.test/', 'https://example.test/other') as $url) $pages[] = array('sourceUrl' => $url, 'states' => array(array('status' => 'captured', 'trigger' => array('selector' => 'missing', 'label' => 'Open menu', 'tag' => 'div', 'ariaHaspopup' => 'menu'), 'dialog' => array('html' => '<div>Panel</div>', 'htmlBytes' => 16, 'htmlTruncated' => false, 'presentation' => 'dropdown'))));
    $files['interaction-states.json'] = json_encode(array('schema' => 'data-liberation/captured-interactions/v1', 'pages' => $pages));
}
$compiled = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => $files));
$plan = $compiled->toWordPressSitePlanView()['wordpress_site_plan'];
if ($scopedDropdown) {
    // The neutral HTTP fixture uses the same native generated definitions as
    // materialization. Register them through WordPress' plugin entrypoint.
    $plugin = WP_PLUGIN_DIR . '/scoped-dropdown-proof';
    wp_mkdir_p($plugin);
    foreach ($compiled->sourceReports['companion_plugin_payload']['blocks'] as $index => $definition) {
        $block = $plugin . '/block-' . $index;
        wp_mkdir_p($block);
        file_put_contents($block . '/block.json', wp_json_encode($definition['block_json']));
        foreach ($definition['assets'] ?? array() as $name => $contents) file_put_contents($block . '/' . $name, $contents);
        if (!empty($definition['view_js'])) file_put_contents($block . '/view.js', $definition['view_js']);
        if (!empty($definition['render'])) file_put_contents($block . '/render.php', $definition['render']);
        foreach ($definition['script_dependencies'] ?? array() as $name => $dependencies) file_put_contents($block . '/' . pathinfo($name, PATHINFO_FILENAME) . '.asset.php', '<?php return ' . var_export(array('dependencies' => $dependencies, 'version' => '1'), true) . ';');
    }
    file_put_contents($plugin . '/scoped-dropdown-proof.php', '<?php /* Plugin Name: Scoped Dropdown Proof */ add_action("init", static function () { foreach (glob(__DIR__ . "/block-*/block.json") as $file) register_block_type(dirname($file)); });');
    require_once ABSPATH . 'wp-admin/includes/plugin.php';
    $activated = activate_plugin('scoped-dropdown-proof/scoped-dropdown-proof.php');
    if (is_wp_error($activated)) throw new RuntimeException($activated->get_error_message());
}
$theme = $scopedDropdown ? 'scoped-dropdown-proof' : 'navigation-anchor-proof';
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
switch_theme($theme);
require $directory . '/functions.php';
$pages = array();
foreach ($resolved['pages'] as $page) {
    $slug = 'index.html' === $page['source_path'] ? 'anchor-home' : 'anchor-other';
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
