<?php
// Install the serializer contract in a disposable, real Gutenberg runtime.
$root = getenv('BE_TRANSFORMER_ROOT') ?: dirname(__DIR__);
require $root . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Generators\AuthoredCarouselBlockGenerator;

$generator = new AuthoredCarouselBlockGenerator();
$namespace = 'carousel-control-proof';
$definition = $generator->definition($namespace);
$cases = array(
    'whitespace' => array(" \n\t ", "\r\n  "),
    'artwork-and-label' => array('<svg viewbox="0 0 24 24"><path d="M16 4L8 12L16 20"></path></svg><span>Back</span>', '<span>Forward</span><svg viewbox="0 0 24 24"><path d="M8 4L16 12L8 20"></path></svg>'),
    'empty' => array('', ''),
    'zero-label' => array('0', '0'),
);
$content = '';
foreach ($cases as $name => [$previous, $next]) {
    $attributes = array('ariaLabel' => $name, 'sourceControlArtwork' => true, 'previousControlVisual' => $previous, 'nextControlVisual' => $next);
    $shell = $generator->shell($attributes);
    $content .= '<!-- wp:' . $namespace . '/authored-carousel ' . json_encode($attributes, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . ' -->' . $shell['opening'] . $shell['closing'] . '<!-- /wp:' . $namespace . '/authored-carousel -->';
}
if (!defined('WP_PLUGIN_DIR')) {
    echo json_encode(array('content' => $content, 'definition' => $definition), JSON_THROW_ON_ERROR);
    return;
}
$plugin = WP_PLUGIN_DIR . '/carousel-control-proof';
wp_mkdir_p($plugin);
file_put_contents($plugin . '/block.json', wp_json_encode($definition['block_json']));
foreach ($definition['assets'] as $name => $contents) {
    file_put_contents($plugin . '/' . $name, $contents);
}
file_put_contents($plugin . '/view.js', $definition['view_js']);
foreach ($definition['script_dependencies'] as $name => $dependencies) {
    file_put_contents($plugin . '/' . pathinfo($name, PATHINFO_FILENAME) . '.asset.php', '<?php return ' . var_export(array('dependencies' => $dependencies, 'version' => hash('sha256', $definition['assets']['index.js'])), true) . ';');
}
file_put_contents($plugin . '/carousel-control-proof.php', '<?php /* Plugin Name: Carousel Control Proof */ add_action("init", static function () { register_block_type(__DIR__); });');
require_once ABSPATH . 'wp-admin/includes/plugin.php';
$activated = activate_plugin('carousel-control-proof/carousel-control-proof.php');
if (is_wp_error($activated)) {
    throw new RuntimeException($activated->get_error_message());
}
$id = wp_insert_post(array('post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Carousel control contract', 'post_content' => wp_slash($content)), true);
if (is_wp_error($id)) {
    throw new RuntimeException($id->get_error_message());
}
echo json_encode(array('post_id' => $id, 'cases' => array_keys($cases)), JSON_THROW_ON_ERROR);
