<?php
// Compile actual WordPress global-styles CSS without installing a database.
// Only the settings lookup is supplied by the input instead of a live theme.
$root = $argv[1];
define('ABSPATH', $root . '/');
define('WPINC', 'wp-includes');
define('WP_DEBUG', false);
foreach (array(
    'plugin.php', 'l10n.php', 'pomo/translations.php', 'class-wp-block-supports.php', 'block-supports/layout.php',
    'block-supports/typography.php', 'theme.php', 'load.php', 'default-constants.php',
    'class-wp-list-util.php', 'class-wp-token-map.php', 'compat-utf8.php', 'utf8.php',
    'compat.php', 'formatting.php', 'functions.php', 'kses.php', 'class-wp-duotone.php',
    'class-wp-block-type.php', 'class-wp-block-type-registry.php', 'class-wp-block-styles-registry.php', 'blocks.php',
    'class-wp-theme-json-schema.php', 'class-wp-theme-json.php',
) as $file) {
    $path = ABSPATH . WPINC . '/' . $file;
    if (is_file($path)) require_once $path;
}
add_filter('pre_option_blog_charset', static fn() => 'UTF-8');
function wp_get_global_settings() { return $GLOBALS['theme']['settings'] ?? array(); }
$theme = json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);
WP_Block_Type_Registry::get_instance()->register('core/button');
// Supply the block registry metadata normally loaded by core's init hook.
$metadata = new ReflectionProperty(WP_Theme_JSON::class, 'blocks_metadata');
$metadata->setValue(null, array('core/button' => array('selector' => '.wp-block-button .wp-block-button__link')));
foreach ($theme['styles']['blocks']['core/button']['variations'] ?? array() as $slug => $look) {
    WP_Block_Styles_Registry::get_instance()->register('core/button', array('name' => $slug, 'label' => ucfirst($slug)));
}
$core = new WP_Theme_JSON(json_decode(file_get_contents(ABSPATH . WPINC . '/theme.json'), true), 'default');
$core->merge(new WP_Theme_JSON($theme, 'theme'));
echo $core->get_stylesheet(array('variables', 'styles', 'presets'), null, array('include_block_style_variations' => true));
