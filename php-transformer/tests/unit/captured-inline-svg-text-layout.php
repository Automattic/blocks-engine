<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

// Captured author CSS is retired. A rich-text replacement for an observed
// flex box must still own that box, rather than becoming an inline carrier.
$source = '<html><body><main><div><span class="ret"><svg width="16" height="16" viewBox="0 0 24 24"><path d="M0 0H24V24Z"/></svg><small>Audiobook</small></span><span class="inline-token"><svg width="16" height="16" viewBox="0 0 24 24"><path d="M0 0H24V24Z"/></svg><small>Inline label</small></span></div></main></body></html>';
$dom = new DOMDocument();
$dom->loadHTML($source);
$template = json_decode(file_get_contents(__DIR__ . '/../fixtures/box-tree/index.json'), true)['nodes']['n7']['viewports']['desktop']['used_styles'];
$nodes = array();
foreach ($dom->getElementsByTagName('*') as $i => $element) {
    $element->setAttribute('data-blocks-engine-node-id', 'n' . $i);
}
foreach ($dom->getElementsByTagName('*') as $element) {
    $children = array();
    foreach ($element->childNodes as $child) {
        if ($child instanceof DOMElement) $children[] = $child->getAttribute('data-blocks-engine-node-id');
    }
    $display = 'span' === $element->tagName ? ('ret' === $element->getAttribute('class') ? 'flex' : 'inline') : 'block';
    $nodes[$element->getAttribute('data-blocks-engine-node-id')] = array('children' => $children, 'viewports' => array('desktop' => array(
        'border_box' => array('x' => 0, 'y' => 0, 'width' => 100, 'height' => 60),
        'used_styles' => array_replace($template, array('display' => $display, 'flex-direction' => 'column', 'width' => '100px', 'height' => '60px')),
    )));
}
$source = $dom->saveHTML();
$tree = array('schema' => 'blocks-engine/box-tree/v2', 'source_path' => 'index.html', 'source_hash' => hash('sha256', $source),
    'viewports' => array('desktop' => array('width' => 1440, 'height' => 900)), 'nodes' => $nodes);
$result = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array(
    'index.html' => $source, 'box-tree/index.json' => json_encode($tree),
)))->toArray();
$found = array();
$check = static function (array $blocks) use (&$check, &$found): void {
    foreach ($blocks as $block) {
        if ('core/paragraph' === ($block['blockName'] ?? '') && str_contains($block['attrs']['className'] ?? '', 'inline-token')) {
            if (str_contains($block['attrs']['className'], 'blocks-engine-source-box-paragraph')) {
                throw new RuntimeException('Inline SVG/text token incorrectly became a source layout box');
            }
            $found['inline'] = true;
        }
        if ('core/paragraph' === ($block['blockName'] ?? '') && str_contains($block['attrs']['className'] ?? '', 'ret')) {
            if (!str_contains($block['attrs']['className'], 'blocks-engine-source-box-paragraph')) {
                throw new RuntimeException('Captured SVG/text flex wrapper lost its source box identity');
            }
            $found['layout'] = true;
        }
        $check($block['innerBlocks'] ?? array());
    }
};
$check($result['blocks']);
if (count($found) !== 2) throw new RuntimeException('Expected native layout and inline paragraphs');
echo "Captured SVG/text layout: passed\n";
