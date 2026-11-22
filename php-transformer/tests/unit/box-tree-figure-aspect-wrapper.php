<?php
declare(strict_types=1);

// Exercises box-tree decisions, which are opt-in (#7352).
putenv('BLOCKS_ENGINE_BOX_TREE_INTAKE=1');

require __DIR__ . '/../../vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

$html = '<!doctype html><html data-blocks-engine-node-id="root"><head data-blocks-engine-node-id="head"><style>'
    . 'body{margin:0}.tile{margin:0}.tile-frame{position:relative;padding-bottom:66%}'
    . '.tile-frame img{position:absolute;top:0;left:0;width:100%;height:100%}'
    . '</style></head><body data-blocks-engine-node-id="body">'
    . '<figure class="tile" data-blocks-engine-node-id="figure"><div class="tile-frame" data-blocks-engine-node-id="frame">'
    . '<img src="a.jpg" alt="A" width="1500" height="1000" data-blocks-engine-node-id="image"></div></figure></body></html>';
$template = json_decode((string) file_get_contents(__DIR__ . '/../fixtures/box-tree/index.json'), true, 32, JSON_THROW_ON_ERROR)['nodes']['n7']['viewports']['desktop']['used_styles'];
$viewports = array('desktop' => array('width' => 1440, 'height' => 1000), 'mobile' => array('width' => 400, 'height' => 800));
$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
};
$find = static function (array $blocks, string $class) use (&$find): ?array {
    foreach ($blocks as $block) {
        if (in_array($class, explode(' ', $block['attrs']['className'] ?? ''), true)) return $block;
        $found = $find($block['innerBlocks'] ?? array(), $class);
        if (null !== $found) return $found;
    }
    return null;
};
$hasImage = static function (array $blocks) use (&$hasImage): bool {
    foreach ($blocks as $block) {
        if ('core/image' === $block['blockName'] || $hasImage($block['innerBlocks'] ?? array())) return true;
    }
    return false;
};
// Also cover a later capture being the only one that needs the wrapper.
foreach (array('absolute', 'mobile-only', 'static') as $case) {
    $source = 'static' === $case ? str_replace('position:absolute', 'position:static', $html) : $html;
    $nodes = array();
    foreach (array('root' => array('head', 'body'), 'head' => array(), 'body' => array('figure'), 'figure' => array('frame'), 'frame' => array('image'), 'image' => array()) as $id => $children) {
        $nodes[$id] = array('children' => $children, 'viewports' => array());
        foreach ($viewports as $name => $viewport) {
            $width = $viewport['width'];
            $height = 'head' === $id ? 0 : $width * .66;
            $styles = array('display' => 'block', 'position' => 'static', 'width' => $width . 'px', 'height' => $height . 'px', 'margin-top' => '0px', 'margin-bottom' => '0px');
            if ('head' === $id) $styles['display'] = 'none';
            if ('frame' === $id) $styles = array_replace($styles, array('position' => 'relative', 'height' => '0px', 'padding-bottom' => $height . 'px'));
            if ('image' === $id) {
                $position = 'mobile-only' === $case ? ('mobile' === $name ? 'absolute' : 'static') : $case;
                $styles = array_replace($styles, array('position' => $position, 'top' => '0px', 'left' => '0px'));
            }
            $nodes[$id]['viewports'][$name] = array(
                'border_box' => array('x' => 0, 'y' => 0, 'width' => $width, 'height' => $height),
                'used_styles' => array_replace($template, $styles),
            );
        }
    }
    $tree = array('schema' => 'blocks-engine/box-tree/v2', 'source_path' => 'index.html', 'source_hash' => hash('sha256', $source), 'viewports' => $viewports, 'nodes' => $nodes);
    $result = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array(
        'index.html' => $source, 'box-tree/index.json' => json_encode($tree),
    )))->toArray();
    $figure = $find($result['blocks'], 'tile');
    $assert(null !== $figure, $case . ': figure class survives');
    if ('static' === $case) {
        $assert('core/image' === $figure['blockName'] && empty($figure['innerBlocks']), 'static: figure still lowers to a bare image');
        $assert(null === $find($result['blocks'], 'tile-frame'), 'static: decorative wrapper is still elided');
    } else {
        $frame = $find($figure['innerBlocks'] ?? array(), 'tile-frame');
        $assert(null !== $frame, $case . ': figure retains its positioned wrapper block');
        $assert($hasImage($frame['innerBlocks'] ?? array()), $case . ': wrapper contains the image block');
    }
}
echo "PASS: figure keeps its out-of-flow image containing block\n";
