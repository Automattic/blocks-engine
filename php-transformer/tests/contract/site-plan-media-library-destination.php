<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\WordPressSitePlan;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\WordPressSitePlanResolver;

$assert = static function (bool $condition, string $message): void { if (!$condition) throw new RuntimeException($message); };
$png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==', true);
$artifact = array('entrypoint' => 'index.html', 'files' => array(
    'index.html' => '<!doctype html><html><head><link rel="stylesheet" href="site.css"></head><body><main><h1>Gallery</h1><p><img src="media/content.png" alt="Content"></p><p><img src="media/shared.png" alt="Shared"></p><div class="hero"><p>Hero</p></div></main></body></html>',
    'site.css' => array('content' => '.hero{background-image:url(media/texture.png)}body{background:url(media/shared.png)}', 'mime_type' => 'text/css'),
    'media/content.png' => array('content' => $png, 'mime_type' => 'image/png'),
    'media/texture.png' => array('content' => $png, 'mime_type' => 'image/png'),
    'media/shared.png' => array('content' => $png, 'mime_type' => 'image/png'),
));
$plan = (new ArtifactCompiler())->compile($artifact)->toArray()['source_reports']['wordpress_site_plan'];
$assert(WordPressSitePlan::SCHEMA === 'blocks-engine/wordpress-site-plan/v3', 'The plan schema advertises consumers and destinations.');
$assets = array();
foreach ($plan['assets'] as $asset) $assets[basename($asset['source_path'])] = $asset;
$content = $assets['content.png']; $texture = $assets['texture.png']; $shared = $assets['shared.png'];
$blockConsumers = static fn(array $asset): array => array_values(array_filter($asset['consumers'], static fn(array $consumer): bool => 'block' === $consumer['kind']));
$styleConsumers = static fn(array $asset): array => array_values(array_filter($asset['consumers'], static fn(array $consumer): bool => 'stylesheet' === $consumer['kind']));

$assert('media_library' === $content['destination'] && array() === $styleConsumers($content) && 'core/image' === ($blockConsumers($content)[0]['block_name'] ?? null) && 'url' === $blockConsumers($content)[0]['attribute_path'] && array() === $content['theme_write_reasons'], 'An image-only asset is a Media Library asset consumed by a core/image url.');
$assert('theme' === $texture['destination'] && array() === $blockConsumers($texture) && 1 === count($styleConsumers($texture)) && array() === $texture['theme_write_reasons'], 'A CSS-only asset stays in the theme.');
$assert('media_library' === $shared['destination'] && 1 === count($blockConsumers($shared)) && 1 === count($styleConsumers($shared)) && array(array('reason' => 'attachment_url_required', 'target_path' => $styleConsumers($shared)[0]['target_path'], 'count' => 1)) === $shared['theme_write_reasons'], 'A shared asset is a Media Library asset with a counted stylesheet theme write.');

$resolver = new WordPressSitePlanResolver();
$themeUri = 'https://example.test/wp-content/themes/site';
// Trunk resolution: every token resolves to the theme URL by plain substitution.
$references = WordPressSitePlanResolver::references($plan['reference_tokens'], $themeUri);
$baseline = $resolver->resolve($plan, array('theme_uri' => $themeUri));
foreach (array('pages', 'templates', 'template_parts') as $kind) foreach ($plan[$kind] as $index => $document) $assert(strtr($document['canonical_block_markup'], $references) === $baseline[$kind][$index]['resolved_block_markup'], "Without attachments, {$kind} resolve byte-identically to theme URLs.");
foreach ($plan['writes'] as $index => $write) if ('utf8' === $write['payload']['encoding']) $assert(strtr($write['payload']['data'], WordPressSitePlanResolver::referencesForWrite($plan['reference_tokens'], $themeUri, $write['target_path'])) === $baseline['writes'][$index]['payload']['data'], 'Without attachments, writes resolve byte-identically to trunk.');
$assert(count($plan['writes']) === count($baseline['writes']) && !isset($baseline['resolution']['attachment_resolutions']), 'Without attachments, every theme write is kept.');

$resolved = $resolver->resolve($plan, array('theme_uri' => $themeUri, 'attachment_resolutions' => array(
    $content['token'] => array('id' => 41, 'url' => 'https://cdn.example.test/uploads/content.png'),
)));
$targets = array_column($resolved['writes'], 'target_path');
$page = $resolved['pages'][0]['resolved_block_markup'];
$assert(!in_array($content['target_path'], $targets, true) && in_array($texture['target_path'], $targets, true) && in_array($shared['target_path'], $targets, true), 'The resolved image-only asset has zero theme writes; the others keep theirs.');
$assert(str_contains($page, '"id":41') && str_contains($page, 'src="https://cdn.example.test/uploads/content.png"') && str_contains($page, 'wp-image-41'), 'The image block carries the attachment id, URL and wp-image class.');
$assert(str_contains($page, $themeUri . '/' . $shared['target_path']), 'Unresolved Media Library assets keep their theme URL.');
$css = implode('', array_map(static fn(array $write): string => 'utf8' === $write['payload']['encoding'] && str_ends_with($write['target_path'], '.css') ? $write['payload']['data'] : '', $resolved['writes']));
$baselineCss = implode('', array_map(static fn(array $write): string => 'utf8' === $write['payload']['encoding'] && str_ends_with($write['target_path'], '.css') ? $write['payload']['data'] : '', $baseline['writes']));
$assert($css === $baselineCss, 'The CSS-only asset is unchanged.');
WordPressSitePlan::assertValid($resolved);

// Resolving the stylesheet consumer too drops the shared theme write.
$both = $resolver->resolve($plan, array('theme_uri' => $themeUri, 'attachment_resolutions' => array(
    $shared['token'] => array('id' => 42, 'url' => 'https://cdn.example.test/uploads/shared.png'),
)));
$bothCss = implode('', array_map(static fn(array $write): string => str_ends_with($write['target_path'], '.css') && 'utf8' === $write['payload']['encoding'] ? $write['payload']['data'] : '', $both['writes']));
$assert(!in_array($shared['target_path'], array_column($both['writes'], 'target_path'), true) && str_contains($bothCss, 'https://cdn.example.test/uploads/shared.png') && str_contains($both['pages'][0]['resolved_block_markup'], '"id":42'), 'A publisher-resolved stylesheet consumer needs no theme write.');

foreach (array(array($texture['token'] => array('id' => 1, 'url' => 'https://x.test/a.png')), array($content['token'] => array('id' => 0, 'url' => 'https://x.test/a.png')), array($content['token'] => array('id' => 1, 'url' => 'javascript:alert(1)'))) as $invalid) {
    try { $resolver->resolve($plan, array('theme_uri' => $themeUri, 'attachment_resolutions' => $invalid)); $assert(false, 'Invalid attachment resolution accepted.'); } catch (InvalidArgumentException) {}
}
echo "site-plan-media-library-destination contract passed\n";
