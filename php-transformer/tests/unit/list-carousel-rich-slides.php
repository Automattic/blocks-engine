<?php
declare(strict_types=1);

// A Squarespace user-items list carousel whose slides hold an image, a heading,
// a description and a button. The list sits in a `__slides-revealer` wrapper,
// whose name reads as a list and whose only child then reads as one slide. The
// infinite source also appends a copy of every slide, positioned over the
// originals and not aria-hidden.
require dirname(__DIR__, 2) . '/vendor/autoload.php';
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;
use Automattic\BlocksEngine\PhpTransformer\WordPress\Runtime;

$failures = array();
$assert = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) $failures[] = $message;
};

/** @return list<array<string, mixed>> */
$carousels = static function (string $html): array {
    $blocks = (new HtmlTransformer())->transform($html)->toArray()['blocks'];
    $found = array();
    $visit = static function (array $blocks) use (&$visit, &$found): void {
        foreach ($blocks as $block) {
            if (str_ends_with((string) ($block['blockName'] ?? ''), '/authored-carousel')) $found[] = $block;
            $visit($block['innerBlocks'] ?? array());
        }
    };
    $visit($blocks);
    return $found;
};

$pitch = 339;
$icon = static fn (string $direction): string => '<svg class="user-items-list-carousel__arrow-icon" viewBox="0 0 44 18" xmlns="http://www.w3.org/2000/svg">'
    . '<path class="user-items-list-carousel__arrow-icon-foreground user-items-list-carousel__arrow-icon-path" d="' . ('left' === $direction ? 'M9.90649 16.96L2.1221 9.17556L9.9065 1.39116' : 'M34.1477 1.39111L41.9321 9.17551L34.1477 16.9599') . '" stroke-width="2" fill="none"></path></svg>';
$desktopArrow = static fn (string $direction): string => '<div class="desktop-arrows user-items-list-carousel__arrow-wrapper user-items-list-carousel__arrow-wrapper--' . $direction . '" style="grid-gap: 60px;margin-' . $direction . ': 2vw">'
    . '<div class="user-items-list-carousel__arrow-container" style=""><div class="user-items-list-carousel__arrow-positioner" data-media-aspect-ratio="original"><div class="user-items-list-carousel__arrow-icon-holder">'
    . '<button class="user-items-list-carousel__arrow-button user-items-list-carousel__arrow-button--' . $direction . ' preFade fadeIn" aria-label=" ' . ('left' === $direction ? 'Previous' : 'Next') . ' " data-animation-role="button">'
    . '<div class=" user-items-list-carousel__arrow-icon-background user-items-list-carousel__arrow-icon-background-area "></div>' . $icon($direction) . '</button></div></div></div></div>';
$mobileArrow = static fn (string $direction): string => '<button class="mobile-arrow-button mobile-arrow-button--' . $direction . ' preFade" aria-label=" ' . ('left' === $direction ? 'Previous' : 'Next') . ' " data-animation-role="button">'
    . '<div class=" user-items-list-carousel__arrow-icon-background mobile-arrow-icon-background-area "></div>' . $icon($direction) . '</button>';
$style = '<style>.user-items-list-carousel__gutter{position:relative;overflow:hidden;cursor:grab}'
    . '.user-items-list-carousel__slideshow-holder{position:relative;margin:0 auto}'
    . '.user-items-list-carousel[data-show-adjacent-slides="false"] .user-items-list-carousel__slides-revealer{overflow:hidden}'
    . '.user-items-list-carousel__slides{margin:0px;padding:0px;width:100%;box-sizing:border-box;display:grid;grid-template-rows:1fr;grid-template-columns:1fr;list-style-type:none;opacity:0;transition:opacity .3s}'
    . '.user-items-list-carousel__slides--initialized{opacity:1}'
    . '.user-items-list-carousel__slide{box-sizing:border-box;grid-row-start:1;grid-row-end:2;grid-column-start:1;grid-column-end:2;width:100%;min-width:0;display:flex;flex-direction:column;transform:translatex(-9999px);pointer-events:none;user-select:none}'
    . '.user-items-list-carousel[data-alignment-vertical="middle"] .user-items-list-carousel__slide{align-self:center}'
    . '@media screen and (min-width:1100px){.user-items-list-carousel[data-max-columns="4"] .user-items-list-carousel__slides,.user-items-list-carousel[data-max-columns="4"] .user-items-list-carousel__arrow-wrapper{grid-template-columns:repeat(4,1fr)}}'
    . '.user-items-list-carousel__arrow-wrapper{pointer-events:none;position:absolute;top:0px;height:100%;box-sizing:border-box;display:grid;grid-template-rows:1fr;grid-template-columns:1fr}'
    . '.user-items-list-carousel__arrow-wrapper--left{left:0px}.user-items-list-carousel__arrow-wrapper--right{right:0px}'
    . '.user-items-list-carousel__arrow-button{pointer-events:auto;cursor:pointer;display:block;border:none;border-radius:50%;overflow:hidden;width:60px;height:60px;margin:0;padding:0;position:relative;background:transparent;z-index:1}'
    . '.user-items-list-carousel .mobile-arrows{display:none;justify-content:center;margin-top:20px}'
    . '.user-items-list-carousel .mobile-arrow-button{pointer-events:auto;cursor:pointer;display:block;border:none;border-radius:50%;overflow:hidden;width:48px;height:48px;margin:0;padding:0;position:relative;background:transparent;z-index:1}'
    . '@media screen and (max-width:575px){.user-items-list-carousel .desktop-arrows{display:none}.user-items-list-carousel .mobile-arrows{display:flex}}</style>';

$fixture = static function (int $copies, bool $wrapped = true) use ($desktopArrow, $mobileArrow, $style): string {
    $slides = '';
    for ($index = 0; $index < 4 * (1 + $copies); ++$index) {
        $item = $index % 4 + 1;
        $offset = 339 * ($index < 4 ? $index : $index - 5);
        $slides .= '<li class="user-items-list-carousel__slide list-item" style="transform: translate3d(' . $offset . 'px, 0px, 0px)" data-is-card-enabled="false">'
            . '<div class="user-items-list-carousel__media-container"><div class="user-items-list-carousel__media-inner"><img src="/cover-' . $item . '.png" alt="Cover ' . $item . '"></div></div>'
            . '<div class="list-item-content"><div class="list-item-content__text-wrapper"><h2 class="list-item-content__title">Item ' . $item . '</h2><div class="list-item-content__description"><p>Description ' . $item . '.</p></div></div>'
            . '<div class="list-item-content__button-wrapper"><a class="list-item-content__button" href="https://example.com/' . $item . '">Buy item ' . $item . '</a></div></div></li>';
    }
    $list = '<ul class="user-items-list-carousel__slides user-items-list-carousel__slides--initialized" style="grid-gap: 60px">' . $slides . '</ul>';
    return $style . '<section class="page-section"><div class="content-wrapper"><div class="content"><div class="user-items-list">'
        . '<div class="user-items-list-item-container user-items-list-carousel" data-max-columns="4" data-show-adjacent-slides="false" data-is-infinite-enabled="true">'
        . '<div class="user-items-list-carousel__gutter" role="region" aria-label="Carousel" tabindex="0"><div class="user-items-list-carousel__slideshow-holder">'
        . ($wrapped ? '<div class="user-items-list-carousel__slides-revealer">' . $list . '</div>' : $list) . '</div>'
        . $desktopArrow('left') . $desktopArrow('right') . '<div class="mobile-arrows">' . $mobileArrow('left') . $mobileArrow('right') . '</div>'
        . '</div></div></div></div></div></section>';
};

foreach (array('served slides' => $fixture(0), 'loop copies' => $fixture(1), 'unwrapped list' => $fixture(1, false)) as $label => $html) {
    $found = $carousels($html);
    $assert(1 === count($found), "{$label}: one authored carousel block");
    $markup = (new Runtime())->serializeBlocks($found);
    $assert(4 === ($found[0]['attrs']['slideCount'] ?? null), "{$label}: one slide per item");
    foreach (array(1, 2, 3, 4) as $item) {
        $assert(1 === substr_count($markup, '>Item ' . $item . '<'), "{$label}: item {$item} appears once");
        $assert(1 === substr_count($markup, 'href="https://example.com/' . $item . '"'), "{$label}: item {$item} keeps its link once");
    }
}

if (array() !== $failures) {
    fwrite(STDERR, implode("\n", $failures) . "\n");
    exit(1);
}
fwrite(STDOUT, "list-carousel-rich-slides: passed\n");
