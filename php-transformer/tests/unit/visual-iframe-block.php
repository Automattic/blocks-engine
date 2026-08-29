<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\CompanionPluginPayload;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Generators\VisualIframeBlockGenerator;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

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
$assert(str_contains($editor, "registerBlockType( 'ssi-example/visual-iframe'") && str_contains($editor, "createElement( 'iframe'") && ! str_contains($editor, 'RawHTML') && ! str_contains($editor, 'TextareaControl'), 'editor renders a bounded iframe preview instead of raw HTML editing');
$assert(array('wp-blocks', 'wp-block-editor', 'wp-element') === ($definition['script_dependencies']['index.js'] ?? null), 'visual iframe declares its editor dependencies');
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

echo "Visual iframe companion tests passed ({$assertions} assertions)\n";
