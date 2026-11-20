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

// The captured shape: the visible slides carry no aria-hidden at all while
// their siblings are aria-hidden="true". The rendered window is positioned by
// inline translate3d offsets on one pitch (a leading and a trailing buffer
// slide beside four on view), and the desktop arrows sit in separate side
// wrappers outside the clipping gutter.
$pitch = 339;
$windowSlides = '';
for ($index = 1; $index <= 12; ++$index) {
    $offset = $index <= 6 ? ($index - 2) * $pitch : null;
    $attributes = null !== $offset
        ? ' style="transform: translate3d(' . $offset . 'px, 0px, 0px)"'
        : (12 === $index ? ' style="transform: translate3d(-9999px, 0px, 0px)" aria-hidden="true"' : ' style="" aria-hidden="true"');
    $windowSlides .= '<li class="window-carousel__slide list-item"' . $attributes . '><div class="window-carousel__media-container">'
        . '<img src="/partner-' . $index . '.png" alt="Partner ' . $index . '"></div><div class="list-item-content"></div></li>';
}
$side = static fn (string $direction): string => '<div class="desktop-arrows window-carousel__arrow-wrapper window-carousel__arrow-wrapper--' . $direction . '">'
    . '<div class="window-carousel__arrow-container"><div class="window-carousel__arrow-icon-holder">' . $arrow('window-carousel__arrow-button', $direction) . '</div></div></div>';
$windowStyle = '<style>.window-carousel{position:relative}.window-carousel__gutter{position:relative;overflow:hidden}.window-carousel__slideshow-holder{position:relative;margin:0 auto}'
    . '.window-carousel__slides-revealer{overflow:hidden}.window-carousel__slides{margin:0;padding:0;display:grid;grid-template-columns:repeat(4,1fr);list-style-type:none}'
    . '.window-carousel__slide{grid-row-start:1;grid-column-start:1;transform:translatex(-9999px)}.mobile-arrows{display:none}@media(max-width:767px){.desktop-arrows{display:none}.mobile-arrows{display:flex}}</style>';
$windowFixture = static fn (string $controls): string => $windowStyle . '<section class="list-section"><div class="window-carousel">'
    . '<div class="window-carousel__gutter" role="region" aria-label="Carousel" tabindex="0"><div class="window-carousel__slideshow-holder"><div class="window-carousel__slides-revealer">'
    . '<ul class="window-carousel__slides" style="grid-gap: 60px">' . $windowSlides . '</ul></div></div></div>' . $controls . '</div></section>';

[$carousels, $galleries, $markup] = $transform($windowFixture($side('left') . $side('right') . $pair('mobile-arrows', 'mobile-arrow-button')));
$assert(array() === $galleries, 'window: the translated list carousel is not lowered to a gallery');
$assert(1 === count($carousels), 'window: one authored carousel block');
$attrs = $carousels[0]['attrs'] ?? array();
$assert('track' === ($attrs['presentation'] ?? null), 'window: the slides move as a track');
$assert(4 === ($attrs['itemsPerView'] ?? null), 'window: the track shows the four slides inside the window, not its buffer slides');
$assert(12 === ($attrs['slideCount'] ?? null), 'window: every slide survives');
$carouselMarkup = (new Runtime())->serializeBlocks(array($carousels[0] ?? array()));
$assert(str_contains($carouselMarkup, 'blocks-engine-authored-carousel--items-4'), 'window: the root paints four slides per view');
$assert(2 === substr_count($carouselMarkup, 'data-carousel-previous="true"') && 2 === substr_count($carouselMarkup, 'data-carousel-next="true"'), 'window: both the desktop and the mobile arrow pairs step the track');
$assert(str_contains($carouselMarkup, 'window-carousel__arrow-wrapper--left') && str_contains($carouselMarkup, 'window-carousel__arrow-wrapper--right'), 'window: the desktop side wrappers survive around their arrows');
$assert(!str_contains($markup, 'columns-'), 'window: no gallery grid');

// Four unlabeled controls that all join in one container are a pager, not
// breakpoint copies of a pair.
$pager = '<div class="list-carousel__pager">' . str_repeat('<button class="list-carousel__dot"></button>', 4) . '</div>';
[$carousels, $galleries] = $transform($fixture($pager, ''));
$assert(array() === $carousels && 1 === count($galleries), 'pager: four controls in one container keep the gallery path');

if (array() !== $failures) {
    fwrite(STDERR, implode("\n", $failures) . "\n");
    exit(1);
}
fwrite(STDOUT, "list-carousel-responsive-arrow-pairs: passed\n");
