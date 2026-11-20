<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

// A source control sized by the box tree (an avatar login button clipped to
// its measured width) is wrapped in a synthesized, layout-neutral
// core/buttons. That wrapper is flattened to display:contents, so it cannot
// carry the control's outer box; the retained .wp-block-button must keep it
// instead of being flattened in turn, which would let the link grow to its
// unclipped label and shift into its neighbours.
$assert = static function (bool $condition, string $message): void { if (!$condition) throw new RuntimeException($message); };
$root = dirname(__DIR__, 2);
$css = 'body{margin:0;font-family:Arial,sans-serif}header{display:flex;height:100px;background:#130022;color:#130022}.bar{display:flex;justify-content:center;align-items:center;width:70px;margin-left:1347px}.login{display:flex;align-items:center;min-width:0;white-space:nowrap;padding:6px 9px;font:inherit;color:inherit;background:none;border:0}.avatar{width:33px;height:33px;margin:0 9px;flex-shrink:0;overflow:hidden;background:#130022}.label{display:block;overflow:hidden;min-width:58px;padding:0 9px}';
$id = static function (string $node): string { return ' data-blocks-engine-node-id="' . $node . '"'; };
$html = '<!doctype html><html' . $id('n1') . '><head' . $id('n2') . '><title' . $id('n3') . '>Login</title><style>' . $css . '</style></head><body' . $id('n4') . '><header' . $id('h') . '><div class="bar" id="bar" tabindex="-1"' . $id('bar') . '><button class="login" type="button"' . $id('btn') . '><div class="avatar"' . $id('av') . '><svg viewBox="0 0 50 50" width="50" height="50"' . $id('svg') . '><path d="M25 0a25 25 0 1 0 0 50a25 25 0 1 0 0-50"' . $id('path') . '></path></svg></div><span class="label"' . $id('lbl') . '>Anmelden</span></button></div></header><main' . $id('m') . '><p' . $id('p') . '>Body copy.</p></main></body></html>';
$template = json_decode((string) file_get_contents($root . '/tests/fixtures/box-tree/index.json'), true, 32, JSON_THROW_ON_ERROR)['nodes']['n7']['viewports']['desktop']['used_styles'];
foreach ($template as $property => $value) if (str_starts_with($property, 'margin-') || str_starts_with($property, 'padding-')) $template[$property] = '0px';
$template['font-family'] = 'Arial, sans-serif';
$node = static function (array $children, array $styles, array $box) use ($template): array {
    $viewports = array();
    foreach (array('desktop' => 1440, 'mobile' => 390) as $key => $width) {
        $viewports[$key] = array('border_box' => array('x' => $box[0], 'y' => $box[1], 'width' => $box[2], 'height' => $box[3]), 'used_styles' => array_replace($template, array('width' => $box[2] . 'px', 'height' => $box[3] . 'px'), $styles));
    }
    return array('children' => $children, 'viewports' => $viewports);
};
$nodes = array(
    'n1' => $node(array('n2', 'n4'), array(), array(0, 0, 1440, 200)),
    'n2' => $node(array(), array('display' => 'none'), array(0, 0, 0, 0)),
    'n4' => $node(array('h', 'm'), array(), array(0, 0, 1440, 200)),
    'h' => $node(array('bar'), array('display' => 'flex', 'background-color' => 'rgb(19, 0, 34)', 'color' => 'rgb(19, 0, 34)'), array(0, 0, 1440, 100)),
    'bar' => $node(array('btn'), array('display' => 'flex', 'justify-content' => 'center', 'align-items' => 'center', 'margin-left' => '1347px', 'color' => 'rgb(19, 0, 34)'), array(1347, 0, 70, 100)),
    'btn' => $node(array('av', 'lbl'), array('display' => 'flex', 'align-items' => 'center', 'min-width' => '0px', 'white-space' => 'nowrap', 'padding-left' => '9px', 'padding-right' => '9px', 'padding-top' => '6px', 'padding-bottom' => '6px', 'color' => 'rgb(19, 0, 34)', 'box-sizing' => 'border-box'), array(1347, 26, 70, 45)),
    'av' => $node(array('svg'), array('display' => 'block', 'margin-left' => '9px', 'margin-right' => '9px', 'flex-shrink' => '0', 'overflow-x' => 'hidden', 'overflow-y' => 'hidden', 'background-color' => 'rgb(19, 0, 34)', 'color' => 'rgb(19, 0, 34)', 'white-space' => 'nowrap'), array(1365, 32, 33, 33)),
    'svg' => $node(array('path'), array('display' => 'block', 'width' => '33px', 'height' => '33px', 'fill' => 'rgb(19, 0, 34)', 'color' => 'rgb(19, 0, 34)', 'white-space' => 'nowrap'), array(1365, 32, 33, 33)),
    'path' => $node(array(), array('display' => 'inline', 'fill' => 'rgb(19, 0, 34)', 'color' => 'rgb(19, 0, 34)', 'white-space' => 'nowrap'), array(1365, 32, 33, 33)),
    'lbl' => $node(array(), array('display' => 'block', 'min-width' => '58px', 'overflow-x' => 'hidden', 'overflow-y' => 'hidden', 'padding-left' => '9px', 'padding-right' => '9px', 'color' => 'rgb(19, 0, 34)', 'white-space' => 'nowrap'), array(1407, 37, 76, 20)),
    'm' => $node(array('p'), array(), array(0, 100, 1440, 100)),
    'p' => $node(array(), array(), array(0, 100, 1440, 18)),
);
$tree = array('schema' => 'blocks-engine/box-tree/v2', 'source_path' => 'index.html', 'source_hash' => hash('sha256', $html), 'viewports' => array('desktop' => array('width' => 1440, 'height' => 900), 'mobile' => array('width' => 390, 'height' => 844)), 'nodes' => $nodes);
$result = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array('index.html' => $html, 'box-tree/index.json' => json_encode($tree))))->toArray();
$markup = (string) $result['serialized_blocks'];
$css = implode("\n", array_map(static fn(array $asset): string => (string) ($asset['content'] ?? ''), $result['assets'] ?? array()));
$assert(1 === preg_match('/<div class="wp-block-buttons blocks-engine-layout-neutral-buttons[^"]* (be-inline-geometry-[a-f0-9]{16})"/', $markup, $neutral), 'The fixture compiles the control inside a synthesized layout-neutral core/buttons.');
$assert(1 === preg_match('/<div class="wp-block-button login[^"]* (be-inline-geometry-[a-f0-9]{16})"/', $markup, $button), 'The control keeps a box-tree carrier on its .wp-block-button.');
$assert(1 === preg_match('/\.' . $button[1] . '\.' . $button[1] . '\{[^}]*width:70px/', $css), 'The retained .wp-block-button carries the observed control width.');
$assert(!preg_match('/\.' . $neutral[1] . '[^{}]*>\s*\.wp-block-button\s*\{[^}]*display:contents/', $css), 'A display:contents core/buttons carrier must not flatten its sized .wp-block-button child.');
$assert(!preg_match('/\.' . $neutral[1] . '\.' . $neutral[1] . '\{[^}]*width:/', $css), 'A display:contents core/buttons carrier does not own outer box declarations it cannot render.');

echo "box-tree-neutral-buttons-sized-button: ok\n";
