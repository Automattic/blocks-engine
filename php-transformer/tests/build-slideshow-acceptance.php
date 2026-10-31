<?php
$root = defined('WP_PLUGIN_DIR') ? WP_PLUGIN_DIR . '/blocks-engine-php-transformer' : dirname(__DIR__);
require $root . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

$files = require $root . '/tests/fixtures/slideshow-components.php';
$projection = (new Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\CapturedDialogProjector())->project($files);
$source = array('images' => 5);
$result = (new HtmlTransformer())->transform($projection['files'][0]['content'])->toArray();
$content = $result['serialized_blocks'];
if (1 !== substr_count($content, '<!-- wp:custom/authored-carousel ') || 5 !== substr_count($content, '<!-- wp:image ')) {
    throw new RuntimeException('Expected five logical frames.');
}
$definitions = $result['source_reports']['generated_blocks'];
$css = implode("\n", array_map(static fn (array $asset): string => (string) ($asset['content'] ?? ''), $result['assets']));
$output = array('content' => $content, 'source' => $source, 'css' => $css, 'definitions' => $definitions, 'validity' => $result['source_reports']['wp_block_validity'], 'fallbacks' => $result['fallbacks']);

// The same compiled fixture can be inspected standalone or installed through
// wp eval-file inside the existing disposable editor-acceptance Docker runner.
if (defined('WP_PLUGIN_DIR')) {
    $plugin = WP_PLUGIN_DIR . '/structural-carousel-acceptance';
    wp_mkdir_p($plugin);
    foreach (array('one', 'two', 'four', 'five') as $index => $name) {
        file_put_contents($plugin . '/' . $name . '.svg', '<svg xmlns="http://www.w3.org/2000/svg" width="800" height="450"><rect width="800" height="450" fill="' . array('#468', '#684', '#846', '#486')[$index] . '"/></svg>');
        $content = str_replace('../website/assets/' . $name . '.jpg', plugins_url($name . '.svg', $plugin . '/structural-carousel-acceptance.php'), $content);
    }
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
