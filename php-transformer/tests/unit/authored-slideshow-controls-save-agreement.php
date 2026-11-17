<?php
declare(strict_types=1);

// An imported slideshow's controls host overlays the slides with a fixed
// inline style. The transformer writes that style into the post, and the
// generated block's registered save function rebuilds it when Gutenberg
// validates the block: when the two disagree on even one declaration, the
// editor opens the carousel as invalid content ("This block contains
// unexpected or invalid content"). Run the emitted save function and compare
// its controls host style with the style the transformer serialized.

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\CapturedDialogProjector;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

$assert = static function (bool $condition, string $message): void {
    if ( ! $condition ) {
        fwrite(STDERR, 'FAIL: ' . $message . PHP_EOL);
        exit(1);
    }
};

$files = require dirname(__DIR__) . '/fixtures/slideshow-components.php';
$projection = (new CapturedDialogProjector())->project($files);
$result = (new HtmlTransformer())->transform($projection['files'][0]['content'])->toArray();

$carousel = null;
foreach ($result['blocks'] ?? array() as $block) {
    if (str_ends_with((string) ($block['blockName'] ?? ''), '/authored-carousel')) $carousel = $block;
}
$assert(is_array($carousel), 'the fixture imports one authored carousel');
$assert('slideshow' === ($carousel['attrs']['presentation'] ?? null), 'the carousel is a slideshow, whose controls host overlays the slides');

$document = new DOMDocument();
@$document->loadHTML('<?xml encoding="utf-8"?>' . (string) ($result['serialized_blocks'] ?? ''));
$host = (new DOMXPath($document))->query('//div[contains(concat(" ", @class, " "), " blocks-engine-authored-carousel__controls ")]')->item(0);
$assert($host instanceof DOMElement && '' !== $host->getAttribute('style'), 'the serialized slideshow has a styled controls host');
$serializedStyle = $host->getAttribute('style');

$definition = null;
foreach ($result['source_reports']['generated_blocks'] ?? array() as $generated) {
    if ('authored-carousel' === ($generated['name'] ?? null)) $definition = $generated;
}
$editorScript = (string) ($definition['assets']['index.js'] ?? '');
$assert('' !== $editorScript, 'the carousel ships its editor script');

// Just enough of wp.* to run save(): the element tree is kept as data and
// its controls host style is written the way wp.element serializes style.
$script = 'let settings;'
    . 'const createElement = (type, props, ...children) => ({ type, props: props || {}, children });'
    . 'const InnerBlocks = () => null; InnerBlocks.Content = () => null;'
    . 'globalThis.window = { wp: { blocks: { registerBlockType: (name, value) => { settings = value; } }, blockEditor: { InnerBlocks, InspectorControls: () => null, useBlockProps: Object.assign(() => ({}), { save: (props) => props }) }, element: { createElement, Fragment: () => null }, components: {} } };'
    . $editorScript
    . ';const attributes = ' . json_encode((object) $carousel['attrs'], JSON_THROW_ON_ERROR) . ';'
    . 'for (const [name, schema] of Object.entries(settings.attributes || {})) if (!(name in attributes) && "default" in schema) attributes[name] = schema.default;'
    . 'const find = (node) => { if (!node || "object" !== typeof node) return null; if (Array.isArray(node)) { for (const child of node) { const hit = find(child); if (hit) return hit; } return null; }'
    . ' if (String(node.props?.className || "").split(" ").includes("blocks-engine-authored-carousel__controls")) return node; return find(node.children); };'
    . 'const unitless = new Set(["zIndex", "opacity", "flexGrow", "flexShrink", "order", "lineHeight", "fontWeight"]);'
    . 'const style = find(settings.save({ attributes, innerBlocks: [] }))?.props.style || {};'
    . 'process.stdout.write(Object.entries(style).map(([key, value]) => (key.startsWith("--") ? key : key.replace(/[A-Z]/g, (letter) => "-" + letter.toLowerCase())) + ":" + ("number" === typeof value && 0 !== value && !unitless.has(key) ? value + "px" : String(value))).join(";"));';

$process = proc_open(array('node', '--input-type=module'), array(0 => array('pipe', 'r'), 1 => array('pipe', 'w'), 2 => array('pipe', 'w')), $pipes);
$assert(is_resource($process), 'node is available to run the generated save function');
fwrite($pipes[0], $script);
fclose($pipes[0]);
$savedStyle = (string) stream_get_contents($pipes[1]);
$error = (string) stream_get_contents($pipes[2]);
fclose($pipes[1]);
fclose($pipes[2]);
$assert(0 === proc_close($process), 'the generated save function runs: ' . $error);

$assert($serializedStyle === $savedStyle, 'save() rebuilds the serialized slideshow controls host style, so Gutenberg validates the imported block (post: ' . $serializedStyle . '; save: ' . $savedStyle . ')');

echo "Authored slideshow controls save agreement passed\n";
