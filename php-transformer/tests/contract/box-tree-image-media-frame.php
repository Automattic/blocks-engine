<?php
declare(strict_types=1);

// Exercises box-tree decisions, which are opt-in (#7352).
putenv('BLOCKS_ENGINE_BOX_TREE_INTAKE=1');

// Card media are authored as `<div class="media"><img class="zoom"></div>`:
// the box sets the ratio, clips and paints, and an in-flow image fills it.
// When the capture proves the image's border box is the box's at every
// viewport, the box is the image's figure: one core/image whose <figure>
// carries the box's classes, with the image's own rules still on the <img>
// beneath it, not a Group wrapping an Image. A box the image does not fill,
// or a box with an identity of its own, keeps its Group.
require __DIR__ . '/../../vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\Contract\BoxTree;

$assert = static function (bool $ok, string $message): void {
    if (!$ok) throw new RuntimeException($message);
    echo "PASS: $message\n";
};

$styles = static function (string $display, array $overrides = array()): array {
    $used = array_fill_keys(BoxTree::PROPERTIES, 'none');
    foreach (array('padding-top', 'padding-right', 'padding-bottom', 'padding-left', 'margin-top', 'margin-right', 'margin-bottom', 'margin-left', 'border-radius') as $box) $used[$box] = '0px';
    foreach (array('border-top', 'border-right', 'border-bottom', 'border-left') as $border) $used[$border] = '0px none rgb(0, 0, 0)';
    return array_merge($used, array(
        'display' => $display, 'position' => 'static', 'overflow-x' => 'visible', 'overflow-y' => 'visible',
        'top' => 'auto', 'right' => 'auto', 'bottom' => 'auto', 'left' => 'auto',
        'background-color' => 'rgba(0, 0, 0, 0)', 'opacity' => '1', 'mix-blend-mode' => 'normal',
        'color' => 'rgb(0, 0, 0)', 'font-family' => 'Arial', 'font-size' => '16px', 'line-height' => '24px',
    ), $overrides);
};

$compile = static function (int $imageInset = 0, string $frameAttributes = '') use ($styles): string {
    $html = '<!doctype html><html data-blocks-engine-node-id="n1"><head data-blocks-engine-node-id="n2"><title data-blocks-engine-node-id="n3">Menu</title></head>'
        . '<body data-blocks-engine-node-id="n4"><main data-blocks-engine-node-id="n5"><div class="card" data-blocks-engine-node-id="card">'
        . '<div class="media"' . $frameAttributes . ' data-blocks-engine-node-id="media"><img src="https://example.com/dish.jpg" alt="Catering dishes" class="zoom" data-blocks-engine-node-id="image"></div>'
        . '<p data-blocks-engine-node-id="caption">Catering dishes ready for service</p></div></main></body></html>';
    $observe = static fn (array $box, array $used): array => array(
        'desktop' => array('border_box' => $box, 'used_styles' => $used),
        'mobile' => array('border_box' => $box, 'used_styles' => $used),
    );
    $media = array('x' => 0, 'y' => 0, 'width' => 400, 'height' => 225);
    $image = array('x' => $imageInset, 'y' => $imageInset, 'width' => 400 - 2 * $imageInset, 'height' => 225 - 2 * $imageInset);
    $nodes = array(
        'n1' => array('children' => array('n4'), 'viewports' => $observe(array('x' => 0, 'y' => 0, 'width' => 400, 'height' => 300), $styles('block'))),
        'n4' => array('children' => array('n5'), 'viewports' => $observe(array('x' => 0, 'y' => 0, 'width' => 400, 'height' => 300), $styles('block'))),
        'n5' => array('children' => array('card'), 'viewports' => $observe(array('x' => 0, 'y' => 0, 'width' => 400, 'height' => 300), $styles('block'))),
        'card' => array('children' => array('media', 'caption'), 'viewports' => $observe(array('x' => 0, 'y' => 0, 'width' => 400, 'height' => 300), $styles('block', array('overflow-x' => 'hidden', 'overflow-y' => 'hidden', 'border-radius' => '16px', 'background-color' => 'rgb(255, 255, 255)')))),
        'media' => array('children' => array('image'), 'viewports' => $observe($media, $styles('block', array('aspect-ratio' => '16 / 9', 'overflow-x' => 'hidden', 'overflow-y' => 'hidden', 'background-color' => 'rgb(240, 240, 240)', 'padding-top' => $imageInset . 'px', 'padding-right' => $imageInset . 'px', 'padding-bottom' => $imageInset . 'px', 'padding-left' => $imageInset . 'px')))),
        'image' => array('children' => array(), 'viewports' => $observe($image, $styles('block', array('object-fit' => 'cover', 'transition-property' => 'transform')))),
        'caption' => array('children' => array(), 'viewports' => $observe(array('x' => 0, 'y' => 241, 'width' => 400, 'height' => 24), $styles('block'))),
    );
    $tree = array('schema' => BoxTree::SCHEMA, 'viewports' => array('desktop' => array('width' => 1440, 'height' => 900), 'mobile' => array('width' => 390, 'height' => 844)), 'nodes' => $nodes, 'source_path' => 'index.html');
    $css = '.card{overflow:hidden;border-radius:16px;background:#fff}'
        . '.media{aspect-ratio:16/9;overflow:hidden;background:#f0f0f0;padding:' . $imageInset . 'px}'
        . '.zoom{display:block;width:100%;height:100%;object-fit:cover;transition:transform .5s}.zoom:hover{transform:scale(1.05)}'
        . 'p{margin:16px 0 0}';
    $html = str_replace('</title>', '</title><style>' . $css . '</style>', $html);
    $tree['source_hash'] = hash('sha256', $html);
    return (string) (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array('index.html' => $html, 'box-tree/index.json' => json_encode($tree))))->toArray()['serialized_blocks'];
};

$blockNames = static function (string $markup): array {
    preg_match_all('/<!-- wp:([a-z0-9\/-]+)/', $markup, $matches);
    return $matches[1];
};

$framed = $compile();
$names = $blockNames($framed);
$assert(1 === count(array_keys($names, 'image', true)), 'The card media is one core/image.');
$assert(1 === preg_match('/<figure class="wp-block-image[^"]*\bmedia\b[^"]*"[^>]*><img [^>]*alt="Catering dishes"/', $framed), 'The media box is the image\'s figure, around the image.');
$assert(0 === preg_match('/<figure class="[^"]*\bzoom\b/', $framed), 'The image\'s own rules stay off the figure, so its hover zoom still clips inside the box.');
$assert(1 === count(array_keys($names, 'group', true)) && 0 === preg_match('/<!-- wp:group \{[^}]*"className":"[^"]*\bmedia\b/', $framed), 'No Group stands in for the media box: only the card remains.');

$inset = $compile(8);
$assert(1 === preg_match('/<!-- wp:group \{[^}]*"className":"[^"]*\bmedia\b/', $inset), 'A box the image does not fill keeps its Group.');

$identified = $compile(0, ' id="hero-media"');
$assert(1 === preg_match('/<!-- wp:group \{[^}]*"className":"[^"]*\bmedia\b/', $identified), 'A box with an identity of its own keeps its Group.');
