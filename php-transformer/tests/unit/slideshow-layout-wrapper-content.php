<?php
/**
 * A layout wrapper whose class carries a carousel-ish token (Dawn's
 * `product--thumbnail_slider`) and holds an image slideshow beside a heading
 * and text is not the slideshow: the slideshow becomes a gallery and the
 * sibling content survives. See Automattic/blocks-engine#6440.
 */
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

$assert = static function (bool $condition, string $message): void {
    if ( ! $condition ) {
        fwrite(STDERR, 'FAIL: ' . $message . PHP_EOL);
        exit(1);
    }
};

$collect = static function (array $blocks, string $name) use (&$collect): array {
    $matches = array();
    foreach ( $blocks as $block ) {
        if ( $name === ( $block['blockName'] ?? null ) ) {
            $matches[] = $block;
        }
        array_push($matches, ...$collect($block['innerBlocks'] ?? array(), $name));
    }

    return $matches;
};

$slideshow = '<item-gallery><item-slider><ul>'
    . '<li><div><item-opener><div><img src="/media/p.jpg" alt="Photo"></div></item-opener></div></li>'
    . '<li><div><item-opener><div><img src="/media/p.jpg" alt="Photo"></div></item-opener></div></li>'
    . '</ul></item-slider><item-slider><ul>'
    . '<li><button><img srcset="/media/thumb1.webp 416w, /media/thumb1-s.webp 104w" width="416"></button></li>'
    . '<li><button><img srcset="/media/thumb2.webp 416w, /media/thumb2-s.webp 104w" width="416"></button></li>'
    . '</ul></item-slider></item-gallery>';
$page = static fn (string $body): string => '<!DOCTYPE html><html lang="en"><head><title>Sample</title></head><body><main>'
    . $body . '</main></body></html>';

$result = ( new HtmlTransformer() )->transform($page(
    '<item-info><div><div class="product product--thumbnail_slider"><div>' . $slideshow . '</div>'
    . '<div><section><div><h1>Sample product</h1><p>Short description of the product.</p></div></section></div>'
    . '</div></div></item-info>'
))->toArray();
$blocks = $result['blocks'] ?? array();
$serialized = (string) ( $result['serialized_blocks'] ?? '' );
$headings = $collect($blocks, 'core/heading');
$assert(1 === count($headings) && str_contains($serialized, 'Sample product'), 'the layout wrapper keeps its h1 heading');
$assert(1 === ( $headings[0]['attrs']['level'] ?? null ), 'the heading keeps level 1');
$assert(
    1 === count($collect($blocks, 'core/paragraph')) && str_contains($serialized, 'Short description of the product.'),
    'the layout wrapper keeps its paragraph text'
);
$galleries = $collect($blocks, 'core/gallery');
$assert(1 === count($galleries), 'the slideshow inside the layout wrapper still becomes one core/gallery');
$assert(2 <= count($galleries[0]['innerBlocks'] ?? array()), 'the gallery keeps the slideshow images');

// A carousel-named element holding only the slideshow lists and controls is
// still the slideshow itself.
$result = ( new HtmlTransformer() )->transform($page(
    '<div class="product product--thumbnail_slider"><div>' . $slideshow . '</div></div>'
))->toArray();
$blocks = $result['blocks'] ?? array();
$galleries = $collect($blocks, 'core/gallery');
$assert(1 === count($galleries), 'a pure carousel-identity slideshow still becomes one core/gallery');
$assert(2 <= count($galleries[0]['innerBlocks'] ?? array()), 'the pure slideshow gallery keeps its images');
$assert(
    'core/gallery' === ( $blocks[0]['blockName'] ?? null ) && 1 === count($blocks),
    'the pure carousel-identity element is replaced by the gallery as a whole'
);

echo "slideshow layout wrapper content ok\n";
