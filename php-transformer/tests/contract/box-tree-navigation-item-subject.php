<?php
declare(strict_types=1);

require __DIR__ . '/../../vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\VisualParity\StaticCssCascade;
use Automattic\BlocksEngine\PhpTransformer\VisualParity\StaticStyleParityRunner;

// A link has a block box, but its owning list item is a flex row with an
// empty divider. The divider still consumes a gap even when it paints nothing.
$base = json_decode((string) file_get_contents(__DIR__ . '/../fixtures/box-tree/index.json'), true, 32, JSON_THROW_ON_ERROR);
$html = '<!doctype html><html data-blocks-engine-node-id="html"><head><title>Menu</title><style>ul{display:flex;justify-content:end}li{display:flex;gap:7px}a{display:block}</style></head><body data-blocks-engine-node-id="body"><header data-blocks-engine-node-id="header"><nav data-blocks-engine-node-id="nav"><ul data-blocks-engine-node-id="list">';
$layout = array('html' => array('body'), 'body' => array('header'), 'header' => array('nav'), 'nav' => array('list'), 'list' => array());
foreach (array('Home', 'About', 'Contact') as $i => $label) {
    $html .= '<li class="menu-item" data-blocks-engine-node-id="item' . $i . '"><div class="box" data-blocks-engine-node-id="box' . $i . '"><a href="/' . strtolower($label) . '/" data-blocks-engine-node-id="link' . $i . '"><span data-blocks-engine-node-id="label' . $i . '">' . $label . '</span></a></div><span class="divider" data-blocks-engine-node-id="divider' . $i . '"></span></li>';
    $layout['list'][] = 'item' . $i;
    $layout['item' . $i] = array('box' . $i, 'divider' . $i);
    $layout['box' . $i] = array('link' . $i);
    $layout['link' . $i] = array('label' . $i);
    $layout['label' . $i] = $layout['divider' . $i] = array();
}
$html .= '</ul></nav></header><main><p>Page content</p></main></body></html>';
$tree = $base;
$tree['source_hash'] = hash('sha256', $html);
$tree['nodes'] = array();
foreach ($layout as $id => $children) {
    $node = array('children' => $children, 'viewports' => array());
    foreach ($base['nodes']['n1']['viewports'] as $viewport => $observation) {
        $item = str_starts_with($id, 'item');
        $observation['used_styles'] = array_replace($observation['used_styles'], array(
            'display' => $item || 'list' === $id ? 'flex' : 'block',
            'position' => 'static', 'width' => $item ? '107px' : '100px', 'height' => '24px',
            'row-gap' => $item ? '7px' : 'normal', 'column-gap' => $item ? '7px' : 'normal',
            'flex-direction' => 'row', 'flex-wrap' => 'nowrap', 'align-items' => 'center',
            'justify-content' => 'list' === $id ? 'end' : 'normal',
        ));
        $observation['border_box'] = array('x' => 0, 'y' => 0, 'width' => $item ? 107 : 100, 'height' => 24);
        $node['viewports'][$viewport] = $observation;
    }
    $tree['nodes'][$id] = $node;
}
$result = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array('index.html' => $html, 'box-tree/index.json' => json_encode($tree))))->toArray();
$css = implode("\n", array_column($result['assets'] ?? array(), 'content'));
$markup = (string) $result['serialized_blocks'];
if (!preg_match_all('/<!-- wp:navigation-link (.*?) \/-->/s', $markup, $matches) || 3 !== count($matches[1])) throw new RuntimeException('Expected three native navigation links');
foreach ($matches[1] as $json) {
    $attrs = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
    if (empty($attrs['metadata']['blocksEngineNavigationAnchor']['boxes'])) throw new RuntimeException('Source anchor boxes must survive');
    $found = false;
    foreach (explode(' ', $attrs['className'] ?? '') as $class) {
        if (!str_starts_with($class, 'be-inline-geometry-')) continue;
        if (preg_match('/\.' . preg_quote($class, '/') . '\.' . preg_quote($class, '/') . '\{([^}]+)\}/', $css, $rule)
            && str_contains($rule[1], 'display:flex') && str_contains($rule[1], 'gap:7px')) $found = true;
    }
    if (!$found) throw new RuntimeException('Navigation LI must carry its source flex row and divider gap, not the anchor block box');
}
// Include the engine's later navigation baseline in the resolved cascade:
// a carrier and that baseline have equal specificity, so order alone must
// not turn an observed flex row back into a list-item box.
$dom = new DOMDocument();
$previous = libxml_use_internal_errors(true);
$dom->loadHTML(StaticStyleParityRunner::candidateHtmlFromSerializedBlocks($markup));
libxml_clear_errors();
libxml_use_internal_errors($previous);
$cascade = new StaticCssCascade($dom, $css);
$count = 0;
foreach ($dom->getElementsByTagName('li') as $li) {
    if (!str_contains($li->getAttribute('class'), 'menu-item')) continue;
    $style = $cascade->resolve($li, array('display'), array());
    if ('flex' !== ($style['display'] ?? '')) throw new RuntimeException('Navigation baseline must not override observed item display');
    ++$count;
}
if (3 !== $count) throw new RuntimeException('Expected three rendered source items');
echo "PASS: navigation items retain their own observed flex row and gap\n";
