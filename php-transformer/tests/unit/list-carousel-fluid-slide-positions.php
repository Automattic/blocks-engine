<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

// Neutral captured list structure: gutter, slideshow holder, revealer, grid
// slides, desktop/mobile arrow pairs and a live status region. The first slide
// and five hidden parked slides get their transforms from learned capture CSS.
// Exposed positions: fluid:0, 0, 339, 678, 1017, then a parked -9999.
$fixture = static function (string $firstTransform, bool $fluid = true, bool $irregular = false): string {
    $slides = '';
    $rules = '';
    $offsets = array(0, 0, 339, 678, $irregular ? 1100 : 1017, 1356, -9999, -9999, -9999, -9999, -9999, -9999);
    foreach ($offsets as $index => $offset) {
        $transform = 0 === $index ? $firstTransform : "translate3d({$offset}px, 0px, 0px)";
        $learned = $fluid && (0 === $index || ($index >= 6 && $index <= 10));
        $segment = 'desktop-' . ($index + 6);
        if ($learned) {
            $rules .= ':is(#data-blocks-engine-capture-fluid-specificity, [data-blocks-engine-capture-fluid-segment="' . $segment . '"])'
                . '{transform:' . $transform . ' !important;}';
        }
        $slides .= '<li class="feature-carousel__slide list-item"'
            . ($learned ? ' data-blocks-engine-capture-fluid-segment="' . $segment . '" style="transform:translateX(-9999px)"' : ' style="transform:' . $transform . '"')
            . ($index >= 5 && $index <= 10 ? ' aria-hidden="true"' : '') . '>'
            . '<div class="feature-carousel__media-container"><div class="feature-carousel__media-inner">'
            . '<img src="/mark-' . $index . '.png" alt="Organization ' . $index . '"></div></div>'
            . '<div class="list-item-content"></div></li>';
    }
    $arrows = static fn (string $class): string => '<div class="' . $class . '">'
        . '<button aria-label="Previous"><svg viewBox="0 0 20 20"><path d="M15 2L5 10L15 18"/></svg></button>'
        . '<button aria-label="Next"><svg viewBox="0 0 20 20"><path d="M5 2L15 10L5 18"/></svg></button></div>';
    return '<html><head><style>'
        . '.feature-carousel__gutter{position:relative;overflow:hidden}'
        . '.feature-carousel__slideshow-holder{position:relative;margin:0 auto}'
        . '.feature-carousel__slides-revealer{overflow:hidden}'
        . '.feature-carousel__slides{display:grid;grid-template-columns:1fr;grid-template-rows:1fr;list-style:none;margin:0;padding:0}'
        . '.feature-carousel__slide{grid-area:1/1/2/2;display:flex;flex-direction:column;width:100%;min-width:0;transform:translateX(-9999px)}'
        . '.mobile-arrows{display:none}@media(max-width:575px){.desktop-arrows{display:none}.mobile-arrows{display:flex}}'
        . $rules . '</style></head><body><main><section class="feature-carousel">'
        . '<div class="feature-carousel__gutter" role="region" aria-label="Carousel" tabindex="0">'
        . '<div class="feature-carousel__slideshow-holder"><div class="feature-carousel__slides-revealer">'
        . '<ul class="feature-carousel__slides" style="gap:60px">' . $slides . '</ul></div></div>'
        . $arrows('desktop-arrows') . $arrows('mobile-arrows')
        . '<div aria-live="polite" aria-atomic="true" style="position:absolute;opacity:0">Item 2 of 12</div>'
        . '</div></section></main></body></html>';
};

$cases = array(
    'fluid duplicate' => array($fixture('translate(0px, 0px)'), true),
    'inline duplicate' => array($fixture('translate(0px, 0px)', false), true),
    'fluid transitional' => array($fixture('translate(125px, 0px)'), true),
    'irregular window' => array($fixture('translate(125px, 0px)', true, true), false),
);
foreach ($cases as $label => [$html, $track]) {
    $result = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array('index.html' => $html)))->toArray();
    $found = array();
    $visit = static function (array $values) use (&$visit, &$found): void {
        if ('custom/authored-carousel' === ($values['blockName'] ?? null)) $found[] = $values['attrs'];
        foreach ($values as $value) if (is_array($value)) $visit($value);
    };
    $visit($result);
    $attrs = $found[0] ?? array();
    if (1 !== count($found) || 12 !== ($attrs['slideCount'] ?? null)
        || ($track ? 4 : 1) !== ($attrs['itemsPerView'] ?? null)
        || ($track ? 'track' : 'slideshow') !== ($attrs['presentation'] ?? null)) {
        fwrite(STDERR, $label . ': unexpected carousel ' . json_encode($found) . "\n");
        exit(1);
    }
}
fwrite(STDOUT, "list-carousel-fluid-slide-positions: passed\n");
