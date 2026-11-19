<?php
declare(strict_types=1);

// The saved carousel root carries the source gallery's presentation classes,
// and source CSS styles the slides through them (a Squarespace slideshow
// paints its captions white with `.sqs-gallery-block-slideshow .meta p`).
// The editor drew the slides under a bare host without those classes, so the
// same captions rendered dark in the canvas and white on the frontend. Run
// the emitted edit() and check that the slides' inner blocks sit under every
// source presentation class, in a scope that generates no box of its own.

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

$assert = static function (bool $condition, string $message): void {
    if ( ! $condition ) {
        fwrite(STDERR, 'FAIL: ' . $message . PHP_EOL);
        exit(1);
    }
};

$source = require dirname(__DIR__) . '/fixtures/gallery-stage-controls.php';
$result = (new HtmlTransformer())->transform('<style>' . $source['css'] . '</style>' . $source['html'])->toArray();
$carousel = null;
$visit = static function (array $blocks) use (&$visit, &$carousel): void {
    foreach ($blocks as $block) {
        if (str_ends_with((string) ($block['blockName'] ?? ''), '/authored-carousel')) $carousel ??= $block;
        $visit($block['innerBlocks'] ?? array());
    }
};
$visit($result['blocks'] ?? array());
$assert(is_array($carousel), 'the fixture imports an authored carousel');
$classes = preg_split('/\s+/', trim((string) ($carousel['attrs']['sourcePresentationClasses'] ?? '')), -1, PREG_SPLIT_NO_EMPTY);
$assert(in_array('catalog-gallery', $classes, true), 'the carousel root keeps its source presentation class');

$definition = null;
foreach ($result['source_reports']['generated_blocks'] ?? array() as $generated) {
    if ('authored-carousel' === ($generated['name'] ?? null)) $definition = $generated;
}
$editorScript = (string) ($definition['assets']['index.js'] ?? '');
$assert('' !== $editorScript, 'the carousel ships its editor script');

$script = 'let settings;'
    . 'const createElement = (type, props, ...children) => ({ type, props: props || {}, children });'
    . 'const InnerBlocks = () => null; InnerBlocks.Content = () => null; InnerBlocks.ButtonBlockAppender = () => null;'
    . 'const InspectorControls = () => null;'
    . 'globalThis.window = { wp: { blocks: { registerBlockType: (name, value) => { settings = value; } }, blockEditor: { InnerBlocks, InspectorControls }, element: { createElement, Fragment: () => null }, components: {} } };'
    . $editorScript
    . ';const attributes = ' . json_encode((object) $carousel['attrs'], JSON_THROW_ON_ERROR) . ';'
    . 'for (const [name, schema] of Object.entries(settings.attributes || {})) if (!(name in attributes) && "default" in schema) attributes[name] = schema.default;'
    . 'const path = (node, chain) => { if (!node || "object" !== typeof node) return null; if (Array.isArray(node)) { for (const child of node) { const hit = path(child, chain); if (hit) return hit; } return null; }'
    . ' if (node.type === InnerBlocks) return chain; return path(node.children, node.type === InspectorControls ? chain : chain.concat([node.props])); };'
    . 'const chain = path(settings.edit({ attributes, setAttributes: () => {}, clientId: "x" }), []) || [];'
    . 'process.stdout.write(JSON.stringify(chain.map((props) => ({ className: String(props.className || ""), display: props.style?.display || "" }))));';

$process = proc_open(array('node', '--input-type=module'), array(0 => array('pipe', 'r'), 1 => array('pipe', 'w'), 2 => array('pipe', 'w')), $pipes);
$assert(is_resource($process), 'node is available to run the generated edit function');
fwrite($pipes[0], $script);
fclose($pipes[0]);
$output = (string) stream_get_contents($pipes[1]);
$error = (string) stream_get_contents($pipes[2]);
fclose($pipes[1]);
fclose($pipes[2]);
$assert(0 === proc_close($process), 'the generated edit function runs: ' . $error);

$chain = json_decode($output, true);
$assert(is_array($chain) && array() !== $chain, 'edit() renders the slides through InnerBlocks');
$ancestorClasses = array();
foreach ($chain as $props) {
    foreach (preg_split('/\s+/', $props['className'], -1, PREG_SPLIT_NO_EMPTY) as $class) $ancestorClasses[$class] = true;
}
foreach ($classes as $class) {
    $assert(isset($ancestorClasses[$class]), 'the editor slides sit under source presentation class ' . $class . ', as on the saved root');
}
foreach ($chain as $props) {
    if (array_intersect($classes, preg_split('/\s+/', $props['className'], -1, PREG_SPLIT_NO_EMPTY))) {
        $assert('contents' === $props['display'], 'the editor scope carrying the source classes generates no box (' . $props['className'] . ')');
    }
}
$assert(str_contains((string) ($definition['assets']['editor.css'] ?? ''), '.blocks-engine-authored-carousel-editor__source-scope{display:contents!important}'), 'the editor stylesheet keeps the source scope boxless against source display rules');

echo "Authored carousel editor source scope passed\n";
