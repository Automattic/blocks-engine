<?php
declare(strict_types=1);

// Menu cards emit `<div class="aspect-[16/9] overflow-hidden bg-secondary">
// <img class="h-full w-full hover:scale-105"></div>`: an in-flow image filling
// a clipping frame. When the capture proves the image's border box is the
// frame's border box at every viewport, the frame is the image's own figure:
// one core/image whose figure carries the frame's classes, while the image
// keeps its own rules (the hover zoom stays clipped by the figure). Kept as
// authored, the frame became a Group around the Image, a wrapper an editor has
// to click through without it meaning anything.
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
        'color' => 'rgb(0, 0, 0)', 'font-family' => 'Arial', 'font-size' => '16px', 'line-height' => '24px',
    ));
    return array_merge($used, $overrides);
};

$compile = static function (int $imageInset, string $frameAttributes = '') use ($styles): string {
    $html = '<!doctype html><html data-blocks-engine-node-id="n1"><head data-blocks-engine-node-id="n2"><title data-blocks-engine-node-id="n3">Menu</title></head>'
        . '<body data-blocks-engine-node-id="n4"><main data-blocks-engine-node-id="n5"><div class="card" data-blocks-engine-node-id="card">'
        . '<div class="frame"' . $frameAttributes . ' data-blocks-engine-node-id="frame"><img src="https://example.com/dish.jpg" alt="Set lunch" class="fill" data-blocks-engine-node-id="image"></div>'
        . '<div class="body" data-blocks-engine-node-id="body"><h3 data-blocks-engine-node-id="title">Set lunch</h3><p data-blocks-engine-node-id="text">Rice, chicken and vegetables</p></div>'
        . '</div></main></body></html>';
    $observe = static fn (array $box, array $used): array => array(
        'desktop' => array('border_box' => $box, 'used_styles' => $used),
        'mobile' => array('border_box' => $box, 'used_styles' => $used),
    );
    $frame = array('x' => 0, 'y' => 0, 'width' => 384, 'height' => 216);
    $image = array('x' => $imageInset, 'y' => $imageInset, 'width' => 384 - 2 * $imageInset, 'height' => 216 - 2 * $imageInset);
    $nodes = array(
        'n1' => array('children' => array('n4'), 'viewports' => $observe(array('x' => 0, 'y' => 0, 'width' => 400, 'height' => 340), $styles('block', 'static'))),
        'n4' => array('children' => array('n5'), 'viewports' => $observe(array('x' => 0, 'y' => 0, 'width' => 400, 'height' => 340), $styles('block', 'static'))),
        'n5' => array('children' => array('card'), 'viewports' => $observe(array('x' => 0, 'y' => 0, 'width' => 400, 'height' => 340), $styles('block', 'static'))),
        'card' => array('children' => array('frame', 'body'), 'viewports' => $observe(array('x' => 0, 'y' => 0, 'width' => 384, 'height' => 320), $styles('block', 'static', array('overflow-x' => 'hidden', 'overflow-y' => 'hidden', 'border-radius' => '16px')))),
        'frame' => array('children' => array('image'), 'viewports' => $observe($frame, $styles('block', 'static', array('aspect-ratio' => '16 / 9', 'overflow-x' => 'hidden', 'overflow-y' => 'hidden', 'background-color' => 'rgb(240, 240, 240)', 'padding-top' => $imageInset . 'px', 'padding-right' => $imageInset . 'px', 'padding-bottom' => $imageInset . 'px', 'padding-left' => $imageInset . 'px')))),
        'image' => array('children' => array(), 'viewports' => $observe($image, $styles('inline', 'static'))),
        'body' => array('children' => array('title', 'text'), 'viewports' => $observe(array('x' => 0, 'y' => 216, 'width' => 384, 'height' => 104), $styles('block', 'static'))),
        'title' => array('children' => array(), 'viewports' => $observe(array('x' => 0, 'y' => 216, 'width' => 384, 'height' => 28), $styles('block', 'static'))),
        'text' => array('children' => array(), 'viewports' => $observe(array('x' => 0, 'y' => 260, 'width' => 384, 'height' => 24), $styles('block', 'static'))),
    );
    $css = '.card{overflow:hidden;border-radius:16px}.frame{aspect-ratio:16/9;overflow:hidden;background:#f0f0f0;padding:' . $imageInset . 'px}'
        . '.fill{width:100%;height:100%;transition:transform .5s}.fill:hover{transform:scale(1.05)}';
    $html = str_replace('</title>', '</title><style>' . $css . '</style>', $html);
    $tree = array('schema' => BoxTree::SCHEMA, 'viewports' => array('desktop' => array('width' => 1440, 'height' => 900), 'mobile' => array('width' => 390, 'height' => 844)), 'nodes' => $nodes, 'source_path' => 'index.html', 'source_hash' => hash('sha256', $html));
    $result = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array('index.html' => $html, 'box-tree/index.json' => json_encode($tree))))->toArray();
    return (string) $result['serialized_blocks'] . "\n" . json_encode($result, JSON_UNESCAPED_SLASHES);
};

$blockNames = static function (string $markup): array {
    preg_match_all('/<!-- wp:([a-z0-9\/-]+)/', $markup, $matches);
    return $matches[1];
};

$filled = $compile(0);
$names = $blockNames(strstr($filled, "\n", true));
$assert(1 === count(array_keys($names, 'image', true)), 'The framed image is one core/image.');
$assert(2 === count(array_keys($names, 'group', true)), 'Only the card and its body remain Groups: no Group stands in for the frame.');
$assert(1 === preg_match('/<!-- wp:image \{[^}]*"className":"[^"]*\bframe\b/', $filled), 'The image block\'s figure carries the frame\'s classes.');
$assert(str_contains($filled, 'alt="Set lunch"'), 'The image keeps its own description.');
$assert(str_contains($filled, 'transform:scale(1.05)'), 'The image keeps its own hover zoom inside the clipping figure.');
// No author selector names div. Lowering one frame must not opt every div
// into tag projection: that also changes unrelated Groups and shared CSS.
$assert(!str_contains($filled, 'blocks-engine-source-div-'), 'Image-frame lowering does not allocate a site-wide div projection marker.');

$inset = $compile(8);
$assert(3 === count(array_keys($blockNames(strstr($inset, "\n", true)), 'group', true)) && 1 === preg_match('/<!-- wp:group \{[^}]*"className":"frame/', $inset), 'An image inset within its frame keeps the frame as its own box.');

$identified = $compile(0, ' id="dish"');
$assert(1 === preg_match('/<!-- wp:group \{[^}]*"className":"frame/', $identified), 'A frame with its own identity keeps its box.');
