<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\WordPress\Runtime;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\ClassPublisher;

// Publishing renames engine classes in shipped block content. Every generated
// block in the published plan must still be exactly what its shipped save()
// produces, or the editor reports it invalid. This runs each block's own
// save() against the published content: a carousel, whose save() composes
// classes from attributes, and a copy-to-clipboard control, whose save() writes
// a fixed inner class.
$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};
$source = require dirname(__DIR__) . '/fixtures/gallery-stage-controls.php';
$compiled = (new ArtifactCompiler())->compile(array(
    'entrypoint' => 'index.html',
    'class_namespace' => 'catalog',
    'files' => array('index.html' => '<!doctype html><html><head><title>Gallery</title><style>' . $source['css'] . '</style></head><body><main>' . $source['html']
        . '<div class="code"><p>npm install blocks</p><button type="button" data-clipboard-text="npm install blocks">Copy</button></div></main></body></html>'),
));
$plan = $compiled->toWordPressSitePlanView()['wordpress_site_plan'];
$assert(isset($plan[ClassPublisher::MAP_KEY]), 'the public site-plan path published the plan');
$assert(!in_array(ClassPublisher::DIAGNOSTIC, array_column($plan['diagnostics'], 'code'), true), 'publishing did not fall back');

$definitions = array();
foreach ($compiled->toArray()['source_reports']['companion_plugin_payload']['blocks'] ?? array() as $definition) {
    $definitions[(string) $definition['block_json']['name']] = $definition;
}
$generated = array();
$visit = static function (array $blocks) use (&$visit, &$generated, $definitions): void {
    foreach ($blocks as $block) {
        $name = (string) ($block['blockName'] ?? '');
        if (isset($definitions[$name])) $generated[$name] ??= $block;
        $visit($block['innerBlocks'] ?? array());
    }
};
$runtime = new Runtime();
foreach (array('pages', 'templates', 'template_parts') as $group) foreach ($plan[$group] as $unit) $visit($runtime->parseBlocks($unit['canonical_block_markup'] ?? $unit['block_markup'] ?? ''));
foreach (array('authored-carousel', 'copy-to-clipboard') as $local) {
    $assert(array() !== array_filter(array_keys($generated), static fn (string $name): bool => str_ends_with($name, '/' . $local)), "the published plan contains a $local block");
}

// Execute the shipped save(), using a dependency-free element renderer for its
// HTML subset. InnerBlocks.Content supplies the already-published child markup.
$save = static function (array $definition, array $block) use ($runtime, $assert): string {
    $script = <<<'JS'
let settings;
const escape = value => String(value).replace(/&/g, '&amp;').replace(/"/g, '&quot;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
const RawHTML = props => props.children.join('');
const createElement = (type, props, ...children) => ({ type, props: { ...(props || {}), children }, children });
const InnerBlocks = { Content: () => childMarkup };
globalThis.window = { wp: { blocks: { registerBlockType: (_, value) => { settings = value; } }, blockEditor: { InnerBlocks }, element: { createElement, RawHTML, Fragment: 'Fragment' }, components: {} } };
const render = node => {
    if (node == null || node === false) return '';
    if (Array.isArray(node)) return node.map(render).join('');
    if (typeof node !== 'object') return escape(node);
    if (typeof node.type === 'function') return node.type(node.props);
    let attrs = '';
    for (let [name, value] of Object.entries(node.props)) {
        if (name === 'key' || name === 'children' || name === 'dangerouslySetInnerHTML' || value == null) continue;
        if (name === 'style') value = Object.entries(value).map(([key, val]) => key.replace(/[A-Z]/g, letter => '-' + letter.toLowerCase()) + ':' + val).join(';');
        if (value === '') continue;
        if (name === 'className') name = 'class';
        if (name === 'tabIndex') name = 'tabindex';
        attrs += ' ' + name + '="' + escape(value) + '"';
    }
    const open = '<' + node.type + attrs + '>';
    if (['img', 'input', 'br', 'hr'].includes(node.type)) return open;
    return open + (node.props.dangerouslySetInnerHTML?.__html ?? node.children.map(render).join('')) + '</' + node.type + '>';
};
JS;
    $script .= $definition['assets']['index.js'] . "\nconst attributes = " . json_encode((object) $block['attrs'], JSON_THROW_ON_ERROR) . ';';
    $script .= 'const childMarkup = ' . json_encode($runtime->serializeBlocks($block['innerBlocks']), JSON_THROW_ON_ERROR) . ';';
    $script .= 'for (const [name, schema] of Object.entries(settings.attributes)) if (!(name in attributes) && "default" in schema) attributes[name] = schema.default; process.stdout.write(render(settings.save({ attributes })));';
    $process = proc_open(array('node', '--input-type=module'), array(0 => array('pipe', 'r'), 1 => array('pipe', 'w'), 2 => array('pipe', 'w')), $pipes);
    $assert(is_resource($process), 'node can execute the companion save function');
    fwrite($pipes[0], $script);
    fclose($pipes[0]);
    $saved = (string) stream_get_contents($pipes[1]);
    $error = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $assert(0 === proc_close($process), 'save() executes: ' . $error);
    return $saved;
};
// Compare DOM trees: attribute order, entity spelling and insignificant HTML
// whitespace and class-token order are not block-validation differences.
$tree = static function (string $html): array {
    $document = new DOMDocument();
    @$document->loadHTML('<?xml encoding="UTF-8"><body>' . preg_replace('/<!--.*?-->/s', '', $html) . '</body>');
    $walk = static function (DOMNode $node) use (&$walk): mixed {
        if ($node instanceof DOMText) return '' === trim($node->textContent) ? null : $node->textContent;
        if (!$node instanceof DOMElement) return null;
        $attributes = array();
        foreach ($node->attributes as $attribute) {
            $value = trim($attribute->value);
            if ('style' === $attribute->name) $value = rtrim($value, ';');
            if ('class' === $attribute->name) {
                $tokens = preg_split('/\s+/', $value) ?: array();
                sort($tokens);
                $value = implode(' ', $tokens);
            }
            if ('' !== $value) $attributes[$attribute->name] = $value;
        }
        ksort($attributes);
        return array($node->tagName, $attributes, array_values(array_filter(array_map($walk, iterator_to_array($node->childNodes)), static fn (mixed $value): bool => null !== $value)));
    };
    return $walk($document->getElementsByTagName('body')->item(0));
};
foreach ($generated as $name => $block) {
    $saved = $save($definitions[$name], $block);
    $stored = $runtime->serializeBlocks(array($block));
    $assert($tree($stored) === $tree($saved), "published $name markup agrees with the shipped save() output\nstored: $stored\nsaved:  $saved");
}
echo 'PASS: published generated block save agreement (' . implode(', ', array_keys($generated)) . ")\n";
