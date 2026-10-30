<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\WordPressSitePlanResolver;
use Automattic\BlocksEngine\PhpTransformer\WordPress\Runtime;

$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};
$html = file_get_contents(dirname(__DIR__) . '/fixtures/text-video-grid.html');
$flatten = static function (array $blocks) use (&$flatten): array {
    $out = array();
    foreach ($blocks as $block) { $out[] = $block; array_push($out, ...$flatten($block['innerBlocks'] ?? array())); }
    return $out;
};
foreach (array(true, false) as $controls) {
    $source = $controls ? $html : str_replace(' controls', '', $html);
    $result = (new HtmlTransformer())->transform($source)->toArray();
    $blocks = $flatten($result['blocks']);
    $names = array_column($blocks, 'blockName');
    $assert(!in_array('core/media-text', $names, true), 'Video grids must not promote to media-text.');
    $assert(array() === $result['fallbacks'], 'The grid needs no fallback.');
    $video = current(array_filter($blocks, static fn(array $block): bool => 'core/video' === $block['blockName']));
    foreach (array('poster' => 'poster.jpg', 'preload' => 'metadata', 'controls' => $controls, 'loop' => true, 'muted' => true, 'playsInline' => true) as $name => $value) {
        $assert($value === ($video['attrs'][$name] ?? null), 'core/video retains ' . $name);
    }
    $markup = $result['serialized_blocks'];
    $assert(strpos($markup, 'Watch the build') < strpos($markup, '<video'), 'Text precedes video at every viewport.');
    $assert(str_contains($markup, 'blocks-engine-css-owned-grid'), 'The source grid stays a native group grid.');
    $css = implode("\n", array_column($result['assets'], 'content'));
    $assert(str_contains($css, 'grid-template-columns:1fr 1.25fr;gap:44px') && str_contains($css, '@media (max-width:860px){.split .wrap{grid-template-columns:1fr;gap:28px}}'), 'Desktop tracks and authored collapse breakpoint remain CSS-owned.');
}
$artifact = array('entrypoint' => 'index.html', 'files' => array(
    'index.html' => $html,
    'clip.mp4' => array('path' => 'clip.mp4', 'kind' => 'video', 'mime_type' => 'video/mp4', 'content_base64' => base64_encode('video')),
    'poster.jpg' => array('path' => 'poster.jpg', 'kind' => 'image', 'mime_type' => 'image/jpeg', 'content_base64' => base64_encode('poster')),
));
$plan = (new ArtifactCompiler())->compile($artifact)->toWordPressSitePlanView()['wordpress_site_plan'];
$attachments = array();
foreach ($plan['assets'] as $asset) {
    if (!in_array($asset['source_path'], array('clip.mp4', 'poster.jpg'), true)) continue;
    $assert('media_library' === $asset['destination'], 'Video and poster are Media Library assets.');
    $attachments[$asset['token']] = array('id' => count($attachments) + 41, 'url' => 'https://example.test/uploads/' . basename($asset['source_path']));
}
$assert(2 === count($attachments), 'Both media files are inventoried.');
$resolved = (new WordPressSitePlanResolver())->resolve($plan, array('theme_uri' => 'https://example.test/theme', 'attachment_resolutions' => $attachments));
$markup = $resolved['pages'][0]['resolved_block_markup'];
$assert(str_contains($markup, 'https://example.test/uploads/clip.mp4') && str_contains($markup, 'https://example.test/uploads/poster.jpg'), 'Media Library URLs replace local video and poster references.');
$resolvedBlocks = $flatten((new Runtime())->parseBlocks($markup));
$video = current(array_filter($resolvedBlocks, static fn(array $block): bool => 'core/video' === $block['blockName']));
$assert(41 === ($video['attrs']['id'] ?? null) && !str_contains($markup, '/theme/assets/'), 'The editable video binds its own attachment ID rather than theme assets.');
echo "Text/video grid contract passed.\n";
