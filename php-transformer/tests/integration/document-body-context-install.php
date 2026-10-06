<?php
declare(strict_types=1);

// Run only in an explicitly disposable WordPress acceptance site. Install the
// canonical producer writes and page payloads, without adapting their markup.
require dirname(__DIR__, 2) . '/vendor/autoload.php';
if ($baseline = getenv('BODY_CONTEXT_BASELINE_SRC')) spl_autoload_register(static function (string $name) use ($baseline): void {
    $prefix = 'Automattic\\BlocksEngine\\PhpTransformer\\';
    if (str_starts_with($name, $prefix)) {
        $file = $baseline . '/' . str_replace('\\', '/', substr($name, strlen($prefix))) . '.php';
        if (is_file($file)) require $file;
    }
}, true, true);

use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\WordPressSitePlanResolver;

if ('1' !== getenv('BODY_CONTEXT_DISPOSABLE')) throw new RuntimeException('An explicit disposable runtime is required.');
$fixture = json_decode(file_get_contents(getenv('BODY_CONTEXT_PLAN')), true, 512, JSON_THROW_ON_ERROR);
$slug = 'document-body-context';
$root = get_theme_root() . '/' . $slug;
$plan = (new WordPressSitePlanResolver())->resolve($fixture['plan'], array('theme_uri' => content_url('themes/' . $slug)));
// Each producer revision owns a fresh fixture theme. Obsolete baseline route
// templates must not take precedence over the candidate's native page template.
if (is_dir($root)) foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)) as $file) {
    if ($file->isFile()) wp_delete_file($file->getPathname());
}
foreach ($plan['writes'] as $write) {
    $path = $root . '/' . $write['target_path'];
    wp_mkdir_p(dirname($path));
    $data = $write['payload']['data'];
    if ('base64' === $write['payload']['encoding']) $data = base64_decode($data, true);
    if (!is_string($data) || strlen($data) !== file_put_contents($path, $data)) throw new RuntimeException('Fixture write failed: ' . $path);
}
switch_theme($slug);
$routes = array();
foreach ($plan['pages'] as $page) {
    $existing = get_posts(array('post_type' => 'page', 'meta_key' => '_blocks_engine_reconciliation_identity', 'meta_value' => $page['reconciliation_identity'], 'fields' => 'ids', 'numberposts' => 1));
    $id = wp_insert_post(wp_slash(array('ID' => $existing[0] ?? 0, 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => $page['title'], 'post_name' => $page['slug'], 'post_content' => $page['resolved_block_markup'])), true);
    if (is_wp_error($id)) throw new RuntimeException($id->get_error_message());
    update_post_meta($id, '_blocks_engine_reconciliation_identity', $page['reconciliation_identity']);
    if ($page['entrypoint']) { update_option('show_on_front', 'page'); update_option('page_on_front', $id); }
    $routes[] = array('id' => $id, 'route' => $page['source_path'], 'url' => home_url('/?page_id=' . $id), 'source' => str_replace('</head>', '<style>' . $fixture['css'] . '</style></head>', $fixture['sources'][$page['source_path']]));
}
file_put_contents(getenv('BODY_CONTEXT_ROUTES'), wp_json_encode($routes, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
echo wp_json_encode(array('wordpress' => get_bloginfo('version'), 'theme' => get_stylesheet(), 'routes' => $routes), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
