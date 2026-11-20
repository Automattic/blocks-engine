<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\WordPressSitePlanResolver;

$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};
$png = (string) base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==');
$rows = array(array('name' => 'Catalog', 'slug' => 'catalog', 'regular_price' => '10.00', 'image' => 'media/catalog.png', 'images' => array(array('src' => 'media/gallery.png'), array('src' => 'media/shared.png')), 'variants' => array(array('image' => 'media/variant.png'))), array('image' => 'missing.png'), array('image' => '../unsafe.png'), array('image' => 'notes.txt'), array('image' => 'https://example.test/remote.png'));
$result = (new ArtifactCompiler())->compile(array(
    'entrypoint' => 'index.html',
    'files' => array('index.html' => '<!doctype html><html><head><title>Catalog</title></head><body><main><h1>Catalog</h1><img src="media/shared.png" alt="Shared"></main></body></html>', 'media/catalog.png' => $png, 'media/gallery.png' => $png, 'media/variant.png' => $png, 'media/shared.png' => $png, 'notes.txt' => 'Not an image'),
    'runtime_declarations' => array(array('kind' => 'entity_collection', 'type' => 'products', 'source_path' => 'data/products.json', 'payload' => array('schema' => 'generic/products/v1', 'entities' => $rows))),
))->toArray();
$plan = $result['source_reports']['wordpress_site_plan'] ?? array();
$assert(array() !== $plan, 'The neutral catalog composes a plan: ' . json_encode($result['source_reports']['wordpress_site_plan_diagnostics'] ?? array()));
$resolved = (new WordPressSitePlanResolver())->resolve($plan, array('theme_uri' => 'https://example.test/theme'));
foreach (array('catalog', 'gallery', 'variant', 'shared') as $name) {
    $path = 'media/' . $name . '.png';
    $assets = array_values(array_filter($plan['assets'], static fn(array $asset): bool => $path === $asset['source_path']));
    $assert(1 === count($assets), 'Exactly one asset for ' . $path);
    $asset = $assets[0];
    $assert(1 === count(array_filter($plan['reference_tokens'], static fn(array $token): bool => $token['token'] === $asset['token'])), 'A reference token for ' . $path);
    $assert(1 === count(array_filter($resolved['writes'], static fn(array $write): bool => 'theme_asset' === $write['kind'] && $asset['target_path'] === $write['target_path'])), 'A resolved theme asset write for ' . $path);
}
$diagnostics = json_encode($plan['diagnostics']);
foreach (array('missing_image', 'unsafe_path', 'non_image') as $reason) $assert(str_contains((string) $diagnostics, $reason), 'Diagnostic for ' . $reason);
$assert(!str_contains((string) $diagnostics, 'remote.png'), 'Remote images are untouched.');
echo "product declaration images contract passed\n";
