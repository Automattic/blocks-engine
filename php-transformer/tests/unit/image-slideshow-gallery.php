<?php
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

$generatedStems = static function (array $result): array {
    $stems = array();
    foreach ( $result['source_reports']['generated_blocks'] ?? array() as $definition ) {
        $name = (string) ( $definition['name'] ?? '' );
        $stems[] = false !== strpos($name, '-') ? strstr($name, '-', true) : $name;
    }

    return $stems;
};

$tableSlideshow = static function (string $stageClass = 'slides'): string {
    return '<div>'
        . '<div style="height:20px;overflow:hidden"></div>'
        . '<div id="photo-slideshow" class="photo-slideshow">'
        . '<style>.photo-slideshow .stage{position:relative;z-index:2;overflow:hidden}.photo-slideshow .crop{position:absolute;z-index:1}</style>'
        . '<table class="stage-table"><tbody>'
        . '<tr><td><div class="picker" style="height:75px"><table><tbody><tr>'
        . '<td><a><div><img src="one-thumb.jpg" alt="" width="105" height="70"></div></a></td>'
        . '<td><a><div><img src="two-thumb.jpg" alt="" width="105" height="70"></div></a></td>'
        . '<td><a><div><img src="three-thumb.jpg" alt="" width="105" height="70"></div></a></td>'
        . '</tr></tbody></table></div></td></tr>'
        . '<tr><td><div class="stage"><div class="' . $stageClass . '">'
        . '<div class="slide"><div class="crop" style="width:736px;left:-368px;top:-246px"><img class="wp-image-41" src="one.jpg" alt="One" style="width:100%"></div></div>'
        . '<div class="slide" style="display:none"><div class="crop" style="width:736px;left:-368px;top:-246px"><img class="wp-image-42" src="two.jpg" alt="Two" style="width:736px"></div></div>'
        . '<div class="slide" style="display:none"><div class="crop" style="width:736px;left:-368px;top:-246px"><img class="wp-image-43" src="three.jpg" alt="Three" style="width:736px"></div></div>'
        . '</div>'
        . '<div class="overlay"><span>Play</span><span style="display:none">Pause</span></div>'
        . '</div></td></tr>'
        . '</tbody></table></div>'
        . '<div style="height:20px;overflow:hidden"></div>'
        . '</div>';
};

$result = ( new HtmlTransformer() )->transform($tableSlideshow())->toArray();
$galleries = $collect($result['blocks'] ?? array(), 'core/gallery');
$assert(1 === count($galleries), 'a host around an image-only stage and thumbnail pager becomes one core/gallery');
$assert(3 === count($galleries[0]['innerBlocks'] ?? array()), 'the gallery keeps one inner image per stage slide');
$assert(
    array( 'one.jpg', 'two.jpg', 'three.jpg' ) === array_map(
        static fn (array $image): string => (string) ( $image['attrs']['url'] ?? '' ),
        $galleries[0]['innerBlocks'] ?? array()
    ),
    'stage images keep source order and skip thumbnail duplicates'
);
$assert(
    array( 41, 42, 43 ) === array_map(
        static fn (array $image): int => (int) ( $image['attrs']['id'] ?? 0 ),
        $galleries[0]['innerBlocks'] ?? array()
    ),
    'Media Library attachment ids survive when the importer provided them'
);
$assert(
    'One' === ( $galleries[0]['innerBlocks'][0]['attrs']['alt'] ?? null )
        && ! str_contains((string) ( $result['serialized_blocks'] ?? '' ), 'one-thumb.jpg'),
    'alt text is preserved and thumbnail chrome is not emitted as gallery images'
);
$assert(! in_array('gallery', $generatedStems($result), true), 'the image-only slideshow is not a generated gallery companion');
$assert(array() === ( $result['fallbacks'] ?? array() ), 'image-only slideshow conversion emits no fallbacks');
$assert('pass' === ( $result['source_reports']['wp_block_validity']['status'] ?? null ), 'the gallery serialization is editor-valid');

$deep = $tableSlideshow();
for ( $depth = 0; $depth < 16; $depth++ ) {
    $deep = '<div class="nest-' . $depth . '">' . $deep . '</div>';
}
$deepResult = ( new HtmlTransformer() )->transform($deep)->toArray();
$deepGalleries = $collect($deepResult['blocks'] ?? array(), 'core/gallery');
$assert(1 === count($deepGalleries) && 3 === count($deepGalleries[0]['innerBlocks'] ?? array()), 'a deeply nested image-only slideshow still becomes core/gallery');
$assert(! in_array('gallery', $generatedStems($deepResult), true), 'deep nesting does not fall back to a hashed gallery companion');

$videoSource = str_replace(
    '<div class="slide"><div class="crop" style="width:736px;left:-368px;top:-246px"><img class="wp-image-41" src="one.jpg" alt="One" style="width:100%"></div></div>',
    '<div class="slide"><video src="clip.mp4"></video></div>',
    $tableSlideshow()
);
$videoResult = ( new HtmlTransformer() )->transform($videoSource)->toArray();
$assert(
    array() === $collect($videoResult['blocks'] ?? array(), 'core/gallery'),
    'a slideshow that mixes video is not rewritten into core/gallery'
);

$mixed = ( new HtmlTransformer() )->transform(
    '<div class="hero-slideshow"><button aria-label="Previous">Previous</button><div role="list">'
    . '<article role="listitem"><h2>First</h2><p>First complete testimonial.</p><img src="first.jpg" alt="First"></article>'
    . '<article role="listitem"><h2>Second</h2><p>Second complete testimonial.</p><img src="second.jpg" alt="Second"></article>'
    . '</div><button aria-label="Next">Next</button></div>'
)->toArray();
$assert(
    'custom/authored-carousel' === ( $mixed['blocks'][0]['blockName'] ?? null ),
    'mixed-content slideshows keep the authored carousel primitive'
);

$collage = ( new HtmlTransformer() )->transform(
    '<div class="collage" style="position:relative;overflow:hidden;isolation:isolate;width:680px;height:560px">'
    . '<img style="position:absolute;left:0;top:0;z-index:1;width:470px;height:505px" src="https://example.com/primary.jpg" alt="Primary">'
    . '<img style="position:absolute;left:390px;top:225px;z-index:2;width:296px;height:340px" src="https://example.com/secondary.jpg" alt="Secondary">'
    . '</div>'
)->toArray();
$assert(
    array() === $collect($collage['blocks'] ?? array(), 'core/gallery'),
    'a positioned collage is not promoted to core/gallery'
);

fwrite(STDOUT, "Image slideshow gallery tests passed\n");
