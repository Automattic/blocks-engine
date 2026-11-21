<?php
declare(strict_types=1);

// A translated list carousel captured mid-rotation: one exposed slide is
// parked at a far sentinel offset ahead of the window, the rest of the window
// sits at one regular inline translate pitch, and the trailing slides are
// aria-hidden. The parked slide is not part of the measured window, so the
// track keeps its four-up view instead of collapsing to a one-up slideshow.
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
$arrow = static fn (string $direction): string => '<button class="logo-carousel__arrow logo-carousel__arrow--' . $direction . '" aria-label="' . ('left' === $direction ? 'Previous' : 'Next') . '">'
    . '<svg viewBox="0 0 10 10"><path d="M' . ('left' === $direction ? '7 1L3 5l4 4' : '3 1l4 4-4 4') . '"/></svg></button>';
$style = '<style>.logo-carousel{position:relative}.logo-carousel__gutter{position:relative;overflow:hidden}'
    . '.logo-carousel__slides{margin:0;padding:0;display:grid;grid-template-columns:repeat(4,1fr);list-style-type:none}'
    . '.logo-carousel__slide{grid-row-start:1;grid-column-start:1;transform:translatex(-9999px)}</style>';

/** @param list<array{0: ?int, 1: bool}> $states offset (null for none) and whether the slide is hidden */
$fixture = static function (array $states) use ($arrow, $style): string {
    $slides = '';
    foreach ($states as $index => [$offset, $hidden]) {
        $slides .= '<li class="logo-carousel__slide"'
            . (null === $offset ? ' style=""' : ' style="transform: translate3d(' . $offset . 'px, 0px, 0px)"')
            . ($hidden ? ' aria-hidden="true"' : '')
            . '><img src="/logo-' . ($index + 1) . '.png" alt="Logo ' . ($index + 1) . '"></li>';
    }
    return $style . '<section><div class="logo-carousel"><div class="logo-carousel__gutter">'
        . '<ul class="logo-carousel__slides" style="grid-gap: 60px">' . $slides . '</ul></div>'
        . '<div class="logo-carousel__arrows">' . $arrow('left') . $arrow('right') . '</div></div></section>';
};

// Parked exposed slide ahead of the window: -9999 -339 0 339 678 1017 1356(H) none(H)… -9999(H).
$parkedState = array(array(-9999, false), array(-$pitch, false));
for ($step = 0; $step <= 3; ++$step) $parkedState[] = array($step * $pitch, false);
$parkedState[] = array(4 * $pitch, true);
while (count($parkedState) < 11) $parkedState[] = array(null, true);
$parkedState[] = array(-9999, true);

// The other rotation state: no parked slide is exposed.
$plainState = array();
for ($step = -1; $step <= 4; ++$step) $plainState[] = array($step * $pitch, false);
while (count($plainState) < 11) $plainState[] = array(null, true);
$plainState[] = array(-9999, true);

foreach (array('parked' => $parkedState, 'plain' => $plainState) as $label => $states) {
    $found = $carousels($fixture($states));
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
