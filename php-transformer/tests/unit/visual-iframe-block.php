<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;
use Automattic\BlocksEngine\PhpTransformer\Tests\Support\IframeEmbedRendererFixture;
use Automattic\BlocksEngine\PhpTransformer\WordPress\Runtime;

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    ++$assertions;
    if ( ! $condition ) {
        throw new RuntimeException($message);
    }
};

$source = '<main><iframe class="map" title="Map" src="https://example.test/map" width="1280" height="350" allow="fullscreen" loading="lazy" sandbox="allow-scripts" referrerpolicy="no-referrer" allowfullscreen></iframe></main>';
$result = (new HtmlTransformer())->transform($source)->toArray();
$block = $result['blocks'][0] ?? array();
$markup = (string) ($result['serialized_blocks'] ?? '');
$frame = IframeEmbedRendererFixture::frame($block);
$assert(null !== $frame && array() === ($result['source_reports']['generated_blocks'] ?? array()), 'bounded HTTPS iframe is a core embed frame, not a generated block');
$assert('https://example.test/map' === ($block['attrs']['url'] ?? null) && '1280' === ($frame['width'] ?? null) && '350' === ($frame['height'] ?? null), 'the frame retains source URL and dimensions structurally');
$assert('Map' === ($frame['title'] ?? null) && true === ($frame['allowFullScreen'] ?? null), 'the frame retains accessibility and fullscreen metadata structurally');
$assert(! str_contains($markup, '<!-- wp:html') && str_contains(IframeEmbedRendererFixture::render($markup), '<iframe class="map" src="https://example.test/map" title="Map" width="1280" height="350" allow="fullscreen" loading="lazy" sandbox="allow-scripts" referrerpolicy="no-referrer" allowfullscreen="" style="max-width:100%"></iframe>'), 'the theme renders the source iframe and the block contains no core HTML fallback');
$assert(array() === ($result['fallbacks'] ?? array()) && 'pass' === ($result['source_reports']['wp_block_validity']['status'] ?? null), 'bounded iframe adds no fallback and has a valid Gutenberg save shape');

// #5011: a captured pre-load state must retain both the native iframe and its hiding CSS.
$hiddenSource = <<<'HTML'
<!doctype html><html><head><meta charset="utf-8"><title>Hidden iframe</title>
<style>.embed-hidden{display:none!important;opacity:0!important}</style></head>
<body><main><h1>Donation form</h1><div class="embed-form"><iframe class="embed-hidden" src="https://example.org/embed/form" width="352" height="150"></iframe></div><p>After the form.</p></main></body></html>
HTML;
$hidden = (new HtmlTransformer())->transform($hiddenSource)->toArray();
$hiddenMarkup = IframeEmbedRendererFixture::render((string) ($hidden['serialized_blocks'] ?? ''));
$hiddenCss = implode("\n", array_column($hidden['assets'] ?? array(), 'content'));
$assert(array() === ($hidden['fallbacks'] ?? array()) && ! str_contains($hiddenMarkup, '<!-- wp:html'), 'hidden sourced iframe imports without any fallback');
$assert(str_contains((string) ($hidden['serialized_blocks'] ?? ''), '"blocksEngineIframe"') && str_contains($hiddenMarkup, 'src="https://example.org/embed/form"') && str_contains($hiddenMarkup, 'width="352" height="150"'), 'hidden sourced iframe retains its native visual-iframe representation and dimensions');
$assert(1 === preg_match('/<iframe\\b[^>]*class="[^"]*\\bembed-hidden\\b/', $hiddenMarkup) && str_contains($hiddenCss, '.embed-hidden') && str_contains($hiddenCss, 'display:none!important') && str_contains($hiddenCss, 'opacity:0!important'), 'source class and hiding CSS survive on the saved frontend iframe');
$assert(str_contains($hiddenMarkup, 'Donation form') && str_contains($hiddenMarkup, 'After the form.') && 'pass' === ($hidden['source_reports']['wp_block_validity']['status'] ?? null), 'hidden iframe preserves surrounding content and a valid Gutenberg save shape');

$repeated = (new HtmlTransformer())->transform($source . $source)->toArray();
$assert(array() === ($repeated['source_reports']['generated_blocks'] ?? array()) && 2 === substr_count((string) ($repeated['serialized_blocks'] ?? ''), '"blocksEngineIframe"'), 'repeated iframes are each a core embed frame and generate no block type');

$customSource = '<main><vendor-iframe class="map-shell" data-src="https://example.test/map" style="width:1280px;height:350px;margin-bottom:24px"></vendor-iframe></main>';
$custom = (new HtmlTransformer())->transform($customSource)->toArray();
$customHost = $custom['blocks'][0] ?? array();
$customBlock = $customHost['innerBlocks'][0] ?? array();
$customMarkup = (string) ($custom['serialized_blocks'] ?? '');
$assert('core/group' === ($customHost['blockName'] ?? null) && null !== IframeEmbedRendererFixture::frame($customBlock), 'custom iframe host becomes an editable geometry carrier around a core embed frame');
$assert('https://example.test/map' === ($customBlock['attrs']['url'] ?? null) && empty(IframeEmbedRendererFixture::frame($customBlock)['width']) && empty(IframeEmbedRendererFixture::frame($customBlock)['height']), 'custom iframe attributes and finite host geometry materialize structurally');
$assert(str_contains($customMarkup, 'margin-bottom:24px') && ! str_contains($customMarkup, '<!-- wp:html'), 'custom iframe host geometry survives without raw HTML fallback');
$customIslands = array_values(array_filter($custom['source_reports']['runtime_islands'] ?? array(), static fn (array $island): bool => 'iframe' === ($island['kind'] ?? '')));
$assert(1 === count($customIslands) && 'typed_visual_iframe_companion' === ($customIslands[0]['preservation_strategy'] ?? null), 'custom iframe retains explicit third-party runtime semantics');
$assert(array() === ($custom['fallbacks'] ?? array()) && 'pass' === ($custom['source_reports']['wp_block_validity']['status'] ?? null), 'custom iframe output is fallback-free and Gutenberg-valid');

$descendant = (new HtmlTransformer())->transform('<main><vendor-iframe><div class="map-frame" style="width:720px;height:405px"><iframe title="Map" src="https://example.test/map" width="100%" height="100%"></iframe></div></vendor-iframe></main>')->toArray();
// The host and its geometry frame may fold into one layout shell that saves both wrappers.
$descendantFrame = 'custom/layout-shell' === ($descendant['blocks'][0]['blockName'] ?? null) ? $descendant['blocks'][0] : ($descendant['blocks'][0]['innerBlocks'][0] ?? array());
$descendantMedia = $descendantFrame['innerBlocks'][0] ?? array();
$descendantCss = implode("\n", array_column($descendant['assets'] ?? array(), 'content'));
$assert(in_array($descendantFrame['blockName'] ?? null, array('core/group', 'custom/layout-shell'), true) && null !== IframeEmbedRendererFixture::frame($descendantMedia), 'custom iframe descendant wrappers retain their DOM geometry carrier');
$assert('100%' === (IframeEmbedRendererFixture::frame($descendantMedia)['width'] ?? null) && '100%' === (IframeEmbedRendererFixture::frame($descendantMedia)['height'] ?? null) && str_contains($descendantCss, 'width:720px') && str_contains($descendantCss, 'height:405px'), 'relative iframe geometry remains bounded by its materialized wrapper');

$portableProvider = (new HtmlTransformer())->transform('<main><media-iframe data-url="https://www.youtube.com/embed/dQw4w9WgXcQ" width="560" height="315"></media-iframe></main>')->toArray();
$provider = $portableProvider['blocks'][0]['innerBlocks'][0] ?? array();
$assert('core/embed' === ($provider['blockName'] ?? null) && 'youtube' === ($provider['attrs']['providerNameSlug'] ?? null), 'portable custom iframe intent prefers a native provider block');

$placeholderHost = (new HtmlTransformer())->transform('<main><vendor-iframe data-src="https://example.test/map" title="Map" width="1280" height="350"><div class="map-container"></div></vendor-iframe></main>')->toArray();
$placeholderMedia = $placeholderHost['blocks'][0]['innerBlocks'][0] ?? $placeholderHost['blocks'][0] ?? array();
$assert(null !== IframeEmbedRendererFixture::frame($placeholderMedia) && 'https://example.test/map' === ($placeholderMedia['attrs']['url'] ?? null), 'empty structural descendants do not block custom iframe materialization');
$assert(array() === ($placeholderHost['fallbacks'] ?? array()) && ! str_contains((string) ($placeholderHost['serialized_blocks'] ?? ''), '<!-- wp:html'), 'placeholder-wrapped custom iframe does not emit raw HTML');

$jsonHost = (new HtmlTransformer())->transform('<main><media-iframe data-src="{&quot;embedUrl&quot;:&quot;https://example.test/map&quot;}" width="640" height="360"></media-iframe></main>')->toArray();
$jsonMedia = $jsonHost['blocks'][0]['innerBlocks'][0] ?? $jsonHost['blocks'][0] ?? array();
$assert(null !== IframeEmbedRendererFixture::frame($jsonMedia) && 'https://example.test/map' === ($jsonMedia['attrs']['url'] ?? null), 'JSON capture declarations expose a portable iframe destination');

$serialized = (new Runtime())->serializeBlocks(array( $customBlock ));
$reparsed = (new Runtime())->parseBlocks($serialized);
$assert(null !== IframeEmbedRendererFixture::frame($reparsed[0] ?? array()) && 'https://example.test/map' === ($reparsed[0]['attrs']['url'] ?? null), 'custom iframe core embed frame survives save and reload');

$unsafe = (new HtmlTransformer())->transform('<main><vendor-iframe src="https://example.test/one" data-src="https://example.test/two" width="640" height="360"></vendor-iframe><vendor-iframe src="javascript:alert(1)" width="640" height="360"></vendor-iframe><vendor-iframe src="https://user:secret@example.test/map" width="640" height="360"></vendor-iframe><vendor-iframe data-widget-id="comp-runtime" width="640" height="360"></vendor-iframe></main>')->toArray();
$gapDiagnostics = array_values(array_filter($unsafe['fallbacks'] ?? array(), static fn (array $fallback): bool => 'html_iframe_surface_capability_gap' === ($fallback['diagnostic_code'] ?? null)));
$gapReasons = array_values(array_map(static fn (array $fallback): string => (string) ($fallback['reason'] ?? ''), $gapDiagnostics));
$assert(array( 'ambiguous_iframe_destination', 'unsafe_iframe_destination', 'credential_bound_iframe', 'source_runtime_only_iframe' ) === $gapReasons, 'unsafe custom iframe surfaces emit explicit capability-gap reasons');
$assert(array() === array_values(array_filter($unsafe['fallbacks'] ?? array(), static fn (array $fallback): bool => 'html_unsupported_element' === ($fallback['diagnostic_code'] ?? null))), 'classified custom iframe surfaces do not use unsupported-element fallbacks');
$assert(! str_contains((string) ($unsafe['serialized_blocks'] ?? ''), '<iframe') && ! str_contains((string) ($unsafe['serialized_blocks'] ?? ''), '<!-- wp:html') && ! str_contains((string) ($unsafe['serialized_blocks'] ?? ''), 'user:secret'), 'rejected custom iframe surfaces emit no raw HTML and no credentials');

// A frame capture dropped (no rendered box) leaves an empty host carrying only capture provenance.
$emptyHost = (new HtmlTransformer())->transform('<main><p>Text</p><vendor-iframe data-src="" data-blocks-engine-node-id="n1"><div class="holder" style="" data-blocks-engine-capture-geometry-id="g1" data-blocks-engine-node-id="n2"></div></vendor-iframe></main>')->toArray();
$assert(array() === ($emptyHost['fallbacks'] ?? array()), 'an empty iframe host carrying only capture provenance is inert, not a capability gap');
$assert(! str_contains((string) ($emptyHost['serialized_blocks'] ?? ''), 'vendor-iframe') && ! str_contains((string) ($emptyHost['serialized_blocks'] ?? ''), '<!-- wp:html'), 'an inert empty iframe host emits no markup');

$fillStyle = '<style>.fill{width:100%;height:100%}.box{aspect-ratio:16/9;overflow:hidden}</style>';
$fillFrame = static fn (string $class): string => '<iframe class="' . $class . '" src="https://www.youtube-nocookie.com/embed/aBcDeFgHiJk" title="Demo" width="290" height="163"></iframe>';
$fill = (new HtmlTransformer())->transform($fillStyle . '<main><div class="box">' . $fillFrame('fill') . '</div></main>')->toArray();
$fillMarkup = (string) ($fill['serialized_blocks'] ?? '');
$assert(str_contains($fillMarkup, '"blocksEngineIframe"') && !str_contains($fillMarkup, 'wp-embed-aspect-'), 'a frame that fills its box keeps source CSS rather than a provider ratio approximation');
$assert(! str_contains($fillMarkup, 'be-inline-geometry-'), 'a frame that fills its box does not freeze the capture-time pixel height');
$fixedFrame = (new HtmlTransformer())->transform($fillStyle . '<main><div class="box">' . $fillFrame('') . '</div></main>')->toArray();
$assert(str_contains((string) ($fixedFrame['serialized_blocks'] ?? ''), 'be-inline-geometry-'), 'a frame with its own authored pixel size still carries that height');
$positioned = IframeEmbedRendererFixture::render((string) ((new HtmlTransformer())->transform('<style>.pinned{position:absolute}</style><main><div class="slot"><iframe class="pinned" src="https://forms.example.test/share" title="Signup" width="398" height="497"></iframe></div></main>')->toArray()['serialized_blocks'] ?? ''));
$assert(str_contains($positioned, 'width="398" height="497"'), 'a frame whose rules only position it keeps the source size attributes that size it');

echo "Visual iframe tests passed ({$assertions} assertions)\n";
