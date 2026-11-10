<?php
declare(strict_types=1);

// Each carousel slide is an absolutely offset box holding a <picture> image
// that an attribute-scoped author rule positions inside that box. The slide is
// flattened to a bare core/image in the carousel track, so the slide box is
// gone. The rule must not follow the image into the track, where every image
// would resolve against the shared track origin and the slides would stack.
require dirname(__DIR__, 2) . '/vendor/autoload.php';
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

$slides = '';
foreach (array(0, 462, 924, 1386) as $index => $left) {
    $slides .= '<div class="slide" style="left:' . $left . 'px"><picture><img class="item" src="https://example.test/slide-' . ($index + 1) . '.png" alt="Slide ' . ($index + 1) . '" style="width:442px;height:589px"></picture></div>';
}
$html = '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><title>Carousel repro</title><style>'
    . 'body{margin:0}.gallery{position:relative;margin:40px;height:589px;overflow:hidden}.track{position:relative}'
    . '.slide{position:absolute;top:0;width:442px;height:589px;overflow:hidden}'
    . '[data-slider] .slide .item{position:absolute;top:0;left:0}'
    . '.arrow{position:absolute;right:33px;top:calc(50% - 37px);width:75px;height:75px;border:0;background:#333;color:#fff}'
    . '</style></head><body><div class="gallery" data-slider><div class="track">' . $slides . '</div>'
    . '<button class="arrow" aria-label="Next">&rarr;</button></div></body></html>';

$result = (new HtmlTransformer())->transform($html)->toArray();
$css = implode("\n", array_map(static fn (array $asset): string => (string) ($asset['content'] ?? ''), array_filter($result['assets'], static fn (array $asset): bool => 'css' === ($asset['kind'] ?? ''))));

// Selector arms that position an element absolutely, reduced to the classes
// they require (negations ignored).
$positioningArms = array();
preg_match_all('/([^{}]+)\{([^{}]*)\}/', $css, $rules, PREG_SET_ORDER);
foreach ($rules as $rule) {
    if (!preg_match('/(?:^|;)\s*position\s*:\s*absolute/i', $rule[2])) continue;
    foreach (explode(',', $rule[1]) as $arm) {
        preg_match_all('/\.([A-Za-z0-9_-]+)/', (string) preg_replace('/:not\([^)]*\)/', '', $arm), $classes);
        if (array() !== $classes[1]) $positioningArms[trim($arm)] = array_unique($classes[1]);
    }
}

$images = array();
$visit = static function (array $blocks) use (&$visit, &$images): void {
    foreach ($blocks as $block) {
        if ('core/image' === ($block['blockName'] ?? null)) $images[] = $block;
        $visit($block['innerBlocks'] ?? array());
    }
};
$visit($result['blocks']);

$failures = array();
if (4 !== count($images)) $failures[] = 'the carousel keeps all four slide images';
foreach ($images as $index => $image) {
    preg_match('/<figure class="([^"]*)"/', (string) ($image['innerHTML'] ?? ''), $figure);
    $classes = preg_split('/\s+/', $figure[1] ?? '') ?: array();
    foreach ($positioningArms as $arm => $required) {
        if (array() === array_diff($required, $classes)) {
            $failures[] = 'slide ' . ($index + 1) . ' image is positioned against the track by `' . $arm . '`';
        }
    }
}

if ($failures) {
    fwrite(STDERR, "FAIL: " . implode("\nFAIL: ", array_unique($failures)) . "\n");
    exit(1);
}
echo "Flattened picture slides do not carry their dropped slide box positioning\n";
