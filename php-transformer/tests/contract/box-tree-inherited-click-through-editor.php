<?php
declare(strict_types=1);

require __DIR__ . '/../../vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\ThemeBootstrap;

// #5901: a Wix page wraps its content in a `pointer-events:none` container
// whose pinned layer is a fixed, viewport-sized grid that inherits `none`, so
// clicks pass through it to the page while its header takes them again. The
// front-end carrier prunes the inherited value; the editor resets every block
// wrapper to `pointer-events:auto`, so that overlay took every click meant for
// the page's heading. The overlay must be click-through in the editor too,
// without growing the front-end stylesheets.
$html = '<!doctype html><html data-blocks-engine-node-id="n1"><head data-blocks-engine-node-id="n2"><meta name="viewport" content="width=device-width,initial-scale=1"><style>'
    . 'body{margin:0}.page{pointer-events:none;position:relative}.pinned{position:fixed;top:0;left:0;width:100%;height:100%;display:grid;z-index:52}'
    . '.bar{pointer-events:auto;position:relative}.content{pointer-events:auto}'
    . '</style></head><body data-blocks-engine-node-id="n4"><div class="page" data-blocks-engine-node-id="p">'
    . '<div class="pinned" data-blocks-engine-node-id="o"><div class="bar" data-blocks-engine-node-id="b"><p data-blocks-engine-node-id="bt">Menu</p></div></div>'
    . '<main class="content" data-blocks-engine-node-id="m"><h1 data-blocks-engine-node-id="h">Welcome home</h1><p data-blocks-engine-node-id="t">Body text</p></main>'
    . '</div></body></html>';
$template = json_decode(file_get_contents(__DIR__ . '/../fixtures/box-tree/index.json'), true, 32, JSON_THROW_ON_ERROR)['nodes']['n7']['viewports']['desktop']['used_styles'];
foreach ($template as $property => $value) {
    if (str_starts_with($property, 'margin-') || str_starts_with($property, 'padding-')) $template[$property] = '0px';
}
$template['pointer-events'] = 'auto';
$widths = array('desktop' => 1440, 'mobile' => 402);
$node = static function (array $children, callable $observe) use ($template, $widths): array {
    $viewports = array();
    foreach ($widths as $key => $width) {
        [$box, $styles] = $observe($width);
        $viewports[$key] = array('border_box' => array_combine(array('x', 'y', 'width', 'height'), $box), 'used_styles' => array_replace($template, $styles));
    }
    return array('children' => $children, 'viewports' => $viewports);
};
$none = array('pointer-events' => 'none');
$nodes = array(
    'n1' => $node(array('n2', 'n4'), static fn(int $w): array => array(array(0, 0, $w, 900), array('width' => $w . 'px', 'height' => '900px'))),
    'n2' => $node(array(), static fn(int $w): array => array(array(0, 0, 0, 0), array('display' => 'none'))),
    'n4' => $node(array('p'), static fn(int $w): array => array(array(0, 0, $w, 900), array('width' => $w . 'px'))),
    'p' => $node(array('o', 'm'), static fn(int $w): array => array(array(0, 0, $w, 900), $none + array('width' => $w . 'px', 'position' => 'relative'))),
    'o' => $node(array('b'), static fn(int $w): array => array(array(0, 0, $w, 900), $none + array('width' => $w . 'px', 'height' => '900px', 'position' => 'fixed', 'top' => '0px', 'left' => '0px', 'display' => 'grid', 'z-index' => '52', 'grid-template-columns' => $w . 'px', 'grid-template-rows' => '900px'))),
    'b' => $node(array('bt'), static fn(int $w): array => array(array(0, 0, $w, 60), array('width' => $w . 'px', 'height' => '60px', 'position' => 'relative'))),
    'bt' => $node(array(), static fn(int $w): array => array(array(0, 0, $w, 20), array('width' => $w . 'px', 'height' => '20px'))),
    'm' => $node(array('h', 't'), static fn(int $w): array => array(array(0, 100, $w, 200), array('width' => $w . 'px'))),
    'h' => $node(array(), static fn(int $w): array => array(array(0, 100, $w, 40), array('width' => $w . 'px', 'height' => '40px', 'font-size' => '36px'))),
    't' => $node(array(), static fn(int $w): array => array(array(0, 140, $w, 20), array('width' => $w . 'px', 'height' => '20px'))),
);
$tree = array(
    'schema' => 'blocks-engine/box-tree/v2',
    'source_path' => 'index.html',
    'source_hash' => hash('sha256', $html),
    'viewports' => array('desktop' => array('width' => 1440, 'height' => 900), 'mobile' => array('width' => 402, 'height' => 844)),
    'nodes' => $nodes,
);
$assert = static function (bool $ok, string $message): void {
    if (!$ok) throw new RuntimeException($message);
    echo "PASS: $message\n";
};

$compiled = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array('index.html' => $html, 'box-tree/index.json' => json_encode($tree))));
$result = $compiled->toArray();
$assert(!in_array('box_tree_styles_invalid', array_column($result['diagnostics'], 'code'), true), 'The box-tree sidecar is accepted.');
$plan = $compiled->toWordPressSitePlanView()['wordpress_site_plan'];

$dom = new DOMDocument();
@$dom->loadHTML('<!doctype html><html><body>' . $result['serialized_blocks'] . '</body></html>');
$xpath = new DOMXPath($dom);
$classOf = static fn(string $token): string => "contains(concat(' ', normalize-space(@class), ' '), ' $token ')";
$overlay = $xpath->query('//*[' . $classOf('pinned') . ']')->item(0);
$bar = $xpath->query('//*[' . $classOf('bar') . ']')->item(0);
$content = $xpath->query('//*[' . $classOf('content') . ']')->item(0);
$assert($overlay instanceof DOMElement && $bar instanceof DOMElement && $content instanceof DOMElement, 'The overlay, its bar and the content survive as blocks.');

// The editor-only rule that reaches a block marked with each class.
$editorCss = '';
foreach ($plan['assets'] as $asset) {
    if (str_contains((string) ($asset['target_path'] ?? ''), 'editor-static-state')) $editorCss .= (string) ($asset['content'] ?? '') . "\n";
}
$frontCss = '';
$planAssets = array_column($plan['assets'], null, 'target_path');
foreach (ThemeBootstrap::documentStylesheets($plan, 'index.html') as $row) {
    $asset = $planAssets[$row['target_path']] ?? array();
    if ('editor' !== ($asset['stylesheet_target'] ?? 'both')) $frontCss .= ($asset['content'] ?? '') . "\n";
}
$editorPointerEvents = static function (DOMElement $element) use ($editorCss): ?string {
    $value = null;
    foreach (preg_split('/\s+/', $element->getAttribute('class')) as $token) {
        if ('' !== $token && preg_match('/:root \.editor-styles-wrapper \.' . preg_quote($token, '/') . '\{pointer-events:(\w+)\}/', $editorCss, $m)) $value = $m[1];
    }
    return $value;
};
$assert('none' === $editorPointerEvents($overlay), 'The editor keeps the overlay that inherits pointer-events:none click-through.');
$assert(null === $editorPointerEvents($bar) && null === $editorPointerEvents($content), 'Blocks that take pointer events keep the editor default.');
$assert('' !== $frontCss && !str_contains($frontCss, 'blocks-engine-click-through'), 'The front end keeps inheriting the value: no front-end rule restates it.');
