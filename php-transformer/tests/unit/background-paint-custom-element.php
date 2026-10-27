<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Elements\CustomElementRuntimeDependency;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

// An empty autonomous custom element whose only output is its inline
// background converts like the equivalent div (#2613, class 1).
$failures = array();
$assert = static function (bool $condition, string $message) use (&$failures): void {
    if ( ! $condition ) {
        $failures[] = $message;
    }
};
$compile = static function (string $tag, string $attributes = '', string $content = '', string $style = 'background-image:url(tile.png);background-repeat:no-repeat', string $head = ''): array {
    $html = '<!doctype html><html><head><meta charset="utf-8"><title>Tiled overlay</title>'
        . '<style>.band{position:relative;padding:80px 24px}' . $tag . '{position:absolute;inset:0}</style>' . $head . '</head>'
        . '<body><section class="band"><' . $tag . ' ' . $attributes . ' style="' . $style . '">' . $content . '</' . $tag . '><h2>Answer every call</h2></section></body></html>';
    return ( new ArtifactCompiler() )->compile(array(
        'entrypoint' => 'index.html',
        'files' => array('index.html' => $html),
    ))->toArray();
};
$codes = static fn (array $result): array => array_column($result['fallbacks'] ?? array(), 'diagnostic_code');
$blocks = static fn (array $result): string => (string) ($result['serialized_blocks'] ?? '');
$css = static fn (array $result): string => (string) json_encode($result);

// Reduced reproduction: converts with no fallback, like the div control.
$tile = $compile('x-tile');
$div = $compile('div');
$assert(array() === ($tile['fallbacks'] ?? array()), 'background-only custom element has no fallbacks');
$assert(array() === ($div['fallbacks'] ?? array()), 'div control has no fallbacks');
$assert(str_contains($blocks($tile), '<!-- wp:image') && str_contains($blocks($tile), 'tile.png'), 'non-repeating background survives as a native image block');
$assert(str_contains($blocks($tile), '<!-- wp:group') && str_contains($blocks($tile), 'Answer every call'), 'surrounding section stays native');
$assert(1 === preg_match('/blocks-engine-source-x-tile-[a-f0-9]{12}-\d+/', $blocks($tile), $marker), 'type selector geometry has a source marker');
$assert(isset($marker[0]) && str_contains($css($tile), $marker[0] . '):not(be-t-0){position:absolute;inset:0}'), 'type selector geometry is projected');

// Repeating paint must remain a background, not one intrinsic-size image (#3552).
$repeating = $compile('x-tile', 'class="t"', '', 'background-image:url(tile.png);background-repeat:repeat', '<style>.t{position:absolute;inset:0}</style>');
$assert(array() === ($repeating['fallbacks'] ?? array()), 'repeating custom element has no fallbacks');
$assert(! str_contains($blocks($repeating), '<!-- wp:image'), 'repeating tile.png must not become a core/image');
preg_match_all('/<!-- wp:group (\{.*?\}) -->/', $blocks($repeating), $matches);
$layers = array();
foreach ( $matches[1] as $json ) {
    $attrs = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
    if ( 'tile.png' === ($attrs['style']['background']['backgroundImage']['url'] ?? null) ) {
        $layers[] = $attrs;
    }
}
$assert(1 === count($layers), 'exactly one core/group owns the repeating tile.png background');
$background = $layers[0]['style']['background'] ?? array();
$assert('repeat' === ($background['backgroundRepeat'] ?? null), 'custom element background keeps repeat');
$assert('auto' === ($background['backgroundSize'] ?? null), 'custom element background defaults to auto size');
$assert(str_contains($blocks($repeating), 'Answer every call'), 'repeating overlay keeps its sibling heading');

// The Wix shape: empty, data-only parameters of the already-painted background.
$wix = $compile('wix-bg-image', 'id="bgImgOverlay_1" class="overlay" data-tiled-image-info="{&quot;imageData&quot;:{}}" data-motion-part="BG_IMG comp-1"', '', 'background-image:url(tile.png); background-size:auto; background-repeat:repeat; background-position:center center;', '<script>window.wix = {};</script>');
$assert(array() === ($wix['fallbacks'] ?? array()), 'data-parameterized empty background host has no fallbacks');
$assert(str_contains($blocks($wix), 'tile.png') && str_contains($blocks($wix), 'bgImgOverlay_1'), 'Wix overlay background and identity survive');

$colour = $compile('x-tile', '', '', 'background-color:#123456');
$assert(array() === ($colour['fallbacks'] ?? array()), 'colour-only custom element has no fallbacks');

// Negative controls keep their existing fallback.
$text = $compile('x-tile', 'src="/tiles.json"', 'Tile');
$assert(! str_contains($blocks($text), 'tile.png'), 'text-bearing host is not lowered as background paint');
$configured = $compile('x-tile', 'src="/tiles.json"');
$assert(in_array(CustomElementRuntimeDependency::UNPROVEN_CODE, $codes($configured), true), 'configuration attribute stays unproven');
$assert(! str_contains($blocks($configured), 'tile.png'), 'configured host is not silently lowered');
$child = $compile('x-tile', 'data-target="panel"', '<span></span>');
$assert(in_array(CustomElementRuntimeDependency::UNPROVEN_CODE, $codes($child), true), 'data attributes on a non-empty host stay configuration');
foreach ( array('role="img"', 'aria-label="Tile"', 'onclick="go()"') as $attributes ) {
    $guarded = $compile('x-tile', $attributes);
    $assert(in_array('html_unsupported_element', $codes($guarded), true), $attributes . ' prevents transparent lowering');
}
$unpainted = $compile('x-tile', '', '', 'background:url(about:blank)');
$assert(! str_contains($blocks($unpainted), '<!-- wp:image') && ! str_contains($blocks($unpainted), 'about:blank'), 'unpainted empty host gains no invented block');
// Direct HTML conversion retains defining-script evidence in the source DOM.
$defined = ( new HtmlTransformer() )->transform('<main><script>customElements.define("x-tile", class extends HTMLElement {});</script><x-tile style="background-image:url(tile.png)"></x-tile></main>')->toArray();
$assert(! str_contains($blocks($defined), '<!-- wp:image') && str_contains($blocks($defined), 'x-tile'), 'proven defining script keeps the runtime-owned element');
$assert(array() !== ($defined['source_reports']['runtime_islands'] ?? array()), 'proven host remains a runtime island');

if ( array() !== $failures ) {
    fwrite(STDERR, implode("\n", array_map(static fn (string $message): string => 'FAIL: ' . $message, $failures)) . "\n");
    exit(1);
}
echo "Background paint custom element tests passed\n";
