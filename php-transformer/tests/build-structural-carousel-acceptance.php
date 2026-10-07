<?php
$root = defined('WP_PLUGIN_DIR') ? WP_PLUGIN_DIR . '/blocks-engine-php-transformer' : dirname(__DIR__);
require $root . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

$source = require $root . '/tests/fixtures/captured-carousel.php';
$result = (new HtmlTransformer())->transform('<style>' . $source['css'] . '</style>' . $source['html'])->toArray();
$content = $result['serialized_blocks'];
if ($source['carousels'] !== substr_count($content, '<!-- wp:custom/authored-carousel ') || $source['images'] !== substr_count($content, '<!-- wp:image ')) {
    throw new RuntimeException('Expected two responsive carousels with twenty native images each.');
}
$definitions = $result['source_reports']['generated_blocks'];
$css = implode("\n", array_map(static fn (array $asset): string => (string) ($asset['content'] ?? ''), $result['assets']));
$output = array('content' => $content, 'source' => $source, 'css' => $css, 'definitions' => $definitions, 'validity' => $result['source_reports']['wp_block_validity'], 'fallbacks' => $result['fallbacks']);

// The same compiled fixture can be inspected standalone or installed through
// wp eval-file inside the existing disposable editor-acceptance Docker runner.
if (defined('WP_PLUGIN_DIR')) {
    $plugin = WP_PLUGIN_DIR . '/structural-carousel-acceptance';
    wp_mkdir_p($plugin);
    foreach ($definitions as $index => $definition) {
        $directory = $plugin . '/block-' . $index;
        wp_mkdir_p($directory);
        file_put_contents($directory . '/block.json', wp_json_encode($definition['block_json']));
        foreach ($definition['assets'] as $name => $contents) {
            file_put_contents($directory . '/' . $name, $contents);
        }
        if (!empty($definition['view_js'])) {
            file_put_contents($directory . '/view.js', $definition['view_js']);
        }
        foreach ($definition['script_dependencies'] ?? array() as $name => $dependencies) {
            file_put_contents($directory . '/' . pathinfo($name, PATHINFO_FILENAME) . '.asset.php', '<?php return ' . var_export(array('dependencies' => $dependencies, 'version' => '1'), true) . ';');
        }
    }
    file_put_contents($plugin . '/source.css', $css);
    file_put_contents($plugin . '/structural-carousel-acceptance.php', '<?php /* Plugin Name: Structural Carousel Acceptance */'
        . ' add_action("init", static function () { foreach (glob(__DIR__ . "/block-*/block.json") as $file) register_block_type(dirname($file)); });'
        . ' add_action("enqueue_block_assets", static function () { wp_enqueue_style("structural-carousel-source", plugins_url("source.css", __FILE__)); });');
    require_once ABSPATH . 'wp-admin/includes/plugin.php';
    $activated = activate_plugin('structural-carousel-acceptance/structural-carousel-acceptance.php');
    if (is_wp_error($activated)) {
        throw new RuntimeException($activated->get_error_message());
    }
    $postId = wp_insert_post(array('post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Structural carousel acceptance', 'post_content' => wp_slash($content)));
    if (is_wp_error($postId) || !$postId) {
        throw new RuntimeException('Could not create disposable carousel page.');
    }
    $output['post_id'] = (int) $postId;
}
echo json_encode($output, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
