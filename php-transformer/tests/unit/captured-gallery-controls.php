<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

// Observed directional evidence must be sufficient even for unnamed SVG arrows.
$items = '<li data-blocks-engine-capture-gallery-frame="0"><img src="https://example.test/one.jpg" alt="One"></li>'
    . '<li data-blocks-engine-capture-gallery-frame="1"><img src="https://example.test/two.jpg" alt="Two"></li>'
    . '<li data-blocks-engine-capture-gallery-frame="2"><img src="https://example.test/three.jpg" alt="Three"></li>';
$source = '<div class="gallery"><div><ul data-blocks-engine-capture-gallery-stage data-blocks-engine-capture-gallery-initial="1">' . $items . '</ul></div>'
    . '<div class="actions"><button data-blocks-engine-capture-gallery-direction="-1"><svg viewBox="0 0 24 24"><path d="M16 4L8 12L16 20"/></svg></button>'
    . '<button data-blocks-engine-capture-gallery-direction="1"><svg viewBox="0 0 24 24"><path d="M8 4L16 12L8 20"/></svg></button></div></div>';
$result = (new HtmlTransformer())->transform($source)->toArray();
$carousels = array();
$visit = static function (array $blocks) use (&$visit, &$carousels): void {
    foreach ($blocks as $block) {
        if ('custom/authored-carousel' === ($block['blockName'] ?? '')) $carousels[] = $block;
        $visit($block['innerBlocks'] ?? array());
    }
};
$visit($result['blocks']);
if (1 !== count($carousels)) throw new RuntimeException('Captured unnamed directional controls must produce one editable carousel.');
$carousel = $carousels[0];
if (3 !== count($carousel['innerBlocks'])) throw new RuntimeException('Every observed frame must remain an editable image.');
if (1 !== $carousel['attrs']['initialSlide']) throw new RuntimeException('The captured selected frame must remain selected.');
if (!str_contains($carousel['innerHTML'], 'aria-label="Previous slide"') || !str_contains($carousel['innerHTML'], 'aria-label="Next slide"')) throw new RuntimeException('Captured arrows must gain accessible native actions.');
echo "Captured gallery unnamed control regression passed\n";

// A verified touch cycle remains a native slideshow without source arrows.
$touch = '<div class="gallery"><ul data-blocks-engine-capture-gallery-stage data-blocks-engine-capture-gallery-track="0.3" data-blocks-engine-capture-gallery-initial="1">' . $items . '</ul></div>';
$carousels = array();
$visit((new HtmlTransformer())->transform($touch)->toArray()['blocks']);
if (1 !== count($carousels)) throw new RuntimeException('A captured touch-only track must remain an editable carousel.');
if (true !== ($carousels[0]['attrs']['swipe'] ?? null)) throw new RuntimeException('The native carousel must retain the proven touch interaction.');
