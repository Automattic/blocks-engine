<?php
declare(strict_types=1);

// A Squarespace user-items list carousel captured mid-rotation, mirroring the
// real capture's structure: root container with the gutter region, slideshow
// holder and slides revealer around the slide list, desktop and mobile arrow
// pairs, and the live slide-status region. One exposed slide is parked at the
// -9999px sentinel ahead of the window, the window sits at one regular inline
// translate pitch, and the trailing slides (including the parked slide's
// hidden twin) are aria-hidden:
//   -9999  -339  0  339  678  1017  1356(H)  none(H)×4  -9999(H)
// The parked slide is not part of the measured window, so the track keeps its
// four-up view instead of collapsing to a one-up fade slideshow.
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

/** @param list<array{0: ?int, 1: bool}> $states offset (null for none) and whether the slide is hidden */
$fixture = static function (array $states, bool $arrowsInHolder = false) use ($desktopArrow, $mobileArrow, $style): string {
    $slides = '';
    foreach ($states as $index => [$offset, $hidden]) {
        $slides .= "<li class=\"\n                  user-items-list-carousel__slide list-item\n                  \n                \""
            . (null === $offset ? ' style=""' : ' style="transform: translate3d(' . $offset . 'px, 0px, 0px)"')
            . ' data-is-card-enabled="false"' . ($hidden ? ' aria-hidden="true"' : '') . '>'
            . '<div class="user-items-list-carousel__media-container" style="margin-bottom: 8%;width: 75%">'
            . '<div class="user-items-list-carousel__media-inner preScale scaleIn" data-media-aspect-ratio="original" data-animation-role="image">'
            . '<img src="/affiliation-logo-' . ($index + 1) . '.png" alt="Affiliation logo ' . ($index + 1) . '" loading="lazy"></div></div>'
            . '<div class="list-item-content"></div></li>';
    }
    return $style . '<section class="page-section"><div class="content-wrapper"><div class="content"><div class="user-items-list" data-section-id="user-items-list">'
        . '<div class=" user-items-list-item-container user-items-list-carousel " data-controller="UserItemsListCarousel" data-max-columns="4" data-show-adjacent-slides="false"'
        . ' data-space-between-slides-value="60" data-space-between-slides-unit="px" data-is-infinite-enabled="true" data-navigation-controls="arrows"'
        . ' data-navigation-placement="center" data-alignment-vertical="middle" data-layout-width="full" data-controllers-bound="UserItemsListCarousel">'
        . '<div class="user-items-list-carousel__gutter" role="region" aria-label="Carousel" tabindex="0">'
        . '<div class="user-items-list-carousel__slideshow-holder"><div class="user-items-list-carousel__slides-revealer">'
        . '<ul class="user-items-list-carousel__slides user-items-list-carousel__slides--initialized" style="grid-gap: 60px">' . $slides . '</ul></div>'
        . ($arrowsInHolder ? $desktopArrow('left') . $desktopArrow('right') . '</div>' : '</div>' . $desktopArrow('left') . $desktopArrow('right'))
        . '<div class="mobile-arrows">' . $mobileArrow('left') . $mobileArrow('right') . '</div>'
        . '<div aria-live="polite" aria-atomic="true" style="position: absolute;pointer-events: none;opacity: 0">Item 2 of 12</div></div>'
        . '</div></div></div></div></section>';
};

// The captured state: a parked exposed slide ahead of the window and its
// hidden twin at the end of the track.
$parkedState = array(array(-9999, false), array(-$pitch, false));
for ($step = 0; $step <= 3; ++$step) $parkedState[] = array($step * $pitch, false);
$parkedState[] = array(4 * $pitch, true);
while (count($parkedState) < 11) $parkedState[] = array(null, true);
$parkedState[] = array(-9999, true);

// The other rotation state (#7231): no parked slide is exposed.
$plainState = array();
for ($step = -1; $step <= 4; ++$step) $plainState[] = array($step * $pitch, false);
while (count($plainState) < 11) $plainState[] = array(null, true);
$plainState[] = array(-9999, true);

// The captured nesting puts the desktop arrows beside the slideshow holder;
// a variant with them inside it roots the carousel at the
// `__slideshow-holder`, whose name must not outweigh the measured window.
$cases = array(
    'parked' => $fixture($parkedState),
    'plain' => $fixture($plainState),
    'parked, holder-rooted' => $fixture($parkedState, true),
);
foreach ($cases as $label => $html) {
    $found = $carousels($html);
    $assert(1 === count($found), "{$label}: one authored carousel block");
    $attrs = $found[0]['attrs'] ?? array();
    $assert('track' === ($attrs['presentation'] ?? null), "{$label}: the slides move as a track, not a slideshow");
    $assert(4 === ($attrs['itemsPerView'] ?? null), "{$label}: the track shows four slides per view");
    $assert(12 === ($attrs['slideCount'] ?? null), "{$label}: every slide survives");
    $assert(str_contains((new Runtime())->serializeBlocks(array($found[0] ?? array())), 'blocks-engine-authored-carousel--items-4'), "{$label}: the root paints four slides per view");
}

if (array() !== $failures) {
    fwrite(STDERR, implode("\n", $failures) . "\n");
    exit(1);
}
fwrite(STDOUT, "list-carousel-parked-exposed-slide: passed\n");
