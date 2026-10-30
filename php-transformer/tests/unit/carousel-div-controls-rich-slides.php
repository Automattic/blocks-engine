<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

$failures = array();
$assert = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) $failures[] = $message;
};
$compile = static function (string $body, string $css = ''): array {
    $html = '<!doctype html><html><head><title>Slides</title><style>' . $css . '</style></head><body><main>' . $body . '</main></body></html>';
    return (new ArtifactCompiler())->compile(['entrypoint' => 'index.html', 'files' => ['index.html' => $html]])->toArray();
};
$find = static function (array $blocks, string $name) use (&$find): array {
    $found = array();
    foreach ($blocks as $block) {
        if (str_ends_with($block['blockName'] ?? '', $name)) $found[] = $block;
        $found = array_merge($found, $find($block['innerBlocks'] ?? array(), $name));
    }
    return $found;
};
$cards = '';
$dots = '<ol class="page-dots">';
foreach (array('one', 'two', 'three') as $index => $title) {
    $n = $index + 1;
    $cards .= '<div class="carousel__slide"><a href="/p/' . $n . '"><img src="/' . $n . '.jpg" alt="' . $title . '"><img src="/' . $n . 'b.jpg" alt=""></a><h3><a href="/p/' . $n . '">Product ' . $title . '</a></h3><span class="price">$' . ($n * 10) . '</span></div>';
    $dots .= '<li class="dot' . (1 === $n ? ' is-selected' : '') . '" aria-label="' . $n . '"></li>';
}
$dots .= '</ol>';
$arrow = '<svg width="15" height="11"><path d="M14 5.5H2"/></svg>';
$arrows = '<div class="nav nav-prev" tabindex="0">' . $arrow . '</div><div class="nav nav-next" tabindex="0">' . $arrow . '</div>';
$host = static fn (string $items, string $controls): string => '<x-slides class="products carousel">' . $controls . '<div class="viewport"><div class="track-slider">' . $items . '</div></div></x-slides>';
$check = static function (array $result, string $label, bool $showDots) use ($assert, $find): void {
    $carousels = $find($result['blocks'] ?? array(), '/authored-carousel');
    $assert(array() === ($result['fallbacks'] ?? array()), $label . ': no fallbacks');
    $assert(1 === count($carousels), $label . ': one authored carousel');
    if (1 !== count($carousels)) return;
    $carousel = $carousels[0];
    $assert(3 === count($carousel['innerBlocks']), $label . ': three slides');
    $assert($showDots === ($carousel['attrs']['showDots'] ?? null), $label . ': numbered dots recognized');
    foreach ($carousel['innerBlocks'] as $index => $slide) {
        $n = $index + 1;
        $json = json_encode($slide, JSON_UNESCAPED_SLASHES);
        $assert('core/group' === $slide['blockName'], $label . ': rich slide is a group');
        $assert(2 === count($find(array($slide), 'core/image')), $label . ': both images retained');
        foreach (array('/p/' . $n, '/' . $n . '.jpg', '/' . $n . 'b.jpg', 'Product ' . array('one', 'two', 'three')[$index], '$' . ($n * 10)) as $content) {
            $assert(str_contains($json, $content), $label . ': retains ' . $content);
        }
        $assert(1 === count($find(array($slide), 'core/heading')), $label . ': title remains a heading');
    }
    $assert(array() === $find($result['blocks'], 'core/html'), $label . ': arrows never become HTML blocks');
};
$check($compile($host($cards, $arrows . $dots)), 'reduced fixture', true);
$check($compile($host($cards, $dots)), 'dots only', true);
$check($compile($host($cards, $arrows)), 'div arrows only', false);
// Neutral capture-shaped layout: positioned track/items, selected state and page CSS.
$capture = str_replace('class="carousel__slide"', 'class="column carousel__slide" style="position:absolute;left:0"', $cards);
$check($compile($host($capture, $arrows . $dots), '.viewport{overflow:hidden}.track-slider{position:relative;height:420px}.column{width:33.333%}.nav{cursor:pointer}.page-dots{display:flex}'), 'capture-shaped fixture', true);
// A single image plus linked text, and mixed image/video content, must also
// take the normal rich-child path rather than the image/caption shortcut.
foreach (array(
    'linked text' => str_replace(array('<img src="/1b.jpg" alt="">', '<img src="/2b.jpg" alt="">', '<img src="/3b.jpg" alt="">', '<h3>', '</h3>'), array('', '', '', '<p>', '</p>'), $cards),
    'video' => str_replace('<span class="price">$10</span>', '<span class="price">$10</span><video src="/demo.mp4" controls></video>', $cards),
) as $label => $items) {
    $result = $compile($host($items, $arrows));
    $carousels = $find($result['blocks'] ?? array(), '/authored-carousel');
    $assert(1 === count($carousels) && 3 === count($carousels[0]['innerBlocks']), $label . ': rich slides remain a carousel');
    $assert(array() === ($result['fallbacks'] ?? array()), $label . ': no fallbacks');
    if ($carousels) {
        $assert('core/group' === $carousels[0]['innerBlocks'][0]['blockName'], $label . ': retains a rich slide group');
        $assert(str_contains(json_encode($carousels, JSON_UNESCAPED_SLASHES), '/p/1'), $label . ': retains authored link');
    }
    if ('video' === $label) $assert(1 === count($find($result['blocks'] ?? array(), 'core/video')), 'mixed media retains native video');
}
$static = $compile('<x-slides class="slideshow carousel" data-autoplay="5000"><div class="viewport"><div class="stage-slider"><div class="carousel__slide"><img src="/hero.jpg" alt="Hero"><h2><a href="/offer">Offer</a></h2></div></div></div></x-slides>');
$assert(array() === ($static['fallbacks'] ?? array()), 'single-slide autoplay host has no fallback');
$assert(array() === $find($static['blocks'] ?? array(), '/authored-carousel'), 'single slide is static');
$assert(1 === count($find($static['blocks'] ?? array(), 'core/image')), 'static image remains native');
$assert(str_contains($static['serialized_blocks'] ?? '', '/offer'), 'static link retained');
$inert = $compile($host($cards, str_replace(' tabindex="0"', '', $arrows)));
$assert(array() === $find($inert['blocks'] ?? array(), '/authored-carousel'), 'inert directional divs are not controls');
$ordinary = $compile($host($cards, '<ol><li aria-label="1">First</li><li aria-label="2">Second</li></ol>'));
$assert(array() === $find($ordinary['blocks'] ?? array(), '/authored-carousel'), 'ordinary numbered list is not pagination');
$uncontrolled = $compile($host($cards, ''));
$assert(array() === $find($uncontrolled['blocks'] ?? array(), '/authored-carousel'), 'uncontrolled rich content does not invent interaction');
foreach (array('1.jpg', '2.jpg', '3.jpg') as $image) {
    $assert(str_contains(json_encode($uncontrolled), $image), 'uncontrolled content retains ' . $image);
}

if ($failures) {
    fwrite(STDERR, 'FAIL: ' . implode("\nFAIL: ", $failures) . "\n");
    exit(1);
}
echo "Carousel div controls and rich slides tests passed\n";
