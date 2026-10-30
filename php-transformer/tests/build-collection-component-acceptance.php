<?php
$root = WP_PLUGIN_DIR . '/blocks-engine-php-transformer';
require $root . '/vendor/autoload.php';
$files = require $root . '/tests/fixtures/collection-component.php';
$result = (new Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\CapturedCollectionProjector())->project($files);
if ($result['projected_count'] !== 1) throw new RuntimeException('Collection was not projected');
$transformed = (new Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer())->transform($result['files'][0]['content'])->toArray();
$plugin = WP_PLUGIN_DIR . '/collection-component-acceptance';
wp_mkdir_p($plugin);
foreach ($transformed['source_reports']['generated_blocks'] as $i => $definition) {
    $dir = $plugin . '/block-' . $i;
    wp_mkdir_p($dir);
    file_put_contents($dir . '/block.json', wp_json_encode($definition['block_json']));
    foreach ($definition['assets'] ?? array() as $name => $content) file_put_contents($dir . '/' . $name, $content);
    if (!empty($definition['view_js'])) file_put_contents($dir . '/view.js', $definition['view_js']);
    foreach ($definition['script_dependencies'] ?? array() as $name => $deps) file_put_contents($dir . '/' . pathinfo($name, PATHINFO_FILENAME) . '.asset.php', '<?php return ' . var_export(array('dependencies' => $deps, 'version' => '1'), true) . ';');
}
file_put_contents($plugin . '/collection-component-acceptance.php', '<?php /* Plugin Name: Collection Component Acceptance */ add_action("init", static function () { foreach (glob(__DIR__ . "/block-*/block.json") as $file) register_block_type(dirname($file)); });');
require_once ABSPATH . 'wp-admin/includes/plugin.php';
activate_plugin('collection-component-acceptance/collection-component-acceptance.php');
$id = wp_insert_post(array('post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Captured collection', 'post_content' => wp_slash($transformed['serialized_blocks'])));
echo wp_json_encode(array('post_id' => $id));
