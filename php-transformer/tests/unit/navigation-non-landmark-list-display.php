<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

// Folding a builder's block host into navigation must not make its flex
// list stack. Preserve the list display without adding a single-child group.
$source = '<!doctype html><html><head><style>'
    . '.social{display:block;width:300px;margin-bottom:40px}'
    . '.social ul{display:flex;gap:20px;margin:0;padding:0}'
    . '.social li{width:40px;height:40px;list-style:none}'
    . '</style></head><body><main><div class="social" role="navigation"><ul>'
    . '<li><a href="/a/">One</a></li>'
    . '<li><a href="/b/">Two</a></li>'
    . '<li><a href="/c/">Three</a></li>'
    . '</ul></div><p>Following content</p></main></body></html>';
// Ordinary authored flex lists already own their display through the cascade.
// Adding a second important projection restyles navigation outside the defect.
$authored = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array(
    'index.html' => $source,
)))->toArray();
foreach ($authored['assets'] ?? array() as $asset) {
    if ('css' === ($asset['kind'] ?? '')
        && preg_match('/wp-block-navigation__container[^{}]*\\{[^{}]*display:flex!important/', $asset['content'] ?? '')) {
        throw new RuntimeException('Authored list display must not gain an important override');
    }
}
// Supply observed boxes through the public artifact interface, where author
// styles are retired and the default block host used to swallow the flex list.
$dom = new DOMDocument();
$dom->loadHTML($source);
$template = json_decode((string) file_get_contents(__DIR__ . '/../fixtures/box-tree/index.json'), true)['nodes']['n7']['viewports']['desktop']['used_styles'];
$nodes = array();
foreach ($dom->getElementsByTagName('*') as $index => $element) {
    $element->setAttribute('data-blocks-engine-node-id', 'n' . $index);
}
foreach ($dom->getElementsByTagName('*') as $element) {
    $children = array();
    foreach ($element->childNodes as $child) {
        if ($child instanceof DOMElement) $children[] = $child->getAttribute('data-blocks-engine-node-id');
    }
    $display = 'ul' === $element->tagName ? 'flex' : ('li' === $element->tagName ? 'list-item' : 'block');
    $nodes[$element->getAttribute('data-blocks-engine-node-id')] = array('children' => $children, 'viewports' => array('desktop' => array(
        'border_box' => array('x' => 0, 'y' => 0, 'width' => 300, 'height' => 40),
        'used_styles' => array_replace($template, array('display' => $display, 'width' => '300px', 'height' => '40px')),
    )));
}
$source = $dom->saveHTML();
$tree = array('schema' => 'blocks-engine/box-tree/v2', 'source_path' => 'index.html', 'source_hash' => hash('sha256', $source),
    'viewports' => array('desktop' => array('width' => 768, 'height' => 900)), 'nodes' => $nodes);
$result = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array(
    'index.html' => $source, 'box-tree/index.json' => json_encode($tree),
)))->toArray();
$find = static function (array $blocks) use (&$find): ?array {
    foreach ($blocks as $block) {
        if ('core/navigation' === ($block['blockName'] ?? '')
            && in_array('social', explode(' ', $block['attrs']['className'] ?? ''), true)) {
            return $block;
        }
        $found = $find($block['innerBlocks'] ?? array());
        if (null !== $found) return $found;
    }
    return null;
};
$host = $find($result['blocks']);
if (null === $host) {
    throw new RuntimeException('The host must remain folded into native navigation');
}
$css = '';
foreach ($result['assets'] ?? array() as $asset) {
    if ('css' === ($asset['kind'] ?? '')) $css .= $asset['content'] ?? '';
}
if (!preg_match('/wp-block-navigation__container[^{}]*\\{[^{}]*display:flex!important/', $css)) {
    throw new RuntimeException('The native inner list must retain the observed flex display');
}
echo "Folded navigation retains the observed list display without an extra group\n";
