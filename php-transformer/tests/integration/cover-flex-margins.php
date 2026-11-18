<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

$document = require dirname(__DIR__) . '/fixtures/cover-flex-margins.php';
$theme = 'cover-flex-margins-proof';
$directory = WP_CONTENT_DIR . '/themes/' . $theme;
wp_mkdir_p($directory);
file_put_contents($directory . '/style.css', "/* Theme Name: Cover flex margins proof */\n");
file_put_contents($directory . '/theme.json', '{"version":3,"settings":{"useRootPaddingAwareAlignments":false},"styles":{"spacing":{"blockGap":"0"}}}');
file_put_contents($directory . '/index.php', '<!doctype html><html><head><?php wp_head(); ?></head><body><?php while(have_posts()){the_post();the_content();} wp_footer(); ?></body></html>');
file_put_contents($directory . '/functions.php', '<?php add_action("wp_enqueue_scripts", static function(){wp_enqueue_style("wp-block-library");wp_enqueue_global_styles();});');
wp_clean_themes_cache();
switch_theme($theme);
foreach (array('ua' => '', 'authored' => '.painted>h3{margin:12px 7px}', 'universal' => ':where(.painted)>:where(h3){margin:11px 6px}') as $variant => $extraCss) {
    // Exercise the public transform, then WordPress's real block rendering.
    $result = (new HtmlTransformer())->transform($document($extraCss))->toArray();
    $styles = '';
    foreach ($result['assets'] as $asset) {
        if (!str_ends_with($asset['path'], '.css')) continue;
        $styles .= '<style>' . $asset['content'] . '</style>';
    }
    $slug = 'cover-margins-' . $variant;
    $existing = get_page_by_path($slug, OBJECT, 'page');
    $id = wp_insert_post(array('ID' => $existing?->ID ?? 0, 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => $slug, 'post_name' => $slug, 'post_content' => wp_slash($styles . $result['serialized_blocks'])), true);
    if (is_wp_error($id)) throw new RuntimeException($id->get_error_message());
}
flush_rewrite_rules();
echo "Cover margin pages installed\n";
