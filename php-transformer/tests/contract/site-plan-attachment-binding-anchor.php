<?php
declare(strict_types=1);

/**
 * Attachment resolution rewrites image blocks (id attribute, wp-image class),
 * which changes byte lengths. A runtime binding nested after such an image
 * must still anchor to its resolved block.
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\WordPressSitePlan;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\WordPressSitePlanResolver;

$assert = static function (bool $condition, string $message): void { if (!$condition) { fwrite(STDERR, "FAIL: {$message}\n"); exit(1); } };
$png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==', true);
$present = '<!-- wp:paragraph --><p>Price 10</p><!-- /wp:paragraph -->';
$plan = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array(
    'index.html' => '<!doctype html><html><body><main><section><h1>Shop</h1><p><img src="media/product.png" alt="Product"></p><p>Price 10</p></section></main></body></html>',
    'media/product.png' => array('content' => $png, 'mime_type' => 'image/png'),
), 'runtime_declarations' => array(array(
    'kind' => 'entity_collection', 'type' => 'products', 'source_path' => 'data/products.json',
    'payload' => array('schema' => 'generic/products/v1', 'entities' => array(array('id' => 'product-1', 'bindings' => array(
        array('schema' => 'generic/block-binding/v1', 'role' => 'price', 'source_path' => 'index.html', 'search_block_markup' => $present, 'occurrence' => 1),
    )))),
))))->toArray()['source_reports']['wordpress_site_plan'];
$canonical = $plan['pages'][0]['canonical_block_markup'];
$assert(strpos($canonical, 'media/product.png') < strpos($canonical, $present) && 1 === substr_count(substr($canonical, 0, strpos($canonical, $present)), '<!-- wp:group'), 'The fixture nests the bound block after a content image inside an open parent block.');
$image = null;
foreach ($plan['assets'] as $asset) if ('product.png' === basename($asset['source_path'])) $image = $asset;
$assert('media_library' === ($image['destination'] ?? null), 'The image is a Media Library asset.');

$resolved = (new WordPressSitePlanResolver())->resolve($plan, array('theme_uri' => 'https://example.test/wp-content/themes/site', 'attachment_resolutions' => array(
    $image['token'] => array('id' => 41, 'url' => 'https://example.test/wp-content/uploads/product.png'),
)));
$page = $resolved['pages'][0]['resolved_block_markup'];
$assert(str_contains($page, 'wp-image-41'), 'The image resolves to its attachment.');
$binding = null;
foreach ($resolved['runtime_declarations'] as $declaration) if ('products' === ($declaration['type'] ?? null)) $binding = $declaration['payload']['entities'][0]['bindings'][0] ?? null;
$assert(is_array($binding) && $present === substr($page, $binding['position']['offset'], $binding['position']['length']), 'The binding position addresses its block in the resolved page.');
echo "site-plan-attachment-binding-anchor contract passed\n";
