<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;
use Automattic\BlocksEngine\PhpTransformer\Contract\EditabilityReport;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Generators\CustomBlockGenerator;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Generators\ResponsiveLayoutBlockGenerator;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Generators\SvgArtworkBlockGenerator;

$source = '<figure><a href="/photo"><picture><source media="(min-width:800px)" srcset="wide.jpg 1200w"><img src="photo.jpg" alt="Photo"></picture></a><figcaption>Photo</figcaption></figure>';
$result = (new HtmlTransformer())->transform($source)->toArray();
$definitions = $result['source_reports']['generated_blocks'];
$media = array_values(array_filter($definitions, static fn(array $definition): bool => 'custom/responsive-media' === $definition['block_json']['name']));
if (1 !== count($media) || 'raw_html' === ($media[0]['block_json']['blocksEngine']['editingSurface'] ?? null)) {
    throw new RuntimeException('Linked responsive figures must expose media fields rather than a raw HTML editing surface.');
}
$editor = $media[0]['assets']['index.js'];
if (str_contains($editor, 'TextareaControl') || !str_contains($editor, 'components.TextControl') || !str_contains($editor, "field( node, 'alt', 'Alternative text'") || !str_contains($editor, "field( node, 'href', 'Link URL'") || !str_contains($editor, "field( node, 'src', 'Image URL'")) {
    throw new RuntimeException('The public transformer must supply structured media controls, not an HTML textarea.');
}
if ('<a href="/photo"><picture><source media="(min-width:800px)" srcset="wide.jpg 1200w"><img src="photo.jpg" alt="Photo"></picture></a>' !== ($result['blocks'][0]['attrs']['content'] ?? null)) {
    throw new RuntimeException('Structured editing must not rewrite captured media during import.');
}
// Execute the emitted editor through its registered public edit callback.
$runner = <<<'JS'
const assert = require('node:assert/strict');
const vm = require('node:vm');
let settings;
const nodes = [
    { localName: 'a', attrs: { href: '/photo' } },
    { localName: 'source', attrs: { media: '(min-width:800px)', srcset: 'wide.jpg 1200w' } },
    { localName: 'img', attrs: { src: 'photo.jpg', alt: 'Photo' } }
];
nodes.forEach(node => {
    node.getAttribute = name => node.attrs[name];
    node.hasAttribute = name => name in node.attrs;
    node.setAttribute = (name, value) => { node.attrs[name] = value; };
});
const body = { querySelectorAll: () => nodes, get innerHTML() { return JSON.stringify(nodes.map(node => node.attrs)); } };
const createElement = (type, props, ...children) => ({ type, props, children });
vm.runInNewContext(Buffer.from(process.argv[1], 'base64').toString(), { window: {
    DOMParser: function() { this.parseFromString = () => ({ body }); },
    wp: {
        blocks: { registerBlockType: (name, value) => { settings = value; } },
        blockEditor: { InspectorControls: 'inspector', useBlockProps: () => ({}) },
        components: { PanelBody: 'panel', TextControl: 'text' },
        element: { createElement, Fragment: 'fragment' }
    }
} });
let update;
const tree = settings.edit({ isSelected: true, attributes: { content: '<captured>' }, setAttributes: value => { update = value; } });
const fields = [];
function visit(node) {
    if (Array.isArray(node)) return node.forEach(visit);
    if (!node || typeof node !== 'object') return;
    if (node.type === 'text') fields.push(node);
    (node.children || []).forEach(visit);
}
visit(tree);
for (const [label, value, index, attribute] of [
    ['Alternative text 3', 'Edited photo', 2, 'alt'],
    ['Image URL 3', 'replacement.jpg', 2, 'src'],
    ['Link URL 1', '/edited', 0, 'href'],
    ['Source URL or candidates 2', 'new-wide.jpg 1200w', 1, 'srcset']
]) {
    const field = fields.find(field => field.props.label === label);
    assert.ok(field, label + ' is a structured control');
    field.props.onChange(value);
    assert.equal(JSON.parse(update.content)[index][attribute], value);
    assert.equal(nodes[1].attrs.media, '(min-width:800px)', 'editing retains source selection');
}
JS;
exec('node -e ' . escapeshellarg($runner) . ' ' . escapeshellarg(base64_encode($editor)) . ' 2>&1', $output, $status);
if (0 !== $status) throw new RuntimeException(implode("\n", $output));

$report = $result['source_reports']['editability_report'];
$signals = array_filter($report['signals'], static fn(array $signal): bool => 'raw_html_block' === $signal['kind'] && 'custom/responsive-media' === $signal['block_name']);
if (0 !== $report['metrics']['raw_html_block_count'] || array() !== $signals) {
    throw new RuntimeException('Field-editable media must not count as raw HTML.');
}
$plain = (new HtmlTransformer())->transform('<p>Editable copy.</p>')->toArray();
if (0 !== $plain['source_reports']['editability_report']['metrics']['raw_html_block_count']) {
    throw new RuntimeException('Plain paragraphs must not count as raw HTML.');
}

// Every audited raw-markup editor opts in, even when its name has no known suffix.
$metadata = array(
    (new CustomBlockGenerator())->blockJson('example/arbitrary', 'Custom'),
    (new ResponsiveLayoutBlockGenerator())->blockJson('example'),
    (new SvgArtworkBlockGenerator())->blockJson('example'),
);
foreach ($metadata as $json) {
    if ('raw_html' !== ($json['blocksEngine']['editingSurface'] ?? null)) throw new RuntimeException('Raw markup editors share the declaration.');
    $nested = array(array('blockName' => 'core/group', 'innerBlocks' => array(array('blockName' => $json['name']))));
    $declared = (new EditabilityReport())->fromBlocks($nested, generatedBlocks: array(array('block_json' => $json)));
    $undeclared = (new EditabilityReport())->fromBlocks($nested);
    if (1 !== $declared['metrics']['raw_html_block_count'] || 0 !== $undeclared['metrics']['raw_html_block_count']) throw new RuntimeException('Nested companion counting uses declarations, not name guesses.');
}

echo "Raw HTML companion editability tests passed.\n";
