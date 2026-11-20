<?php
declare(strict_types=1);

require __DIR__ . '/../../vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

// Native runtime markup, unlike core's saved wrapper, consumes metadata classes.
$html = '<!doctype html><html data-blocks-engine-node-id="root"><head data-blocks-engine-node-id="head"></head><body data-blocks-engine-node-id="body"><main data-blocks-engine-node-id="main"><form data-blocks-engine-node-id="form"><div data-blocks-engine-node-id="field"><label for="guest" data-blocks-engine-node-id="label">Guest</label><input id="guest" data-blocks-engine-node-id="input" data-blocks-engine-capture-native-control-state="{&quot;version&quot;:1,&quot;kind&quot;:&quot;input&quot;,&quot;type&quot;:&quot;text&quot;,&quot;value&quot;:&quot;&quot;,&quot;defaultValue&quot;:&quot;&quot;,&quot;checked&quot;:false,&quot;defaultChecked&quot;:false,&quot;indeterminate&quot;:false}"><button data-blocks-engine-node-id="button">Send</button></div></form></main></body></html>';
$template = json_decode((string) file_get_contents(__DIR__ . '/../fixtures/box-tree/index.json'), true, 32, JSON_THROW_ON_ERROR)['nodes']['n7']['viewports']['desktop']['used_styles'];
$nodes = array();
foreach (array('root' => array('head', 'body'), 'head' => array(), 'body' => array('main'), 'main' => array('form'), 'form' => array('field'), 'field' => array('label', 'input', 'button'), 'label' => array(), 'input' => array(), 'button' => array()) as $id => $children) {
    $styles = array_replace($template, array('display' => 'block', 'width' => '400px', 'height' => '100px', 'font-family' => 'Arial, sans-serif'));
    if ('head' === $id) $styles['display'] = 'none';
    if ('label' === $id) $styles = array_replace($styles, array('display' => 'inline', 'font-size' => '14px', 'line-height' => '14px', 'color' => 'rgb(120, 30, 40)'));
    if ('input' === $id) $styles = array_replace($styles, array('height' => '36px', 'margin-top' => '6px', 'padding-left' => '12px', 'background-color' => 'rgb(240, 240, 240)'));
    $nodes[$id] = array('children' => $children, 'viewports' => array('desktop' => array('border_box' => array('x' => 0, 'y' => 0, 'width' => 400, 'height' => 100), 'used_styles' => $styles)));
}
$tree = array('schema' => 'blocks-engine/box-tree/v2', 'source_path' => 'index.html', 'source_hash' => hash('sha256', $html), 'viewports' => array('desktop' => array('width' => 1440, 'height' => 900)), 'nodes' => $nodes);
$result = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array('index.html' => $html, 'box-tree/index.json' => json_encode($tree))));
$find = static function (array $blocks) use (&$find): ?array {
    foreach ($blocks as $block) {
        if (isset($block['attrs']['metadata']['blocksEngineInput'])) return $block['attrs']['metadata']['blocksEngineInput'];
        if ($found = $find($block['innerBlocks'] ?? array())) return $found;
    }
    return null;
};
$attrs = $find($result->toArray()['blocks']);
if (null === $attrs) throw new RuntimeException('Missing native input metadata');
foreach (array('className', 'labelClassName') as $key) {
    if (!str_contains($attrs[$key] ?? '', 'be-inline-geometry-')) throw new RuntimeException('Native runtime must retain the observed box carrier in ' . $key . ': ' . json_encode($attrs));
}
echo "PASS: native input and label retain box-tree presentation\n";
