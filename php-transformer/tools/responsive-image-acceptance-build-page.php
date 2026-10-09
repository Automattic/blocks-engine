<?php
/** Disposable WordPress fixture; paired source compilers and actual SSI services. */

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\CompanionPluginPayload;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

require_once WP_PLUGIN_DIR . '/blocks-engine-php-transformer/vendor/autoload.php';
require_once '/ssi/includes/class-static-site-importer-companion-plugin.php';
require_once '/ssi/includes/class-static-site-importer-media-library-materializer.php';
$theme = get_theme_root() . '/be-responsive-proof';
wp_mkdir_p($theme . '/assets');
$themeUrl = content_url('/themes/be-responsive-proof');
$initialTheme = get_stylesheet();
$css = 'body{margin:0}.media-container{width:min(calc(100vw - 32px),570px);margin:16px}.media-container img{display:block;max-width:100%;height:auto}.media-container figure{margin:0}';
file_put_contents($theme . '/style.css', "/* Theme Name: Responsive image proof */\n" . $css);
file_put_contents($theme . '/index.php', '<?php ?><!doctype html><html><head><meta name="viewport" content="width=device-width,initial-scale=1"><?php wp_head(); ?><link rel="stylesheet" href="<?php echo get_stylesheet_uri(); ?>"></head><body><?php while(have_posts()){the_post(); echo do_blocks(get_the_content());} wp_footer(); ?></body></html>');
switch_theme('be-responsive-proof');
$files = array('fallback.png' => array(480, 175, 220, 40, 60), 'tablet.png' => array(768, 280, 40, 180, 60), 'desktop.png' => array(1920, 700, 40, 60, 220), 'density.png' => array(80, 40, 170, 40, 140), 'density-2x.png' => array(160, 80, 20, 160, 190), 'descriptor-fallback.png' => array(64, 32, 200, 160, 30), 'first,w_128.png' => array(128, 64, 160, 30, 200), 'second,w_256.png' => array(256, 128, 30, 200, 160));
$files['service.png/v1/fit/w_40,h_20,q_90/icon.png'] = array(40, 20, 150, 40, 80);
$files['service.png/v1/fit/w_80,h_40,q_90/icon.png'] = array(80, 40, 40, 150, 80);
$files['large-original.png'] = array(3840, 1400, 110, 80, 150);
foreach ($files as $name => [$width, $height, $red, $green, $blue]) {
    wp_mkdir_p(dirname($theme . '/assets/' . $name));
    $image = imagecreatetruecolor($width, $height);
    imagefill($image, 0, 0, imagecolorallocate($image, $red, $green, $blue));
    imagefilledrectangle($image, (int) ($width / 4), (int) ($height / 4), (int) ($width * 3 / 4), (int) ($height * 3 / 4), imagecolorallocate($image, 255 - $red, 255 - $green, 255 - $blue));
    imagepng($image, $theme . '/assets/' . $name);
}
$asset = $themeUrl . '/assets/';
$html = '<div class="media-container"><img src="' . $asset . 'fallback.png" srcset="' . $asset . 'fallback.png 480w, ' . $asset . 'tablet.png 768w, ' . $asset . 'desktop.png 1920w" sizes="(min-width: 602px) 570px, calc(100vw - 32px)" alt="Width family"></div>'
    . '<div class="media-container"><a href="/item"><img src="' . $asset . 'density.png" srcset="' . $asset . 'density.png 1x, ' . $asset . 'density-2x.png 2x" alt="Density family"></a></div>'
    . '<div class="media-container"><figure><img src="' . $asset . 'descriptor-fallback.png" srcset="' . $asset . 'first,w_128.png, ' . $asset . 'second,w_256.png" alt="Descriptorless family"><figcaption>Caption</figcaption></figure></div>'
    . '<div class="media-container"><img src="' . $asset . 'service.png/v1/fit/w_40,h_20,q_90/icon.png" srcset="' . $asset . 'service.png/v1/fit/w_40,h_20,q_90/icon.png 1x, ' . $asset . 'service.png/v1/fit/w_80,h_40,q_90/icon.png 2x" alt="Service density family"></div>'
    . '<div class="media-container"><img src="' . $asset . 'fallback.png" srcset="' . $asset . 'large-original.png 3840w" sizes="570px" alt="Large original family"></div>';
$sourceInput = tempnam(sys_get_temp_dir(), 'be-responsive-source-');
file_put_contents($sourceInput, wp_json_encode(array('html' => $html)));
file_put_contents($theme . '/source.html', '<!doctype html><html><head><meta name="viewport" content="width=device-width,initial-scale=1"><style>' . $css . '</style></head><body>' . $html . '</body></html>');
$compiler = WP_PLUGIN_DIR . '/blocks-engine-php-transformer/tools/responsive-image-acceptance-compile.php';
$before = json_decode((string) shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($compiler) . ' /baseline/vendor/autoload.php ' . escapeshellarg($sourceInput)), true, 512, JSON_THROW_ON_ERROR);
unlink($sourceInput);
$after = (new HtmlTransformer())->transform($html)->toArray();
$payload = (new CompanionPluginPayload())->fromBlockTypes(array(), array(), array('site_slug' => 'responsive-proof'), $after['source_reports']['generated_blocks']);
$scaffold = Static_Site_Importer_Companion_Plugin::scaffold($payload);
if (is_wp_error($scaffold)) throw new RuntimeException($scaffold->get_error_message());
foreach ($scaffold['files'] as $path => $content) {
    $target = WP_PLUGIN_DIR . '/' . $path;
    wp_mkdir_p(dirname($target));
    file_put_contents($target, $content);
}
require_once ABSPATH . 'wp-admin/includes/plugin.php';
$activation = activate_plugin('ssi-responsive-proof/ssi-responsive-proof.php');
if (is_wp_error($activation)) throw new RuntimeException($activation->get_error_message());
update_option('static_site_importer_active_companion_plugin', 'ssi-responsive-proof/ssi-responsive-proof.php');
$ids = array();
foreach (array('before' => $before, 'after' => $after) as $label => $result) {
    $ids[$label] = wp_insert_post(wp_slash(array('post_type' => 'page', 'post_status' => 'publish', 'post_title' => $label, 'post_content' => $result['serialized_blocks'])));
}
$state = array('theme' => array('uri' => $themeUrl), 'theme_dir' => $theme, 'ordered_pages' => array(array('source_path' => 'before'), array('source_path' => 'after')), 'source_ids' => $ids, 'applied' => array(), 'resolved' => array('pages' => array()));
$binding = Static_Site_Importer_Media_Library_Materializer::materialize($state);
if (is_wp_error($binding)) throw new RuntimeException($binding->get_error_message());
$provenance = array();
foreach (get_posts(array('post_type' => 'attachment', 'post_status' => 'inherit', 'numberposts' => -1)) as $attachment) {
    $provenance[] = array('id' => $attachment->ID, 'url' => wp_get_original_image_url($attachment->ID), 'full_url' => wp_get_attachment_url($attachment->ID), 'identity' => get_post_meta($attachment->ID, Static_Site_Importer_Media_Library_Materializer::SOURCE_ASSET_META_KEY, true), 'sha256' => hash_file('sha256', wp_get_original_image_path($attachment->ID)));
}
$sourceBindings = array();
foreach ($files as $name => $dimensions) {
    $sha256 = hash_file('sha256', $theme . '/assets/' . $name);
    $identity = basename($theme) . '#' . $sha256;
    $matches = array_values(array_filter($provenance, static fn(array $item): bool => $item['identity'] === $identity));
    if (1 !== count($matches) || $matches[0]['sha256'] !== $sha256) throw new RuntimeException('Authored asset bytes or identity were substituted: ' . $name);
    $sourceBindings[] = array('source_url' => $asset . $name, 'attachment_id' => $matches[0]['id'], 'attachment_url' => $matches[0]['url'], 'sha256' => $sha256, 'identity' => $identity);
}
echo wp_json_encode(array('initial_theme' => $initialTheme, 'source_url' => $themeUrl . '/source.html', 'posts' => $ids, 'binding' => $binding, 'source_bindings' => $sourceBindings, 'provenance' => $provenance, 'before' => $before['serialized_blocks'], 'after' => $after['serialized_blocks'], 'bound_content' => get_post_field('post_content', $ids['after']), 'replacement_id' => (int) $args[0]), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
