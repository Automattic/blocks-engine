<?php
declare(strict_types=1);

// Exercises box-tree decisions, which are opt-in (#7352).
putenv('BLOCKS_ENGINE_BOX_TREE_INTAKE=1');

// Image placeholders emit `<span class="aspect-square …"><img class="absolute
// inset-0 h-full w-full object-cover"></span>`. When the capture proves the
// image covers the frame exactly, the frame is the image's own box: one
// core/image carrying the frame's classes, not a Group wrapping an Image.
// An image the capture shows inset within its frame keeps both boxes.
require __DIR__ . '/../../vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\Contract\BoxTree;

$assert = static function (bool $ok, string $message): void {
    if (!$ok) throw new RuntimeException($message);
    echo "PASS: $message\n";
};

$styles = static function (string $display, string $position, array $overrides = array()): array {
    $used = array_fill_keys(BoxTree::PROPERTIES, 'none');
    foreach (array('padding-top', 'padding-right', 'padding-bottom', 'padding-left', 'margin-top', 'margin-right', 'margin-bottom', 'margin-left', 'border-radius') as $box) $used[$box] = '0px';
    foreach (array('border-top', 'border-right', 'border-bottom', 'border-left') as $border) $used[$border] = '0px none rgb(0, 0, 0)';
    $used = array_merge($used, array(
        'display' => $display, 'position' => $position, 'overflow-x' => 'visible', 'overflow-y' => 'visible',
        'top' => 'auto', 'right' => 'auto', 'bottom' => 'auto', 'left' => 'auto',
        'background-color' => 'rgba(0, 0, 0, 0)', 'opacity' => '1', 'mix-blend-mode' => 'normal',
        'object-fit' => 'cover', 'object-position' => '50% 50%',
        'color' => 'rgb(0, 0, 0)', 'font-family' => 'Arial', 'font-size' => '16px', 'line-height' => '24px',
    ));
    return array_merge($used, $overrides);
};

$compile = static function (int $imageInset, bool $ratioFrame = true, bool $blockFrame = false) use ($styles): string {
    $frameTag = $blockFrame ? 'div' : 'span';
    $html = '<!doctype html><html data-blocks-engine-node-id="n1"><head data-blocks-engine-node-id="n2"><title data-blocks-engine-node-id="n3">Gallery</title></head>'
        . '<body data-blocks-engine-node-id="n4"><main data-blocks-engine-node-id="n5"><div class="tile" data-blocks-engine-node-id="tile">'
        . '<' . $frameTag . ' class="frame" data-blocks-engine-node-id="frame"><img src="https://example.com/dish.jpg" alt="Catering dishes" class="fill" data-blocks-engine-node-id="image"></' . $frameTag . '>'
        . '</div><p data-blocks-engine-node-id="caption">Catering dishes ready for service</p></main></body></html>';
    $observe = static fn (array $box, array $used): array => array(
        'desktop' => array('border_box' => $box, 'used_styles' => $used),
        'mobile' => array('border_box' => $box, 'used_styles' => $used),
    );
    $box = static fn (int $inset = 0): array => array('x' => $inset, 'y' => $inset, 'width' => 286 - 2 * $inset, 'height' => 286 - 2 * $inset);
    $nodes = array(
        'n1' => array('children' => array('n4'), 'viewports' => $observe(array('x' => 0, 'y' => 0, 'width' => 400, 'height' => 340), $styles('block', 'static'))),
        'n4' => array('children' => array('n5'), 'viewports' => $observe(array('x' => 0, 'y' => 0, 'width' => 400, 'height' => 340), $styles('block', 'static'))),
        'n5' => array('children' => array('tile', 'caption'), 'viewports' => $observe(array('x' => 0, 'y' => 0, 'width' => 400, 'height' => 340), $styles('block', 'static'))),
        'tile' => array('children' => array('frame'), 'viewports' => $observe(array('x' => 0, 'y' => 0, 'width' => 400, 'height' => 292), $styles('block', 'static', array('overflow-x' => 'hidden', 'overflow-y' => 'hidden', 'border-radius' => '12px')))),
        'frame' => array('children' => array('image'), 'viewports' => $observe($box(), $styles($blockFrame ? 'block' : 'inline-block', 'relative', array('aspect-ratio' => $ratioFrame ? '1 / 1' : 'auto', 'height' => $ratioFrame ? '286px' : '100%')))),
        'image' => array('children' => array(), 'viewports' => $observe($box($imageInset), $styles('block', 'absolute', array('top' => $imageInset . 'px', 'right' => $imageInset . 'px', 'bottom' => $imageInset . 'px', 'left' => $imageInset . 'px')))),
        'caption' => array('children' => array(), 'viewports' => $observe(array('x' => 0, 'y' => 300, 'width' => 400, 'height' => 24), $styles('block', 'static'))),
    );
    $tree = array('schema' => BoxTree::SCHEMA, 'viewports' => array('desktop' => array('width' => 1440, 'height' => 900), 'mobile' => array('width' => 390, 'height' => 844)), 'nodes' => $nodes, 'source_path' => 'index.html', 'source_hash' => hash('sha256', $html));
    $css = '.tile{overflow:hidden;border-radius:12px}.frame{' . ($blockFrame ? '' : 'display:inline-block;') . 'position:relative;' . ($ratioFrame ? 'aspect-ratio:1/1;' : 'height:100%;') . 'width:100%;object-fit:cover}.fill{position:absolute;inset:' . $imageInset . 'px;width:100%;height:100%;object-fit:cover}';
    $html = str_replace('</title>', '</title><style>' . $css . '</style>', $html);
    $tree['source_hash'] = hash('sha256', $html);
    return (string) (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array('index.html' => $html, 'box-tree/index.json' => json_encode($tree))))->toArray()['serialized_blocks'];
};

$blockNames = static function (string $markup): array {
    preg_match_all('/<!-- wp:([a-z0-9\/-]+)/', $markup, $matches);
    return $matches[1];
};

$merged = $compile(0);
$names = $blockNames($merged);
$assert(1 === count(array_keys($names, 'image', true)), 'The framed image is one core/image.');
$assert(1 === preg_match('/<!-- wp:image \{[^}]*"className":"[^"]*\bframe\b/', $merged), 'The image block carries the frame\'s classes.');
$assert(!str_contains($merged, '"className":"fill') && 0 === preg_match('/<!-- wp:image \{[^}]*\bfill\b/', $merged), 'The image\'s fill-the-frame classes are gone with the frame.');
$assert(2 === count(array_keys($names, 'group', true)), 'Only main and the tile remain Groups: no Group stands in for the frame.');
$assert(str_contains($merged, 'alt="Catering dishes"'), 'The image keeps its own description.');

$percentage = $compile(0, false);
$assert(3 === count(array_keys($blockNames($percentage), 'group', true)) && str_contains($percentage, '"className":"frame'), 'A percentage-height frame keeps its containing box even when the image fills it at every captured width.');
$assert(0 === preg_match('/<!-- wp:image \{[^}]*"className":"[^"]*\bframe\b/', $percentage), 'A percentage-height frame never transfers its sizing classes to the image.');

$inset = $compile(8);
$assert(3 === count(array_keys($blockNames($inset), 'group', true)) && str_contains($inset, '"className":"frame'), 'An image inset within its frame keeps the frame as its own box.');

// A `div` frame is a block box by its tag alone. Once the image takes its
// place it keeps that box: an inline image would sit on a line box and gain
// a baseline strut below it that the frame never had.
$block = $compile(0, true, true);
$assert(1 === preg_match('/<!-- wp:image \{[^}]*"className":"[^"]*\bframe\b/', $block) && 2 === count(array_keys($blockNames($block), 'group', true)), 'A block frame merges into its image.');
$assert(!str_contains($block, 'blocks-engine-synthetic-image-figure-strut') && !str_contains($block, 'blocks-engine-synthetic-image-figure-inline'), 'The merged image of a block frame keeps a block box, not an inline image on a line box.');
