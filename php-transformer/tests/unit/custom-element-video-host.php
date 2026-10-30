<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Elements\CustomElementRuntimeDependency;

// A textless autonomous custom element wrapping a captured native <video>
// (optionally with a poster <img>) already renders without a script, so its
// `data-*` attributes parameterize existing media instead of proving a
// missing runtime. Each host converts to the editable native video block,
// never to an unproven-runtime fallback or a raw-content custom block.
$failures = array();
$assert = static function (bool $condition, string $message) use (&$failures): void {
    if ( ! $condition ) {
        $failures[] = $message;
    }
};
$compile = static function (string $body): array {
    $html = '<!doctype html><html><head><meta charset="utf-8"><title>Captured video host</title></head><body>' . $body . '</body></html>';
    return ( new ArtifactCompiler() )->compile(array(
        'entrypoint' => 'index.html',
        'files' => array('index.html' => $html),
    ))->toArray();
};
$codes = static fn (array $result): array => array_column($result['fallbacks'] ?? array(), 'diagnostic_code');
$blocks = static fn (array $result): string => (string) ($result['serialized_blocks'] ?? '');
$flatten = static function (array $blocks) use (&$flatten): array {
    $out = array();
    foreach ($blocks as $block) { $out[] = $block; array_push($out, ...$flatten($block['innerBlocks'] ?? array())); }
    return $out;
};
$videoBlock = static function (array $result) use ($flatten): array {
    foreach ($flatten($result['blocks'] ?? array()) as $block) {
        if ( 'core/video' === $block['blockName'] ) {
            return $block;
        }
    }
    return array();
};
$opaqueCustomBlocks = static function (array $result): array {
    // The generated layout shell carries the host's wrapper identity; any
    // other generated custom block would freeze the media as raw content.
    preg_match_all('/<!-- wp:custom\/([a-z0-9-]+)/', (string) ($result['serialized_blocks'] ?? ''), $names);
    return array_values(array_diff(array_unique($names[1] ?? array()), array('layout-shell')));
};

// The reduced page from the issue, one host variant at a time. The poster
// `<img>` keeps its own editable image layer beside the video it decorates.
$reducedPage = static fn (string $attributes, string $media): string => '<section><div class="bg">'
    . '<x-video ' . $attributes . '>' . $media . '</x-video>'
    . '</div><h2>Hello</h2></section>';
$videoAndPoster = '<video src="clip.mp4" autoplay muted loop playsinline></video><img src="poster.png" alt="" draggable="false">';

foreach ( array(
    'data-video-info' => 'data-video-info="{&quot;autoPlay&quot;:true}"',
    'data-motion-part' => 'data-motion-part="stage"',
    'no attribute' => '',
) as $variant => $attributes ) {
    $result = $compile($reducedPage($attributes, $videoAndPoster));
    $assert(array() === ($result['fallbacks'] ?? array()), $variant . ' host has no fallbacks');
    $video = $videoBlock($result);
    $assert(array() !== $video, $variant . ' host keeps a native video block');
    $assert('clip.mp4' === ($video['attrs']['src'] ?? null), $variant . ' video keeps its captured source');
    foreach ( array('autoplay', 'muted', 'loop', 'playsInline') as $attribute ) {
        $assert(true === ($video['attrs'][$attribute] ?? null), $variant . ' video keeps ' . $attribute);
    }
    $assert(str_contains($blocks($result), '<!-- wp:image') && str_contains($blocks($result), 'poster.png'), $variant . ' poster stays an editable image layer');
    $assert(array() === $opaqueCustomBlocks($result), $variant . ' host is not an opaque custom block');
    $assert(! str_contains($blocks($result), '<!-- wp:html'), $variant . ' host carries no raw HTML fallback');
}

// A video-only host with a configuration attribute promotes to bare
// core/video with the same playback state.
$videoOnly = $compile($reducedPage('data-video-info="{&quot;autoPlay&quot;:true}"', '<video src="clip.mp4" autoplay muted loop playsinline></video>'));
$assert(array() === ($videoOnly['fallbacks'] ?? array()), 'video-only configured host has no fallbacks');
$video = $videoBlock($videoOnly);
foreach ( array('autoplay', 'muted', 'loop', 'playsInline') as $attribute ) {
    $assert(true === ($video['attrs'][$attribute] ?? null), 'video-only configured host keeps ' . $attribute);
}
$assert(array() === $opaqueCustomBlocks($videoOnly), 'video-only configured host is not an opaque custom block');

// A non-data configuration attribute is still meaningful: the media-only
// host keeps its explicit missing-functional-content finding.
$configured = $compile($reducedPage('src="/playlists.json"', $videoAndPoster));
$assert(in_array(CustomElementRuntimeDependency::UNPROVEN_CODE, $codes($configured), true), 'non-data configuration attribute stays unproven');

// Negative control: a config-attribute custom element with no renderable
// media and no proving script still reports missing functional content.
$empty = $compile('<section><div class="bg"><x-widget data-config="{&quot;size&quot;:3}"></x-widget></div><h2>Hello</h2></section>');
$assert(in_array(CustomElementRuntimeDependency::UNPROVEN_CODE, $codes($empty), true), 'configured element without media stays unproven');
$assert(! str_contains($blocks($empty), '<!-- wp:video') && ! str_contains($blocks($empty), '<!-- wp:html'), 'unproven host is not silently lowered');

if ( array() !== $failures ) {
    fwrite(STDERR, implode("\n", array_map(static fn (string $message): string => 'FAIL: ' . $message, $failures)) . "\n");
    exit(1);
}
echo "Custom element video host tests passed\n";
