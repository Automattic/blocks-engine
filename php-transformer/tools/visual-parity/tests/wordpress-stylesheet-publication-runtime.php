<?php
/** Run the emitted theme bootstrap against real WordPress, without installing it. */
$input = json_decode(file_get_contents($args[0]), true, 512, JSON_THROW_ON_ERROR);
require getenv('BLOCKS_ENGINE_AUTOLOAD') ?: dirname(__DIR__, 3) . '/vendor/autoload.php';
$resolved = (new \Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\WordPressSitePlanResolver())->resolve($input['plan'], array('theme_uri' => $input['base']));
$page = $input['page'] ?? null;
$GLOBALS['wp_query'] = new WP_Query();
if (null === $page) {
    // A WordPress-native route that no source document owns (search).
    $GLOBALS['wp_query']->is_search = true;
} else {
    $post = new WP_Post((object) array('ID' => 987654321, 'post_type' => 'page', 'post_name' => trim($page['route']['path'], '/'), 'post_parent' => 0, 'post_status' => 'publish'));
    wp_cache_set($post->ID, $post, 'posts');
    $GLOBALS['wp_query']->is_page = true;
    $GLOBALS['wp_query']->is_singular = true;
    $GLOBALS['wp_query']->queried_object = $post;
    $GLOBALS['wp_query']->queried_object_id = $post->ID;
    add_filter('pre_option_show_on_front', static fn() => 'page');
    add_filter('pre_option_page_on_front', static fn() => '/' === $page['route']['path'] ? $post->ID : 1);
    add_filter('get_post_metadata', static fn($value, $id, $key) => $id === $post->ID && '_blocks_engine_reconciliation_identity' === $key ? array($page['reconciliation_identity']) : $value, 10, 3);
}
add_filter('theme_file_uri', static fn($uri, $file) => $input['base'] . '/' . $file, 10, 2);
$before = $GLOBALS['wp_filter']['wp_enqueue_scripts']->callbacks ?? array();
eval(preg_replace('/^<\?php\s*/', '', $input['bootstrap']));
$callbacks = $GLOBALS['wp_filter']['wp_enqueue_scripts']->callbacks;
$GLOBALS['wp_styles'] = new WP_Styles();
foreach ($callbacks as $priority => $entries) foreach ($entries as $id => $callback) {
    if (!isset($before[$priority][$id])) ($callback['function'])();
}
$handles = array_values(array_filter(wp_styles()->queue, static fn($handle) => str_starts_with($handle, 'blocks-engine-')));
$enqueued = array_map(static fn($handle) => array('handle' => $handle, 'src' => wp_styles()->registered[$handle]->src, 'media' => wp_styles()->registered[$handle]->args), $handles);
ob_start();
wp_styles()->do_items($handles);
$head = ob_get_clean();
$markup = $input['markup'];
// Template parts render around the route content exactly as the theme writes them.
$partWrites = array_column(array_filter($resolved['writes'], static fn(array $write): bool => 'theme_template_part' === $write['kind']), null, 'target_path');
foreach (array_reverse($input['parts'] ?? array()) as $slug) $markup = $partWrites['parts/' . $slug . '.html']['payload']['data'] . $markup;
$saved = serialize_blocks(parse_blocks($markup));
$names = array();
$walk = static function (array $blocks) use (&$walk, &$names): void { foreach ($blocks as $block) { if ($block['blockName']) $names[] = $block['blockName']; $walk($block['innerBlocks']); } };
$walk(parse_blocks($saved));
echo json_encode(array('version' => get_bloginfo('version'), 'head' => $head, 'enqueued' => $enqueued, 'html' => do_blocks($saved), 'stable' => $saved === serialize_blocks(parse_blocks($saved)), 'names' => $names, 'unregistered' => array_values(array_filter($names, static fn(string $name): bool => !WP_Block_Type_Registry::get_instance()->is_registered($name))), 'resources' => array_values(array_filter($resolved['writes'], static fn(array $write): bool => 'theme_asset' === $write['kind'] && 'utf8' === $write['payload']['encoding']))), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
