<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\WordPress\AuthoredCarouselGalleryContract;

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        fwrite(STDERR, 'FAIL: ' . $message . PHP_EOL);
        exit(1);
    }
};
$collect = static function (array $blocks, string $name) use (&$collect): array {
    $found = array();
    foreach ($blocks as $block) {
        // A site import publishes a carousel as a core Gallery or Group
        // carrying the carousel stage; either form is the authored carousel.
        $isCarousel = 'custom/authored-carousel' === $name && 'custom/authored-carousel' !== ($block['blockName'] ?? null)
            && null !== AuthoredCarouselGalleryContract::carouselAttributes($block);
        if ($name === ($block['blockName'] ?? null) || $isCarousel) $found[] = $block;
        $found = array_merge($found, $collect($block['innerBlocks'] ?? array(), $name));
    }
    return $found;
};
$images = '<div class="media__slide media__slide--image"><div class="media"><img src="/1.jpg" alt="1"></div></div>'
    . '<div class="media__slide media__slide--image"><div class="media"><img src="/2.jpg" alt="2"></div></div>';
$video = '<div class="media__slide media__slide--video"><div class="media"><video muted playsinline poster="/v.webp" src="/v.mp4"><source src="/v.mp4" type="video/mp4"><img src="/v.webp" alt=""></video></div></div>';
$compile = static function (string $slides): array {
    $html = '<div><x-media-slider class="media-images" data-captions="false">' . $slides . '</x-media-slider>'
        . '<div class="media-buttons"><button type="button" aria-label="Previous">‹</button><button type="button" aria-label="Next">›</button></div></div>';
    return (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array('index.html' => $html)))->toArray();
};
$result = $compile($images . $video);
$carousels = $collect($result['blocks'], 'custom/authored-carousel');
$assert(1 === count($carousels), 'mixed native media slides compile to one authored carousel');
$carousel = $carousels[0];
$assert(3 === count($carousel['innerBlocks']), 'the carousel retains all three slides');
$nativeImages = $collect($carousel['innerBlocks'], 'core/image');
$assert(2 === count($nativeImages), 'two slides remain native images');
$assert(array('1', '2') === array_column(array_column($nativeImages, 'attrs'), 'alt'), 'image source order survives');
$assert(1 === count($collect(array($carousel['innerBlocks'][2]), 'core/video')), 'the video retains its third-slide position');
$videos = $collect($carousel['innerBlocks'], 'core/video');
$assert(1 === count($videos), 'the rich-slide path produces one native video');
$assert('/v.mp4' === $videos[0]['attrs']['src'] && '/v.webp' === $videos[0]['attrs']['poster'], 'video source and poster survive');
$assert(true === $videos[0]['attrs']['muted'] && true === $videos[0]['attrs']['playsInline'], 'video playback attributes survive');
$assert(!str_contains(json_encode($result['fallbacks']), 'missing_functional_content'), 'converted slider has no missing functional content finding');
$assert(array() === $collect($result['blocks'], 'core/html'), 'converted media needs no raw HTML');

$control = $compile($images);
$controls = $collect($control['blocks'], 'custom/authored-carousel');
$assert(1 === count($controls) && 2 === count($controls[0]['innerBlocks']), 'image-only control remains a two-slide carousel');
$published = (new \Automattic\BlocksEngine\PhpTransformer\WordPress\Runtime())->parseBlocks($control['source_reports']['wordpress_site_plan']['pages'][0]['canonical_block_markup']);
$publishedControls = $collect($published, 'custom/authored-carousel');
$assert('core/gallery' === ($publishedControls[0]['blockName'] ?? null), 'the image-only control is published as an editable core gallery');
$assert($controls[0]['innerBlocks'] === array_slice($carousel['innerBlocks'], 0, 2), 'image slides convert exactly as in the image-only control');
$sourceOnly = $compile($images . str_replace(' src="/v.mp4"><source', '><source', $video));
$assert(1 === count($collect($sourceOnly['blocks'], 'core/video')) && 1 === count($collect($sourceOnly['blocks'], 'custom/authored-carousel')), 'a native source child also supplies the video');
$empty = $compile($images . str_replace(' src="/v.mp4"', '', $video));
$assert(array() === $collect($empty['blocks'], 'custom/authored-carousel'), 'a poster without a video source is not a media slide');
foreach (array('<audio src="/v.mp4"></audio>', '<iframe src="/v.mp4"></iframe>', '<video src="/v.mp4"></video><img src="/poster.jpg">') as $unsupported) {
    $output = $compile($images . '<div class="media__slide"><div class="media">' . $unsupported . '</div></div>');
    $assert(array() === $collect($output['blocks'], 'custom/authored-carousel'), 'unsupported or mixed media within a slide does not qualify as a homogeneous media list');
}
echo "Media slider video slide regression passed\n";
