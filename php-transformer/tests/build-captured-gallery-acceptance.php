<?php
$root = defined('WP_PLUGIN_DIR') ? WP_PLUGIN_DIR . '/blocks-engine-php-transformer' : dirname(__DIR__);
require $root . '/vendor/autoload.php';
use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\WordPress\Runtime;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\WordPressSitePlanResolver;
$capture = defined('WP_PLUGIN_DIR') ? $root . '/tests/fixtures/captured-gallery' : ($argv[1] ?? $root . '/tests/fixtures/captured-gallery');
$files = array();
foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($capture, FilesystemIterator::SKIP_DOTS)) as $file) {
    if (!$file->isFile()) continue;
    $path = substr($file->getPathname(), strlen($capture) + 1);
    if (str_starts_with(basename($path), '._') || (!str_starts_with($path, 'website/') && (str_contains($path, '/') || !str_ends_with($path, '.json')))) continue;
    $files[] = array('path' => $path, 'content_base64' => base64_encode(file_get_contents($file->getPathname())));
}
$result = (new ArtifactCompiler())->compile(array('entrypoint' => 'website/index.html', 'files' => $files))->toArray();
$plan = $result['source_reports']['wordpress_site_plan'] ?? array();
$content = (string) ($plan['pages'][0]['canonical_block_markup'] ?? '');
$definitions = $result['source_reports']['companion_plugin_payload']['blocks'] ?? array();
$blocks = (new Runtime())->parseBlocks($content);
$flatten = static function (array $blocks) use (&$flatten): array { $all = array(); foreach ($blocks as $block) { $all[] = $block; $all = array_merge($all, $flatten($block['innerBlocks'] ?? array())); } return $all; };
$tree = $flatten($blocks);
$carousels = array_values(array_filter($tree, static fn(array $block): bool => str_ends_with($block['blockName'] ?? '', '/authored-carousel')));
$dialogs = array_values(array_filter($tree, static fn(array $block): bool => str_ends_with($block['blockName'] ?? '', '/captured-dialog')));
$output = array('status' => $result['status'], 'carousels' => array_column($carousels, 'attrs'), 'dialogs' => array_column($dialogs, 'attrs'), 'native_images' => count(array_filter($tree, static fn(array $block): bool => 'core/image' === ($block['blockName'] ?? ''))), 'diagnostics' => $result['diagnostics'] ?? array(), 'source_reports' => array_diff_key($result['source_reports'], array_flip(array('wordpress_site_plan', 'companion_plugin_payload'))), 'content' => $content);
if (!defined('WP_PLUGIN_DIR')) { echo json_encode($output, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR); return; }
if (count($dialogs) < 1 || count($carousels) < 2 || $output['native_images'] < 40) throw new RuntimeException('Actual capture did not compile to native image cycles and a captured dialog.');
$plugin = WP_PLUGIN_DIR . '/captured-gallery-acceptance';
wp_mkdir_p($plugin);
foreach ($definitions as $index => $definition) {
    $directory = $plugin . '/block-' . $index;
    wp_mkdir_p($directory);
    file_put_contents($directory . '/block.json', wp_json_encode($definition['block_json']));
    foreach ($definition['assets'] ?? array() as $name => $contents) file_put_contents($directory . '/' . $name, $contents);
    if (!empty($definition['view_js'])) file_put_contents($directory . '/view.js', $definition['view_js']);
    foreach ($definition['script_dependencies'] ?? array() as $name => $dependencies) file_put_contents($directory . '/' . pathinfo($name, PATHINFO_FILENAME) . '.asset.php', '<?php return ' . var_export(array('dependencies' => $dependencies, 'version' => '1'), true) . ';');
}
$resolved = (new WordPressSitePlanResolver())->resolve($plan, array('theme_uri' => plugins_url('site-plan', $plugin . '/captured-gallery-acceptance.php')));
$content = $resolved['pages'][0]['resolved_block_markup'];
$css = '';
foreach ($resolved['writes'] as $write) {
    $file = $plugin . '/site-plan/' . $write['target_path'];
    wp_mkdir_p(dirname($file));
    $payload = $write['payload'];
    $data = 'base64' === $payload['encoding'] ? base64_decode($payload['data'], true) : $payload['data'];
    file_put_contents($file, $data);
    if (str_ends_with($file, '.css')) $css .= $data . "\n";
}
file_put_contents($plugin . '/source.css', $css);
file_put_contents($plugin . '/captured-gallery-acceptance.php', '<?php /* Plugin Name: Captured Gallery Acceptance */ add_action("init", static function () { foreach (glob(__DIR__ . "/block-*/block.json") as $file) register_block_type(dirname($file)); }); add_action("enqueue_block_assets", static function () { wp_enqueue_style("captured-gallery-source", plugins_url("source.css", __FILE__)); });');
require_once ABSPATH . 'wp-admin/includes/plugin.php';
$activated = activate_plugin('captured-gallery-acceptance/captured-gallery-acceptance.php');
if (is_wp_error($activated)) throw new RuntimeException($activated->get_error_message());
$output['post_id'] = wp_insert_post(array('post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Captured gallery acceptance', 'post_content' => wp_slash($content)));
$output['content'] = $content;
unset($output['source_reports']);
echo json_encode($output, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
