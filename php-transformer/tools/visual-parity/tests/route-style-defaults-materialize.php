<?php
// Run with wp eval-file in a disposable site only. Arguments: plans.json order.
$plan = json_decode(file_get_contents($args[0]), true, 512, JSON_THROW_ON_ERROR)[(int) ($args[1] ?? 0)];
$slug = 'route-style-defaults-' . (int) ($args[1] ?? 0);
$theme = get_theme_root() . '/' . $slug;
foreach ($plan['writes'] as $write) {
    $path = $theme . '/' . $write['target_path'];
    wp_mkdir_p(dirname($path));
    file_put_contents($path, 'base64' === $write['payload']['encoding'] ? base64_decode($write['payload']['data']) : $write['payload']['data']);
}
foreach ($plan['assets'] as $asset) {
    $path = $theme . '/' . $asset['target_path'];
    wp_mkdir_p(dirname($path));
    file_put_contents($path, $asset['content'] ?? base64_decode($asset['content_base64'] ?? ''));
}
$pages = array();
foreach ($plan['pages'] as $page) {
    $existing = get_page_by_path($page['slug']);
    $id = wp_insert_post(wp_slash(array(
        'ID' => $existing ? $existing->ID : 0,
        'post_type' => $page['post_type'], 'post_status' => 'publish',
        'post_name' => $page['slug'], 'post_title' => $page['title'],
        'post_content' => $page['canonical_block_markup'],
    )), true);
    if (is_wp_error($id)) throw new RuntimeException($id->get_error_message());
    update_post_meta($id, '_blocks_engine_reconciliation_identity', $page['reconciliation_identity']);
    if ($page['entrypoint']) { update_option('page_on_front', $id); update_option('show_on_front', 'page'); }
    $pages[] = array('id' => $id, 'title' => $page['title'], 'source_path' => $page['source_path']);
}
switch_theme($slug);
update_option('permalink_structure', '/%postname%/');
flush_rewrite_rules();
foreach ($pages as &$page) $page['url'] = get_permalink($page['id']);
echo wp_json_encode(array('wordpress' => get_bloginfo('version'), 'pages' => $pages));
