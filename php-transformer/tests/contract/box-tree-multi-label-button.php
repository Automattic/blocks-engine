<?php
declare(strict_types=1);

require __DIR__ . '/../../vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\Contract\BoxTree;

// A flex logo has two text items, unlike an ordinary single-label CTA. Only
// the former needs an extra captured link context to keep its text boundaries.
$compile = static function (string $content): array {
    $html = '<!doctype html><html><head><title>Controls</title><style>.control{display:flex;gap:10px;align-items:center}</style></head><body><main><button class="control" type="button">' . $content . '</button></main></body></html>';
    $dom = new DOMDocument();
    @$dom->loadHTML($html);
    $i = 0;
    foreach ($dom->getElementsByTagName('*') as $element) $element->setAttribute('data-blocks-engine-node-id', 'n' . ++$i);
    $html = $dom->saveHTML();
    $styles = array_fill_keys(BoxTree::PROPERTIES, 'none');
    $styles = array_replace($styles, array('display' => 'block', 'position' => 'static', 'opacity' => '1', 'mix-blend-mode' => 'normal', 'color' => 'rgb(0, 0, 0)', 'background-color' => 'rgba(0, 0, 0, 0)', 'font-size' => '16px', 'font-family' => 'Arial', 'line-height' => '20px'));
    $nodes = array();
    foreach ($dom->getElementsByTagName('*') as $element) {
        if (in_array($element->tagName, array('head', 'title', 'style'), true)) continue;
        $children = array();
        foreach ($element->childNodes as $child) {
            if ($child instanceof DOMElement && 'head' !== $child->tagName) $children[] = $child->getAttribute('data-blocks-engine-node-id');
        }
        $used = $styles;
        if ('button' === $element->tagName) $used = array_replace($used, array('display' => 'flex', 'row-gap' => '10px', 'column-gap' => '10px', 'align-items' => 'center', 'flex-direction' => 'row'));
        $observation = array('border_box' => array('x' => 0, 'y' => 0, 'width' => 180, 'height' => 40), 'used_styles' => $used);
        $nodes[$element->getAttribute('data-blocks-engine-node-id')] = array('children' => $children, 'viewports' => array('desktop' => $observation));
    }
    $tree = array('schema' => BoxTree::SCHEMA, 'viewports' => array('desktop' => array('width' => 1440, 'height' => 900)), 'nodes' => $nodes, 'source_path' => 'index.html', 'source_hash' => hash('sha256', $html));
    return (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array('index.html' => $html, 'box-tree/index.json' => json_encode($tree))))->toArray();
};
foreach (array('<span class="mark">IMB</span><span class="label">Catering</span>' => true, 'Book Now' => false, '<span class="label">Book Now</span>' => false) as $content => $needsContext) {
    $result = $compile($content);
    if (!str_contains($result['serialized_blocks'], 'wp-block-button__link')) throw new RuntimeException('Expected native button for ' . $content);
    if (array_filter($result['diagnostics'], static fn(array $row): bool => str_starts_with($row['code'], 'box_tree_'))) throw new RuntimeException(json_encode($result['diagnostics']));
    $css = implode("\n", array_column($result['assets'], 'content'));
    $hasContext = 1 === preg_match('/\.be-inline-geometry-[a-f0-9]+\.be-inline-geometry-[a-f0-9]+>\.wp-block-button__link\{[^}]*display:flex/', $css);
    if ($hasContext !== $needsContext) throw new RuntimeException('Wrong captured link context for ' . $content . "\n" . $css);
}
echo "Multi-label controls retain their context without restyling single-label buttons.\n";
