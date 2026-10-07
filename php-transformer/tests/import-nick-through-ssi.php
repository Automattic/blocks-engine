<?php

$engineRoot = (string) (getenv('BLOCKS_ENGINE_PHP_TRANSFORMER_ROOT') ?: dirname(__DIR__));
$artifactPath = (string) getenv('NICK_THEME_ARTIFACT_PATH');
if ('' === $artifactPath || !is_file($artifactPath)) throw new RuntimeException('NICK_THEME_ARTIFACT_PATH must reference the browser-captured Nick artifact.');
require_once WP_PLUGIN_DIR . '/static-site-importer/vendor/autoload.php';
foreach (Composer\Autoload\ClassLoader::getRegisteredLoaders() as $loader) {
    $loader->setPsr4('Automattic\\BlocksEngine\\PhpTransformer\\', rtrim($engineRoot, '/') . '/src/', true);
}
require_once WP_PLUGIN_DIR . '/static-site-importer/static-site-importer.php';

$artifact = json_decode((string) file_get_contents($artifactPath), true, 512, JSON_THROW_ON_ERROR);
$result = Static_Site_Importer_Theme_Generator::import_website_artifact($artifact, array(
    'slug' => 'nick-theme-restoration',
    'name' => 'Nick Diego',
    'url' => 'https://nickdiego.com',
    'theme_materialization' => 'block',
    'require_proven_dynamic_client_assets' => false,
));
if (is_wp_error($result)) throw new RuntimeException($result->get_error_code() . ': ' . $result->get_error_message());

$pages = get_posts(array('post_type' => 'page', 'post_status' => array('publish', 'draft', 'private'), 'numberposts' => 100));
$nickPages = array_values(array_map(static fn (WP_Post $post): array => array(
    'id' => (int) $post->ID,
    'title' => (string) $post->post_title,
    'status' => (string) $post->post_status,
    'content' => (string) $post->post_content,
), array_filter($pages, static fn (WP_Post $post): bool => 'Nick Diego' === $post->post_title)));
$blockName = '';
if (preg_match('/<!--\s*wp:([a-z0-9_-]+\/theme-toggle)\b/', (string) ($nickPages[0]['content'] ?? ''), $match)) $blockName = $match[1];
$declarations = $result['materialization_receipt']['plan']['runtime_declarations'] ?? array();
$themeDeclaration = current(array_filter($declarations, static fn (array $declaration): bool => 'theme_control' === ($declaration['kind'] ?? null))) ?: array();
$ownership = $themeDeclaration['payload']['ownership'] ?? array();
update_option('ssi_nick_theme_preference_ownership', $themeDeclaration, false);
update_option('ssi_nick_theme_source_page_id', (int) ($nickPages[0]['id'] ?? 0), false);
$summary = array(
    'status' => $result['materialization_receipt']['status'] ?? null,
    'theme_slug' => $result['theme_slug'] ?? '',
    'theme_dir' => $result['theme_dir'] ?? '',
    'page' => array(
        'id' => $nickPages[0]['id'] ?? null,
        'title' => $nickPages[0]['title'] ?? '',
        'status' => $nickPages[0]['status'] ?? '',
        'has_theme_block' => str_contains((string) ($nickPages[0]['content'] ?? ''), '/theme-toggle'),
    ),
    'block_name' => $blockName,
    'runtime_declaration' => array(
        'reconciliation_identity' => $themeDeclaration['reconciliation_identity'] ?? null,
        'runtime_script_path' => $ownership['runtime_script_path'] ?? null,
        'runtime_script_sha256' => $ownership['runtime_script_sha256'] ?? null,
        'runtime_script_content_hash' => is_string($ownership['runtime_script_content'] ?? null) ? hash('sha256', $ownership['runtime_script_content']) : null,
        'group_selector' => $ownership['group_selector'] ?? null,
    ),
    'companion' => $result['materialization_receipt']['completed']['companion_plugin'] ?? array(),
    'theme_directory' => (string) ($result['theme_dir'] ?? ''),
);
if ('completed' !== $summary['status'] || array() === $nickPages || '' === $blockName
    || $summary['runtime_declaration']['runtime_script_sha256'] !== $summary['runtime_declaration']['runtime_script_content_hash']
    || !is_file($summary['theme_directory'] . '/style.css')
    || 'installed_activated' !== ($summary['companion']['status'] ?? null)) {
    throw new RuntimeException('SSI did not materialize the qualified Nick control and full companion payload: ' . wp_json_encode($summary));
}
echo wp_json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
