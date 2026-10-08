<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\WordPressSitePlanResolver;

$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};
$html = file_get_contents(dirname(__DIR__) . '/fixtures/native-video-poster.html');
$result = (new HtmlTransformer())->transform($html)->toArray();
$assert(array() === $result['fallbacks'], 'A configured custom host with explicit native video and decorative poster has no unsupported content loss.');
$flatten = static function (array $blocks) use (&$flatten): array {
    $out = array();
    foreach ($blocks as $block) { $out[] = $block; array_push($out, ...$flatten($block['innerBlocks'] ?? array())); }
    return $out;
};
$blocks = $flatten($result['blocks']);
$video = current(array_filter($blocks, static fn(array $block): bool => 'core/video' === $block['blockName']));
$image = current(array_filter($blocks, static fn(array $block): bool => 'core/image' === $block['blockName']));
$assert(is_array($video) && is_array($image) && 'clip.webm' === $video['attrs']['src'] && 'cover.svg' === $image['attrs']['url'], 'Playable media and the separate poster remain editable native blocks.');
foreach (array('autoplay', 'muted', 'loop', 'playsInline') as $attribute) $assert(true === $video['attrs'][$attribute], 'Native playback retains ' . $attribute . '.');
$assert(str_contains($result['serialized_blocks'], 'id="ambient"') && str_contains($result['serialized_blocks'], 'id="cover"') && str_contains($result['serialized_blocks'], 'opacity:0') && !str_contains($result['serialized_blocks'], '<!-- wp:html'), 'Host/poster identities and presentation survive as layout shells without raw HTML.');
$assert('pass' === $result['source_reports']['wp_block_validity']['status'], 'Native media and layout shells serialize as valid editable blocks.');
$assert(!str_contains(implode("\n", array_column($result['assets'], 'content')), '#cover{opacity:1'), 'Closed-state normalization keeps the decorative poster hidden beside native autoplay playback.');
$artifact = array('entrypoint' => 'index.html', 'files' => array('index.html' => $html, 'clip.webm' => array('path' => 'clip.webm', 'kind' => 'video', 'mime_type' => 'video/webm', 'content_base64' => base64_encode("\x1a\x45\xdf\xa3")), 'cover.svg' => '<svg xmlns="http://www.w3.org/2000/svg" width="640" height="360"><path fill="red" d="M0 0h640v360H0z"/></svg>'));
$plan = (new ArtifactCompiler())->compile($artifact)->toWordPressSitePlanView()['wordpress_site_plan'];
$resolved = (new WordPressSitePlanResolver())->resolve($plan, array('theme_uri' => 'https://example.test/theme'));
$assert(str_contains($resolved['pages'][0]['resolved_block_markup'], 'https://example.test/theme/assets/clip.webm') && str_contains($resolved['pages'][0]['resolved_block_markup'], 'https://example.test/theme/assets/cover.svg') && array() === $plan['quality']['fallbacks'], 'Compiler/plan/resolver retain declared local video and poster assets without an unsupported fallback.');
foreach (array('onclick="playRemote()"', 'role="application"', 'purchase-key="unproven"', 'data-action="remote"') as $configuration) {
    $unsupported = (new HtmlTransformer())->transform(str_replace('id="ambient"', 'id="ambient" ' . $configuration, $html))->toArray();
    $assert(0 < count($unsupported['fallbacks']), 'Unknown application behavior stays unsupported: ' . $configuration);
}

echo "Native video with custom poster contract passed.\n";
