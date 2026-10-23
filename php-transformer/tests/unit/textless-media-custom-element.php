<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Elements\CustomElementRuntimeDependency;

$failures = array();
$assert = static function (bool $condition, string $message) use (&$failures): void {
    if ( ! $condition ) {
        $failures[] = $message;
    }
};
$compile = static function (string $body): array {
    $html = '<!doctype html><html><head><title>Textless media</title></head><body><main>' . $body . '</main></body></html>';
    return ( new ArtifactCompiler() )->compile(array(
        'entrypoint' => 'index.html',
        'files' => array('index.html' => $html),
    ))->toArray();
};
$codes = static fn (array $result): array => array_column($result['fallbacks'] ?? array(), 'diagnostic_code');
$slots = '<div class="banner__content"><p class="sub"></p><h3></h3><div class="rte"></div></div>';
$content = '<div class="banner__bg"><img src="/media/hero.jpg" alt="Hero"></div>' . $slots;
$host = static fn (string $content, string $attributes = ''): string => '<overlay-banner class="banner" style="--overlay-opacity:30%" ' . $attributes . '>' . $content . '</overlay-banner>';
$result = $compile($host($content));
$blocks = (string) ($result['serialized_blocks'] ?? '');
$assert(array() === ($result['fallbacks'] ?? array()), 'neutral fixture has no fallbacks');
$assert(str_contains($blocks, '<!-- wp:image') && str_contains($blocks, '/media/hero.jpg'), 'nested hero survives as an image block');
$assert(! str_contains($blocks, '<!-- wp:heading') && ! str_contains($blocks, '>Hero</'), 'empty text slots do not gain invented text');
$assert(array() === ($result['source_reports']['runtime_islands'] ?? array()), 'passive media does not become a runtime island');

foreach ( array(
    '<div><picture><source srcset="/media/wide.jpg"><img src="/media/small.jpg" alt=""></picture></div>' => '/media/small.jpg',
    '<div><video src="/media/clip.mp4" controls></video></div>' => '/media/clip.mp4',
    '<div><video controls><source src="/media/clip.mp4" type="video/mp4"></video></div>' => '/media/clip.mp4',
) as $media => $url ) {
    $result = $compile($host($media . $slots));
    $assert(! in_array('html_unsupported_element', $codes($result), true), 'sourced picture/video wrapper is transparent');
    $assert(str_contains((string) ($result['serialized_blocks'] ?? ''), $url), 'nested media source survives');
}
$configured = $compile($host($content, 'data-target="panel"'));
$assert(in_array(CustomElementRuntimeDependency::UNPROVEN_CODE, $codes($configured), true), 'configured media host stays unproven');
$assert(! str_contains((string) ($configured['serialized_blocks'] ?? ''), '<!-- wp:image'), 'configured host is not silently lowered');
foreach ( array('role="button"', 'aria-label="Banner"', 'aria-labelledby="label"') as $attributes ) {
    $guarded = $compile($host($content, $attributes));
    $assert(in_array('html_unsupported_element', $codes($guarded), true), $attributes . ' prevents transparent lowering');
}
foreach ( array('<div><img></div>', '<div><picture><source></picture></div>', '<div><video></video></div>', '<div><video><source></video></div>') as $empty ) {
    $result = $compile($host($empty . $slots));
    $assert(in_array('html_unsupported_element', $codes($result), true), 'sourceless media does not prove a transparent host');
}
// Keep the #3207 controls on their existing conversion paths.
$svg = '<svg width="14" height="14" viewBox="0 0 14 14"><path d="M1 1l12 12M13 1L1 13" stroke="currentColor"/></svg>';
$static = $compile('<section><h2>First panel</h2><panel-dismiss>' . $svg . '</panel-dismiss></section><section><h2>Second panel</h2><panel-dismiss>' . $svg . '</panel-dismiss></section><icon-glyph>' . $svg . '</icon-glyph>');
$assert(array() === ($static['fallbacks'] ?? array()), 'static SVG hosts retain no-fallback conversion');
$assert(3 === substr_count((string) ($static['serialized_blocks'] ?? ''), '<!-- wp:image'), 'all three static SVGs survive');
$button = $compile('<button aria-label="Close">' . $svg . '</button>');
$assert(array() === ($button['fallbacks'] ?? array()) && str_contains((string) ($button['serialized_blocks'] ?? ''), 'Close'), 'native named icon button keeps its existing path');
$configuredSvg = $compile('<icon-glyph data-target="panel">' . $svg . '</icon-glyph>');
$assert(in_array(CustomElementRuntimeDependency::UNPROVEN_CODE, $codes($configuredSvg), true), 'configured SVG host stays unproven');
$image = $compile('<icon-glyph><img src="/media/icon.png" alt="Icon"></icon-glyph>');
$assert(array() === ($image['fallbacks'] ?? array()) && str_contains((string) ($image['serialized_blocks'] ?? ''), '/media/icon.png'), 'direct image host retains existing conversion');

if ( array() !== $failures ) {
    fwrite(STDERR, implode("\n", array_map(static fn (string $message): string => 'FAIL: ' . $message, $failures)) . "\n");
    exit(1);
}
echo "Textless media custom element tests passed\n";
