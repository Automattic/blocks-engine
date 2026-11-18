<?php
declare(strict_types=1);

// A jQuery FlexSlider slideshow: a `flexslider` root holding `ul.slides`, an
// `ol.flex-control-paging` of numbered `#` links (one per slide) and captured
// empty previous/next items. It is an authored carousel: the slides rotate,
// slide 1 stays the initial slide, each slide link survives and the numbered
// links become carousel pagination instead of a dead `#` navigation.
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
    $navigations = array();
    $visit = static function (array $blocks) use (&$visit, &$carousels, &$navigations): void {
        foreach ($blocks as $block) {
            $name = (string) ($block['blockName'] ?? '');
            if (str_ends_with($name, '/authored-carousel')) $carousels[] = $block;
            if ('core/navigation' === $name) $navigations[] = $block;
            $visit($block['innerBlocks'] ?? array());
        }
    };
    $visit($blocks);
    return array($carousels, $navigations, (new Runtime())->serializeBlocks($blocks));
};

$slide = static fn (string $opacity, string $zIndex, string $inner, string $class = ''): string => '<li style="float:left;margin-right:-100%;position:relative;width:100%;display:block;opacity:' . $opacity . ';z-index:' . $zIndex . '"' . ('' === $class ? '' : ' class="' . $class . '"') . '>' . $inner . '</li>';
$slides = $slide('1', '2', '<img src="/a.png" alt="">', 'flex-active-slide')
    . $slide('0', '1', '<a href="/n/1/"><img src="/b.png" alt=""></a>')
    . $slide('0', '1', '<a href="/n/2/"><img src="/c.png" alt=""></a>');
$paging = static fn (int $count): string => '<ol class="flex-control-nav flex-control-paging">'
    . implode('', array_map(static fn (int $index): string => '<li><a href="#"' . (1 === $index ? ' class="flex-active"' : '') . '>' . $index . '</a></li>', range(1, $count)))
    . '</ol>';
$directionNav = '<ul class="flex-direction-nav"><li class="flex-nav-prev"></li><li class="flex-nav-next"></li></ul>';

// The issue fixture.
[$carousels, $navigations, $markup] = $transform('<div class="flexslider"><ul class="slides">' . $slides . '</ul>' . $paging(3) . $directionNav . '</div>');
$assert(1 === count($carousels), 'fixture: one authored carousel block');
$carousel = $carousels[0] ?? array();
$attrs = $carousel['attrs'] ?? array();
$assert(3 === count($carousel['innerBlocks'] ?? array()), 'fixture: the carousel holds three slides');
$assert(3 === ($attrs['slideCount'] ?? null), 'fixture: slideCount is 3');
$assert(true === ($attrs['showDots'] ?? null), 'fixture: the carousel paints pagination');
$assert(0 === ($attrs['initialSlide'] ?? 0), 'fixture: slide 1 is the initial slide');
$assert('slideshow' === ($attrs['presentation'] ?? null) && 1 === ($attrs['itemsPerView'] ?? null), 'fixture: cross-faded frames show one slide at a time');
$carouselMarkup = (new Runtime())->serializeBlocks(array($carousel));
foreach (array('/a.png', '/b.png', '/c.png') as $image) {
    $assert(str_contains($carouselMarkup, $image), "fixture: slide image {$image} survives in the carousel");
}
foreach (array('/n/1/', '/n/2/') as $link) {
    $assert(str_contains($carouselMarkup, 'href="' . $link . '"'), "fixture: slide link {$link} survives in the carousel");
}
$assert(3 === substr_count($carouselMarkup, 'data-carousel-index='), 'fixture: pagination has one control per slide');
$assert(array() === $navigations, 'fixture: no leftover core/navigation of numbered links');
$assert(!str_contains($markup, 'href="#"'), 'fixture: no dead # links survive');

// A `slides` list (and `flex` classes) without slider identity is content.
[$carousels] = $transform('<div class="flex-container"><ul class="slides">' . $slides . '</ul>' . $directionNav . '</div>');
$assert(array() === $carousels, 'negative: ul.slides without slider identity is not a carousel');
[$carousels] = $transform('<div class="flex"><ul class="slides"><li>Plan the trip with the team.</li><li>Book the venue for the event.</li><li>Send the invitations to guests.</li></ul>' . $paging(3) . '</div>');
$assert(array() === $carousels, 'negative: a slides list with numbered links but no slider identity is not a carousel');

// Numbered page links whose count differs from the slide count are not
// carousel pagination, even beside a slide list in a slider root.
[$carousels, , $markup] = $transform('<div class="flexslider"><ul class="slides">' . $slides . '</ul>' . $paging(5) . '</div>');
$assert(array() === $carousels, 'negative: numbered pagination for 5 is not pagination for 3 slides');
$assert(str_contains($markup, '/b.png') && str_contains($markup, '/n/2/'), 'negative: the slide content survives as content');

if (array() !== $failures) {
    fwrite(STDERR, implode("\n", $failures) . "\n");
    exit(1);
}
fwrite(STDOUT, "flexslider-authored-carousel: passed\n");
