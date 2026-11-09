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
$ringChartHtml = <<<'HTML'
<!doctype html>
<html lang="en"><head><meta charset="utf-8"><title>Ring Chart</title>
<link href="https://fonts.googleapis.com/css2?family=Red+Hat+Display:wght@400;800&display=swap" rel="stylesheet">
<style>body{font-family:"Red Hat Display",sans-serif;margin:0;padding:40px}</style></head>
<body><main><h1>Progress this week</h1><p>A ring chart drawn as inline SVG with a label set in the page's web font.</p>
<svg width="138" height="138" viewBox="0 0 138 138" aria-hidden="true"><circle cx="69" cy="69" r="56" fill="none" stroke="#15171c" stroke-width="14"/><text x="69" y="72" text-anchor="middle" font-family="Red Hat Display" font-weight="800" font-size="28" fill="#15171c">40%</text></svg>
<svg width="40" height="40" viewBox="0 0 40 40" aria-hidden="true"><circle cx="20" cy="20" r="16" fill="#fcc71e"/></svg>
</main></body></html>
HTML;
$ringChartResult = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array('index.html' => $ringChartHtml)))->toArray();
$ringChartPlan = $ringChartResult['source_reports']['wordpress_site_plan'];
$ringChartConsumers = $ringChartPlan['theme']['font_materialization']['webfont_contract']['svg_consumers'];
$assert(1 === count($ringChartConsumers), 'ring chart records only the webfont text SVG as a font consumer');
$ringChartWrites = array_column($ringChartPlan['writes'], null, 'target_path');
$ringChartAttachments = array();
$ringChartSvgCount = 0;
foreach ($ringChartPlan['assets'] as $ringChartAsset) {
    if ('media_library' === $ringChartAsset['destination']) $ringChartAttachments[$ringChartAsset['token']] = array('id' => count($ringChartAttachments) + 1, 'url' => 'https://example.test/uploads/' . basename($ringChartAsset['target_path']));
    if ('image/svg+xml' !== ($ringChartAsset['mime_type'] ?? null)) continue;
    ++$ringChartSvgCount;
    $assert(in_array('block', array_column($ringChartAsset['consumers'], 'kind'), true), 'both ring chart SVGs have block consumers');
    if ($ringChartConsumers[0]['source_path'] === $ringChartAsset['source_path']) {
        $assert('theme' === $ringChartAsset['destination'] && isset($ringChartWrites[$ringChartAsset['target_path']]), 'block-referenced webfont SVG retains its theme destination and write');
        $ringChartFontTarget = $ringChartAsset['target_path'];
    } else {
        $assert('media_library' === $ringChartAsset['destination'], 'block-referenced SVG without webfont text stays in the Media Library');
    }
}
$assert(2 === $ringChartSvgCount && 1 === count($ringChartAttachments), 'ring chart routes only the non-font SVG to media resolution');
$resolvedRingChartPlan = $resolver->resolve($ringChartPlan, array('theme_uri' => $themeUri, 'attachment_resolutions' => $ringChartAttachments));
WordPressSitePlan::assertValid($resolvedRingChartPlan);
$assert(isset(array_column($resolvedRingChartPlan['writes'], null, 'target_path')[$ringChartFontTarget]), 'media resolution preserves the webfont SVG theme write');
$assert(str_contains($resolvedRingChartPlan['pages'][0]['resolved_block_markup'], $themeUri . '/' . $ringChartFontTarget), 'webfont SVG block reference resolves to the theme URL');
$assert(str_contains($resolvedRingChartPlan['pages'][0]['resolved_block_markup'], reset($ringChartAttachments)['url']), 'non-font SVG block reference resolves to its attachment URL');
echo "site-plan-media-library-destination contract passed\n";
