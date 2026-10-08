<?php
declare(strict_types=1);

// Run with WP-CLI eval 'require "/path/to/layout-media-tracks.php";' in a
// disposable site. BE_ARTIFACT_PATH is an existing writable evidence directory.
require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

wp_set_current_user(1);
$source = file_get_contents(dirname(__DIR__) . '/fixtures/layout-media-tracks.html');
$result = (new HtmlTransformer())->transform($source)->toArray();
$markup = $result['serialized_blocks'];
$id = wp_insert_post(wp_slash(array('post_title' => 'Layout track native save contract', 'post_type' => 'page', 'post_status' => 'draft', 'post_content' => $markup)), true);
if (is_wp_error($id)) {
    throw new RuntimeException($id->get_error_message());
}
$saved = get_post($id)->post_content;
if ($saved !== $markup) {
    throw new RuntimeException('WordPress did not retain the native block document.');
}
$rendered = do_blocks($saved);
$css = wp_get_global_stylesheet(array('base-layout-styles')) . wp_style_engine_get_stylesheet_from_context('block-supports');
$output = getenv('BE_ARTIFACT_PATH');
if (!is_string($output) || !is_dir($output)) {
    throw new RuntimeException('BE_ARTIFACT_PATH must be an existing evidence directory.');
}
file_put_contents($output . '/rendered.html', '<style>' . $css . '</style>' . $rendered);
file_put_contents($output . '/wordpress.json', wp_json_encode(array('wordpress' => get_bloginfo('version'), 'post_id' => $id, 'markup' => $markup, 'rendered' => $rendered, 'layout_css' => $css, 'result' => $result)));
echo wp_json_encode(array('wordpress' => get_bloginfo('version'), 'post_id' => $id, 'artifact_path' => $output)) . "\n";
