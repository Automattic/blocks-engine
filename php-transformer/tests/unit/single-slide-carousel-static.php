<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

$failures = array();
$assert = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) $failures[] = $message;
};
$compile = static fn (string $body): array => (new ArtifactCompiler())->compile(array(
    'entrypoint' => 'index.html',
    'files' => array('index.html' => '<!doctype html><html><head><title>Slides</title></head><body><main>' . $body . '</main></body></html>'),
))->toArray();
$slide = '<div class="carousel__slide is-selected" style="position:absolute;left:0"><div class="slide-media"><img src="/a-desktop.png" alt=""><img src="/a-mobile.png" alt=""></div><a href="/collections/new" class="slide-link"></a></div>';
$host = static fn (string $slides, string $controls = '', string $attributes = ''): string => '<x-slides class="slideshow carousel" data-autoplay="5000" data-dots="false" ' . $attributes . '><div class="viewport" style="height:35vw"><div class="track-slider">' . $slides . '</div></div>' . $controls . '</x-slides>';
$other = '<div class="slideshow carousel"><div class="track-slider"><div class="carousel__slide"><img src="/other-one.png" alt=""></div><div class="carousel__slide"><img src="/other-two.png" alt=""></div></div><button aria-label="Next slide">Next</button></div>';
$carousels = static function (array $blocks) use (&$carousels): array {
    $found = array();
    foreach ($blocks as $block) {
        if (str_ends_with($block['blockName'] ?? '', '/authored-carousel')) $found[] = $block;
        $found = array_merge($found, $carousels($block['innerBlocks'] ?? array()));
    }
    return $found;
};
foreach (array('alone' => '', 'beside another carousel' => $other) as $label => $sibling) {
    $result = $compile($host($slide) . $sibling);
    $markup = $result['serialized_blocks'] ?? '';
    $assert(array() === ($result['fallbacks'] ?? array()), $label . ': static slide has no fallback');
    $assert(str_contains($markup, '<!-- wp:group'), $label . ': static content lowers to a group');
    foreach (array('a-desktop.png', 'a-mobile.png', '/collections/new') as $content) {
        $assert(str_contains($markup, $content), $label . ': retains ' . $content);
    }
    $converted = $carousels($result['blocks'] ?? array());
    $assert(count($converted) === ('' === $sibling ? 0 : 1), $label . ': only the multi-slide sibling is interactive');
    if ($converted) {
        $assert(2 === count($converted[0]['innerBlocks']), 'the sibling retains its two slides');
        $assert(!str_contains(json_encode($converted), 'a-desktop'), 'the sibling never takes the static host content');
    }
}
// The old document-wide lookup could turn this one-slide host into a copy of
// its two-slide sibling as soon as the host had a navigation control.
$controlled = $compile($host($slide, '<button aria-label="Next slide">Next</button>') . $other);
$assert(1 === count($carousels($controlled['blocks'] ?? array())), 'a controlled one-slide host never borrows sibling slides');
$renamed = $compile(str_replace(array('x-slides', 'slideshow carousel', 'track-slider', 'carousel__slide'), array('neutral-banner', 'gallery', 'rail', 'item'), $host($slide)));
$assert(array() === ($renamed['fallbacks'] ?? array()) && str_contains($renamed['serialized_blocks'] ?? '', 'a-mobile.png'), 'generic carousel structure does not depend on fixture class or tag names');
$multiple = $compile($host($slide . str_replace('is-selected', '', $slide)));
$assert(array() !== ($multiple['fallbacks'] ?? array()), 'multiple slides without controls do not take the static exception');
$empty = $compile($host(''));
$assert(array() !== ($empty['fallbacks'] ?? array()), 'an empty configured host still reports missing content');
$runtime = $compile($host($slide, '', 'onclick="advance()"'));
$assert(!str_contains($runtime['serialized_blocks'] ?? '', '<!-- wp:image'), 'event-owned content is not transparently lowered');
$directives = $compile($host(str_replace('class="slide-media"', 'class="slide-media" data-wp-interactive="demo"', $slide)));
$assert(!str_contains($directives['serialized_blocks'] ?? '', '<!-- wp:image'), 'descendant runtime directives prevent static lowering');

if ($failures) {
    fwrite(STDERR, 'FAIL: ' . implode("\nFAIL: ", $failures) . "\n");
    exit(1);
}
echo "Single-slide carousel static tests passed\n";
