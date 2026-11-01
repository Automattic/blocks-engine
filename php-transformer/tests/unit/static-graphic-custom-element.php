<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Elements\CustomElementRuntimeDependency;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

$failures = array();
$passes = 0;
$assert = static function (bool $condition, string $message) use (&$failures, &$passes): void {
    if ( ! $condition ) {
        $failures[] = $message;
    } else {
        ++$passes;
    }
};
$compile = static fn (string $body): array => ( new ArtifactCompiler() )->compile(array(
    'entrypoint' => 'index.html',
    'files' => array('index.html' => '<!doctype html><html><head><title>Static graphics</title></head><body><main>' . $body . '</main></body></html>'),
))->toArray();
$codes = static fn (array $result): array => array_column($result['fallbacks'] ?? array(), 'diagnostic_code');
$svg = '<svg width="14" height="14" viewBox="0 0 14 14" fill="none"><path d="M1 1l12 12M13 1L1 13" stroke="currentColor"/></svg>';
$close = '<panel-dismiss class="panel-close">' . $svg . '</panel-dismiss>';
$neutral = $compile('<section><h2>First panel</h2>' . $close . '</section><section><h2>Second panel</h2>' . $close . '</section><icon-glyph>' . $svg . '</icon-glyph>');
$assert(! in_array('html_unsupported_element', $codes($neutral), true), 'three static graphic hosts have no unsupported-element findings');
$assert(array() === ($neutral['fallbacks'] ?? array()), 'neutral fixture has no fallbacks');
$assert(3 === substr_count((string) ($neutral['serialized_blocks'] ?? ''), '<!-- wp:image'), 'all three SVGs survive as image blocks');
$svgAssets = array_filter($neutral['assets'] ?? array(), static fn (array $asset): bool => str_contains((string) ($asset['content'] ?? ''), 'M1 1l12 12M13 1L1 13'));
$assert(array() !== $svgAssets, 'output asset retains the SVG artwork');
$assert(array() === ($neutral['source_reports']['runtime_islands'] ?? array()), 'static graphics do not become runtime islands');
$assert(array() === $neutral['source_reports']['compiled_site']['runtime_declarations'], 'static graphics declare no invented runtime');
$assert(! str_contains((string) ($neutral['serialized_blocks'] ?? ''), '<!-- wp:button'), 'static hosts do not infer a button');

$button = $compile('<button aria-label="Close">' . $svg . '</button>');
$assert(array() === ($button['fallbacks'] ?? array()) && str_contains((string) ($button['serialized_blocks'] ?? ''), 'Close'), 'native named icon button keeps its existing path');
// Captured graphics are rendered output, not evidence of a missing runtime.
// Shadow-DOM captures carry the rendered SVG's own inline sizing.
$styledSvg = str_replace('<svg ', '<svg style="width: 48px; height: 48px;" ', $svg);
$flex = 'style="display:flex;align-items:center"';
foreach ( array(
    'bare SVG' => array('svg', '', '', $svg),
    'span' => array('span', $flex, '', $svg),
    'bare host' => array('icon-graphic', '', '', $svg),
    'styled host' => array('icon-graphic', $flex, '', $svg),
    'host with styled SVG' => array('icon-graphic', $flex, '', $styledSvg),
    'configured host' => array('icon-graphic', 'data-src="icon.svg" data-color="#FFFFFF" ' . $flex, '', $svg),
    'named host' => array('icon-graphic', 'alt="Visit example" ' . $flex, 'Visit example', $svg),
    'empty configured host' => array('icon-graphic', 'data-src="icon.svg" data-color="#FFFFFF"', '', ''),
) as $name => [$tag, $attributes, $alt, $graphic] ) {
    $empty = '' === $graphic;
    $content = 'svg' === $tag ? $graphic : '<' . $tag . ' ' . $attributes . '>' . $graphic . '</' . $tag . '>';
    $result = $compile('<a href="https://example.com/">' . $content . '</a>');
    $blocks = (string) ($result['serialized_blocks'] ?? '');
    if ( $empty ) {
        $assert(in_array(CustomElementRuntimeDependency::UNPROVEN_CODE, $codes($result), true), $name . ' stays runtime unproven');
        $assert(! str_contains($blocks, '<!-- wp:image'), $name . ' does not invent an image');
        continue;
    }
    $assert(array() === ($result['fallbacks'] ?? array()), $name . ' has no fallback diagnostics');
    $assert(1 === substr_count($blocks, '<!-- wp:image'), $name . ' lowers to one core/image');
    $assert(str_contains($blocks, 'href="https://example.com/"'), $name . ' retains the destination');
    $assert(str_contains($blocks, 'alt="' . $alt . '"'), $name . ' maps image alt');
    $assets = array_filter($result['assets'] ?? array(), static fn (array $asset): bool => str_contains((string) ($asset['content'] ?? ''), 'M1 1l12 12M13 1L1 13'));
    $assert(array() !== $assets, $name . ' materializes the SVG artwork');
}
$configured = $compile('<icon-glyph data-target="panel"></icon-glyph>');
$assert(in_array(CustomElementRuntimeDependency::UNPROVEN_CODE, $codes($configured), true), 'empty configuration-bearing host stays unproven');
$assert(! str_contains((string) ($configured['serialized_blocks'] ?? ''), '<!-- wp:image'), 'unproven host is not silently lowered');
$image = $compile('<icon-glyph><img src="icon.png" alt="Icon"></icon-glyph>');
$assert(array() === ($image['fallbacks'] ?? array()) && str_contains((string) ($image['serialized_blocks'] ?? ''), 'icon.png'), 'image-only host keeps its existing media path');
// Direct HTML conversion retains defining-script evidence in the source DOM.
$proven = ( new HtmlTransformer() )->transform('<main><script>customElements.define("icon-glyph", class extends HTMLElement {});</script><icon-glyph>' . $svg . '</icon-glyph></main>')->toArray();
$assert(! in_array('html_unsupported_element', $codes($proven), true), 'proven host has no unsupported finding');
$assert(str_contains((string) ($proven['serialized_blocks'] ?? ''), 'icon-glyph'), 'proven runtime retains its custom element');
$assert(array() !== ($proven['source_reports']['runtime_islands'] ?? array()), 'proven host remains a runtime island');

foreach ( array('role="button"', 'aria-label="Close"', 'aria-labelledby="label"', 'onclick="act()"') as $attributes ) {
    $guarded = $compile('<icon-glyph ' . $attributes . '>' . $svg . '</icon-glyph>');
    $assert(in_array('html_unsupported_element', $codes($guarded), true), $attributes . ' prevents transparent lowering');
}
foreach ( array('<svg></svg>', '<svg><title>Label</title></svg>', '<span></span>', '') as $content ) {
    $empty = $compile('<icon-glyph>' . $content . '</icon-glyph>');
    $assert(in_array('html_unsupported_element', $codes($empty), true) || '' === ($empty['serialized_blocks'] ?? ''), 'non-graphic or empty content is not newly lowered');
}

if ( array() !== $failures ) {
    fwrite(STDERR, implode("\n", array_map(static fn (string $message): string => 'FAIL: ' . $message, $failures)) . "\n");
    exit(1);
}
echo "Static graphic custom element tests passed ({$passes} assertions)\n";
