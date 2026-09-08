<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\CompanionPluginPayload;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Generators\VisualIframeBlockGenerator;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;
use Automattic\BlocksEngine\PhpTransformer\WordPress\Runtime;

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    ++$assertions;
    if ( ! $condition ) {
        throw new RuntimeException($message);
    }
};

$generator = new VisualIframeBlockGenerator();
$definition = $generator->definition('ssi-example');
$attributes = $definition['block_json']['attributes'] ?? array();
$assert('ssi-example/visual-iframe' === ($definition['block_json']['name'] ?? null), 'visual iframe has a namespaced companion block type');
$assert(false === ($definition['block_json']['supports']['html'] ?? null), 'visual iframe disables raw HTML editing');
$assert(array('src', 'title', 'width', 'height', 'className', 'allow', 'loading', 'sandbox', 'referrerPolicy', 'allowFullScreen') === array_keys($attributes), 'visual iframe has a bounded structured attribute schema');
$assert('boolean' === ($attributes['allowFullScreen']['type'] ?? null), 'visual iframe fullscreen permission is typed');
$editor = (string) ($definition['assets']['index.js'] ?? '');
$assert(str_contains($editor, "registerBlockType( 'ssi-example/visual-iframe'") && str_contains($editor, 'InspectorControls') && str_contains($editor, 'TextControl') && str_contains($editor, 'SelectControl') && str_contains($editor, 'ToggleControl') && str_contains($editor, "createElement( 'iframe'") && ! str_contains($editor, 'RawHTML') && ! str_contains($editor, 'TextareaControl'), 'editor provides structured iframe controls and a bounded preview instead of raw HTML editing');
$assert(str_contains($editor, "label: 'URL'") && str_contains($editor, "label: 'Title'") && str_contains($editor, "label: 'Width'") && str_contains($editor, "label: 'Height'") && str_contains($editor, "label: 'Allow permissions'") && str_contains($editor, "label: 'Loading'") && str_contains($editor, "label: 'Sandbox'") && str_contains($editor, "label: 'Referrer policy'") && str_contains($editor, "label: 'Allow fullscreen'"), 'editor exposes controls for every bounded iframe attribute');
$assert(str_contains($editor, 'isSafeVisualIframeUrl') && str_contains($editor, "url.protocol === 'https:'") && str_contains($editor, '! url.username') && str_contains($editor, '! url.password'), 'editor URL changes retain the bounded HTTPS destination admission policy');
$assert(str_contains($editor, "blockEditor.useBlockProps( { style: { position: 'relative' } } )") && str_contains($editor, "'aria-hidden': true") && str_contains($editor, "inset: 0") && str_contains($editor, "createElement( 'iframe', iframeProps( props.attributes ) )") && str_contains($editor, "function save( props ) { return createElement( 'iframe', iframeProps( props.attributes ) ); }") , 'editor keeps Gutenberg selection props on an editor-only wrapper and overlays the initial iframe interaction without changing saved markup');
$assert(str_contains($editor, "var props = {};") && str_contains($editor, "if ( attributes.className ) { props.className = attributes.className; }") && ! str_contains($editor, 'editor ? blockEditor.useBlockProps()'), 'authored iframe classes stay on the iframe and never replace Gutenberg wrapper classes');
$assert(array('wp-blocks', 'wp-block-editor', 'wp-components', 'wp-element') === ($definition['script_dependencies']['index.js'] ?? null), 'visual iframe declares its editor dependencies');
$editorSchemaRunner = <<<'JS'
const vm = require( 'node:vm' );
let settings;
vm.runInNewContext( Buffer.from( process.argv[ 1 ], 'base64' ).toString(), {
    window: { wp: {
        blocks: { registerBlockType: ( name, blockSettings ) => { settings = blockSettings; } },
        blockEditor: {}, components: {}, element: {}
    } }
} );
process.stdout.write( JSON.stringify( settings.attributes ) );
JS;
$editorAttributes = json_decode((string) shell_exec('node -e ' . escapeshellarg($editorSchemaRunner) . ' ' . escapeshellarg(base64_encode($editor))), true);
$assert($attributes === $editorAttributes, 'editor registration attribute schema exactly matches generated block metadata');
$payload = (new CompanionPluginPayload())->fromBlockTypes(array(), array(), array(), array($definition));
$assert('ssi-example/visual-iframe' === ($payload['blocks'][0]['block_json']['name'] ?? null), 'visual iframe companion definition survives payload packaging');

$source = '<main><iframe class="map" title="Map" src="https://example.test/map" width="1280" height="350" allow="fullscreen" loading="lazy" sandbox="allow-scripts" referrerpolicy="no-referrer" allowfullscreen></iframe></main>';
$result = (new HtmlTransformer())->transform($source)->toArray();
$block = $result['blocks'][0] ?? array();
$markup = (string) ($result['serialized_blocks'] ?? '');
$assert('custom/visual-iframe' === ($block['blockName'] ?? null), 'bounded HTTPS iframe uses the direct-transform companion block');
$assert('https://example.test/map' === ($block['attrs']['src'] ?? null) && '1280' === ($block['attrs']['width'] ?? null) && '350' === ($block['attrs']['height'] ?? null), 'companion reference retains source URL and dimensions structurally');
$assert('Map' === ($block['attrs']['title'] ?? null) && true === ($block['attrs']['allowFullScreen'] ?? null), 'companion reference retains accessibility and fullscreen metadata structurally');
$assert(! str_contains($markup, '<!-- wp:html') && str_contains($markup, '<iframe class="map" src="https://example.test/map" title="Map" width="1280" height="350"'), 'companion save markup is static and contains no core HTML fallback');
$assert(array() === ($result['fallbacks'] ?? array()) && 'pass' === ($result['source_reports']['wp_block_validity']['status'] ?? null), 'bounded companion iframe adds no fallback and has a valid Gutenberg save shape');

$repeated = (new HtmlTransformer())->transform($source . $source)->toArray();
$assert(1 === count($repeated['source_reports']['generated_blocks'] ?? array()), 'multiple visual iframe instances share one generated companion definition');

$customSource = '<main><vendor-iframe class="map-shell" data-src="https://example.test/map" style="width:1280px;height:350px;margin-bottom:24px"></vendor-iframe></main>';
$custom = (new HtmlTransformer())->transform($customSource)->toArray();
$customHost = $custom['blocks'][0] ?? array();
$customBlock = $customHost['innerBlocks'][0] ?? array();
$customMarkup = (string) ($custom['serialized_blocks'] ?? '');
$assert('core/group' === ($customHost['blockName'] ?? null) && 'custom/visual-iframe' === ($customBlock['blockName'] ?? null), 'custom iframe host becomes an editable geometry carrier around typed media');
$assert('https://example.test/map' === ($customBlock['attrs']['src'] ?? null) && '1280px' === ($customBlock['attrs']['width'] ?? null) && '350px' === ($customBlock['attrs']['height'] ?? null), 'custom iframe attributes and finite host geometry materialize structurally');
$assert(str_contains($customMarkup, 'margin-bottom:24px') && ! str_contains($customMarkup, '<!-- wp:html'), 'custom iframe host geometry survives without raw HTML fallback');
$customIslands = array_values(array_filter($custom['source_reports']['runtime_islands'] ?? array(), static fn (array $island): bool => 'iframe' === ($island['kind'] ?? '')));
$assert(1 === count($customIslands) && 'typed_visual_iframe_companion' === ($customIslands[0]['preservation_strategy'] ?? null), 'custom iframe retains explicit third-party runtime semantics');
$assert(array() === ($custom['fallbacks'] ?? array()) && 'pass' === ($custom['source_reports']['wp_block_validity']['status'] ?? null), 'custom iframe output is fallback-free and Gutenberg-valid');

$descendant = (new HtmlTransformer())->transform('<main><vendor-iframe><div class="map-frame" style="width:720px;height:405px"><iframe title="Map" src="https://example.test/map" width="100%" height="100%"></iframe></div></vendor-iframe></main>')->toArray();
$descendantFrame = $descendant['blocks'][0]['innerBlocks'][0] ?? array();
$descendantMedia = $descendantFrame['innerBlocks'][0] ?? array();
$descendantCss = implode("\n", array_column($descendant['assets'] ?? array(), 'content'));
$assert('core/group' === ($descendantFrame['blockName'] ?? null) && 'custom/visual-iframe' === ($descendantMedia['blockName'] ?? null), 'custom iframe descendant wrappers retain their editable DOM geometry carrier');
$assert('100%' === ($descendantMedia['attrs']['width'] ?? null) && '100%' === ($descendantMedia['attrs']['height'] ?? null) && str_contains($descendantCss, 'width:720px') && str_contains($descendantCss, 'height:405px'), 'relative iframe geometry remains bounded by its materialized wrapper');

$portableProvider = (new HtmlTransformer())->transform('<main><media-iframe data-url="https://www.youtube.com/embed/dQw4w9WgXcQ" width="560" height="315"></media-iframe></main>')->toArray();
$provider = $portableProvider['blocks'][0]['innerBlocks'][0] ?? array();
$assert('core/embed' === ($provider['blockName'] ?? null) && 'youtube' === ($provider['attrs']['providerNameSlug'] ?? null), 'portable custom iframe intent prefers a native provider block');

$placeholderHost = (new HtmlTransformer())->transform('<main><vendor-iframe data-src="https://example.test/map" title="Map" width="1280" height="350"><div class="map-container"></div></vendor-iframe></main>')->toArray();
$placeholderMedia = $placeholderHost['blocks'][0]['innerBlocks'][0] ?? $placeholderHost['blocks'][0] ?? array();
$assert('custom/visual-iframe' === ($placeholderMedia['blockName'] ?? null) && 'https://example.test/map' === ($placeholderMedia['attrs']['src'] ?? null), 'empty structural descendants do not block custom iframe materialization');
$assert(array() === ($placeholderHost['fallbacks'] ?? array()) && ! str_contains((string) ($placeholderHost['serialized_blocks'] ?? ''), '<!-- wp:html'), 'placeholder-wrapped custom iframe does not emit raw HTML');

$jsonHost = (new HtmlTransformer())->transform('<main><media-iframe data-src="{&quot;embedUrl&quot;:&quot;https://example.test/map&quot;}" width="640" height="360"></media-iframe></main>')->toArray();
$jsonMedia = $jsonHost['blocks'][0]['innerBlocks'][0] ?? $jsonHost['blocks'][0] ?? array();
$assert('custom/visual-iframe' === ($jsonMedia['blockName'] ?? null) && 'https://example.test/map' === ($jsonMedia['attrs']['src'] ?? null), 'JSON capture declarations expose a portable iframe destination');

$serialized = (new Runtime())->serializeBlocks(array( $customBlock ));
$reparsed = (new Runtime())->parseBlocks($serialized);
$assert('custom/visual-iframe' === ($reparsed[0]['blockName'] ?? null) && 'https://example.test/map' === ($reparsed[0]['attrs']['src'] ?? null), 'typed custom iframe companion survives save and reload');

$unsafe = (new HtmlTransformer())->transform('<main><vendor-iframe src="https://example.test/one" data-src="https://example.test/two" width="640" height="360"></vendor-iframe><vendor-iframe src="javascript:alert(1)" width="640" height="360"></vendor-iframe><vendor-iframe src="https://user:secret@example.test/map" width="640" height="360"></vendor-iframe><vendor-iframe data-widget-id="comp-runtime" width="640" height="360"></vendor-iframe></main>')->toArray();
$gapDiagnostics = array_values(array_filter($unsafe['fallbacks'] ?? array(), static fn (array $fallback): bool => 'html_iframe_surface_capability_gap' === ($fallback['diagnostic_code'] ?? null)));
$gapReasons = array_values(array_map(static fn (array $fallback): string => (string) ($fallback['reason'] ?? ''), $gapDiagnostics));
$assert(array( 'ambiguous_iframe_destination', 'unsafe_iframe_destination', 'credential_bound_iframe', 'source_runtime_only_iframe' ) === $gapReasons, 'unsafe custom iframe surfaces emit explicit capability-gap reasons');
$assert(array() === array_values(array_filter($unsafe['fallbacks'] ?? array(), static fn (array $fallback): bool => 'html_unsupported_element' === ($fallback['diagnostic_code'] ?? null))), 'classified custom iframe surfaces do not use unsupported-element fallbacks');
$assert(! str_contains((string) ($unsafe['serialized_blocks'] ?? ''), '<iframe') && ! str_contains((string) ($unsafe['serialized_blocks'] ?? ''), '<!-- wp:html') && ! str_contains((string) ($unsafe['serialized_blocks'] ?? ''), 'user:secret'), 'rejected custom iframe surfaces emit no raw HTML and no credentials');

echo "Visual iframe companion tests passed ({$assertions} assertions)\n";
