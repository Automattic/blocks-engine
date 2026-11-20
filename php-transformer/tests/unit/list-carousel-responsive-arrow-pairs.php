<?php
declare(strict_types=1);

// A list carousel: an overflow-hidden gutter clips a row of fixed-width
// slides, four exposed and the rest aria-hidden, stepped by a desktop arrow
// pair inside the gutter and a mobile arrow pair beside it. It is an authored
// track carousel that keeps the visible slide count, the clipping gutter and
// the controls. It is not an image gallery that shows every slide at once in
// a wrapping grid.
require dirname(__DIR__, 2) . '/vendor/autoload.php';
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;
use Automattic\BlocksEngine\PhpTransformer\WordPress\Runtime;

$failures = array();
$assert = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) $failures[] = $message;
};

/** @return array{0: list<array<string, mixed>>, 1: list<array<string, mixed>>, 2: string} */
$transform = static function (string $html): array {
    $blocks = (new HtmlTransformer())->transform($html)->toArray()['blocks'];
    $carousels = array();
    $galleries = array();
    $visit = static function (array $blocks) use (&$visit, &$carousels, &$galleries): void {
        foreach ($blocks as $block) {
            $name = (string) ($block['blockName'] ?? '');
            if (str_ends_with($name, '/authored-carousel')) $carousels[] = $block;
            if ('core/gallery' === $name) $galleries[] = $block;
            $visit($block['innerBlocks'] ?? array());
        }
    };
    $visit($blocks);
    return array($carousels, $galleries, (new Runtime())->serializeBlocks($blocks));
};

$slideCount = 8;
$visible = 4;
$slides = '';
for ($index = 1; $index <= $slideCount; ++$index) {
    $slides .= '<li class="list-carousel__slide" aria-hidden="' . ($index <= $visible ? 'false' : 'true') . '">'
        . '<div class="list-carousel__media"><img src="/logo-' . $index . '.png" alt="Logo ' . $index . '"></div></li>';
}
$arrow = static fn (string $class, string $direction): string => '<button class="' . $class . ' ' . $class . '--' . $direction . '" aria-label="' . ('left' === $direction ? 'Previous' : 'Next') . '">'
    . '<svg viewBox="0 0 10 10"><path d="M' . ('left' === $direction ? '7 1L3 5l4 4' : '3 1l4 4-4 4') . '"/></svg></button>';
$pair = static fn (string $wrapper, string $class): string => '<div class="' . $wrapper . '">' . $arrow($class, 'left') . $arrow($class, 'right') . '</div>';
$style = '<style>.list-carousel__gutter{overflow:hidden;padding:0 72px}.list-carousel__slides{display:flex;gap:24px;margin:0;padding:0;list-style:none}'
    . '.list-carousel__slide{flex:0 0 279px;width:279px}.mobile-arrows{display:none}@media(max-width:767px){.list-carousel__arrow-wrapper{display:none}.mobile-arrows{display:flex}}</style>';
$fixture = static fn (string $controls, string $outside): string => $style . '<section class="list-section"><div class="list-carousel">'
    . '<div class="list-carousel__gutter"><div class="list-carousel__slideshow-holder"><ul class="list-carousel__slides">' . $slides . '</ul></div>' . $controls . '</div>'
    . $outside . '</div></section>';

// The issue fixture: a desktop pair and a mobile pair.
[$carousels, $galleries, $markup] = $transform($fixture($pair('list-carousel__arrow-wrapper', 'list-carousel__arrow'), $pair('mobile-arrows', 'mobile-arrow')));
$assert(array() === $galleries, 'fixture: the list carousel is not lowered to a gallery');
$assert(1 === count($carousels), 'fixture: one authored carousel block');
$carousel = $carousels[0] ?? array();
$attrs = $carousel['attrs'] ?? array();
$assert('track' === ($attrs['presentation'] ?? null), 'fixture: the slides move as a track');
$assert($visible === ($attrs['itemsPerView'] ?? null), 'fixture: the track shows the source\'s four visible slides');
$assert($slideCount === ($attrs['slideCount'] ?? null) && $slideCount === count($carousel['innerBlocks'] ?? array()), 'fixture: every slide survives');
$carouselMarkup = (new Runtime())->serializeBlocks(array($carousel));
$assert(str_contains($carouselMarkup, 'blocks-engine-authored-carousel--items-' . $visible), 'fixture: the root paints four slides per view');
$assert(str_contains($carouselMarkup, 'list-carousel__gutter'), 'fixture: the clipping gutter survives');
$assert(str_contains($carouselMarkup, 'data-carousel-previous="true"') && str_contains($carouselMarkup, 'data-carousel-next="true"'), 'fixture: the previous/next controls survive');
for ($index = 1; $index <= $slideCount; ++$index) {
    $assert(str_contains($carouselMarkup, '/logo-' . $index . '.png'), "fixture: slide {$index} survives in the carousel");
}
$assert(!str_contains($markup, 'columns-8'), 'fixture: no eight-column grid');

// One pair keeps the same track.
[$carousels, $galleries] = $transform($fixture($pair('list-carousel__arrow-wrapper', 'list-carousel__arrow'), ''));
$assert(array() === $galleries && 1 === count($carousels), 'single pair: one authored carousel');
$assert($visible === ($carousels[0]['attrs']['itemsPerView'] ?? null), 'single pair: four slides per view');

// Without stepping controls the collection keeps the gallery path.
[$carousels, $galleries] = $transform($fixture('', ''));
$assert(array() === $carousels && 1 === count($galleries), 'negative: an uncontrolled collection stays a gallery');

if (array() !== $failures) {
    fwrite(STDERR, implode("\n", $failures) . "\n");
    exit(1);
}
fwrite(STDOUT, "list-carousel-responsive-arrow-pairs: passed\n");
