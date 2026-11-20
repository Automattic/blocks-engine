<?php
declare(strict_types=1);

require __DIR__ . '/../../vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

// #7015: captured heights of an inherit-sized flex wrapper belong to layout
// when its parent is content-sized, not to an immutable pixel declaration.
$assert = static function (bool $ok, string $message, string $detail): void {
    if (!$ok) throw new RuntimeException($message . "\n" . $detail);
};
$template = json_decode((string) file_get_contents(__DIR__ . '/../fixtures/box-tree/index.json'), true, 32, JSON_THROW_ON_ERROR)['nodes']['n7']['viewports']['desktop']['used_styles'];
$viewports = array('desktop' => array('width' => 1440, 'height' => 900), 'mobile' => array('width' => 402, 'height' => 844));
foreach (array('' => true, 'height:initial' => true, 'height:unset' => true, 'height:inherit' => true, 'height:fit-content' => true, 'height:300px' => false, 'block-size:auto' => false) as $ancestor => $intrinsic) {
    $html = '<!doctype html><html data-blocks-engine-node-id="root"><head data-blocks-engine-node-id="head"><style>'
        . 'body{margin:0}.outer{' . $ancestor . '}.inner{display:flex;align-items:center;height:inherit}.row{height:62px}'
        . '@media (max-width:600px){.row{height:37px}}'
        . '</style></head><body data-blocks-engine-node-id="body"><main class="outer" data-blocks-engine-node-id="outer">'
        . '<section class="inner" data-blocks-engine-node-id="inner"><div class="row" data-blocks-engine-node-id="row">Header</div></section>'
        . '</main></body></html>';
    $nodes = array();
    foreach (array('root' => array('head', 'body'), 'head' => array(), 'body' => array('outer'), 'outer' => array('inner'), 'inner' => array('row'), 'row' => array()) as $id => $children) {
        $nodes[$id] = array('children' => $children, 'viewports' => array());
        foreach ($viewports as $name => $viewport) {
            $height = 'desktop' === $name ? 62 : 37;
            // Keep the inner observations distinct from the fixed ancestor:
            // unexplained pixels must not gain an intrinsic proof from inherit.
            if ('height:300px' === $ancestor && 'outer' === $id) $height = 300;
            if ('root' === $id) $height = $viewport['height'];
            if ('head' === $id) $height = 0;
            $styles = array('width' => $viewport['width'] . 'px', 'height' => $height . 'px');
            if ('head' === $id) $styles['display'] = 'none';
            if ('inner' === $id) $styles += array('display' => 'flex', 'align-items' => 'center');
            $nodes[$id]['viewports'][$name] = array(
                'border_box' => array('x' => 0, 'y' => 0, 'width' => $viewport['width'], 'height' => $height),
                'used_styles' => array_replace($template, $styles),
            );
        }
    }
    $tree = array('schema' => 'blocks-engine/box-tree/v2', 'source_path' => 'index.html', 'source_hash' => hash('sha256', $html), 'viewports' => $viewports, 'nodes' => $nodes);
    $result = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array('index.html' => $html, 'box-tree/index.json' => json_encode($tree))))->toArray();
    $css = implode("\n", array_column($result['assets'], 'content'));
    // Inspect only the inner carrier: the row must retain its fixed heights.
    $assert(1 === preg_match('/class="([^"]*\binner\b[^"]*)"/', $result['serialized_blocks'], $match), 'The inner has a geometry carrier', $result['serialized_blocks']);
    preg_match_all('/be-inline-geometry-[a-z0-9-]+/', $match[1], $carriers);
    $innerCss = '';
    foreach ($carriers[0] as $carrier) {
        preg_match_all('/[^{}]*\.' . preg_quote($carrier, '/') . '(?![a-z0-9-])[^{}]*\{([^{}]*)\}/', $css, $rules);
        $innerCss .= implode(';', $rules[1]) . ';';
    }
    $assert('' !== $innerCss, 'The inner carrier has CSS', $css);
    if ($intrinsic) {
        $assert(!preg_match('/(?:^|;)\s*height:(?:109|37|62)px(?:;|$)/', $innerCss), 'Inherited intrinsic height must not freeze observed pixels: ' . $ancestor, $innerCss);
        $keyword = 'height:fit-content' === $ancestor ? 'fit-content' : 'auto';
        $assert(!preg_match('/(?:^|;)\s*height:/', $innerCss) || str_contains($innerCss, 'height:' . $keyword), 'The inner lifts the inherited keyword: ' . $ancestor, $innerCss);
    } else {
        if ('height:300px' === $ancestor) {
            // The compiler may retain the author inherit instead of freezing
            // the child again; either way the fixed ancestor must stay fixed.
            $assert(str_contains($css, 'height:300px') && !str_contains($innerCss, 'height:auto'), 'A fixed ancestor does not prove an intrinsic height', $css);
        } else {
            $assert(str_contains($innerCss, 'height:62px') && str_contains($innerCss, 'height:37px') && !str_contains($innerCss, 'height:auto'), 'Aliased ancestry stays unproven', $innerCss);
        }
    }
}
echo "PASS: inherited intrinsic heights lift without freezing flex wrapper captures\n";
