<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

$assert = static function (bool $condition, string $message): void {
    if (!$condition) { fwrite(STDERR, 'FAIL: ' . $message . PHP_EOL); exit(1); }
};
foreach (array('row' => 'left', 'row-reverse' => 'right', 'column-reverse' => 'bottom', 'column' => 'top') as $direction => $position) {
    $html = '<style>
.product-gallery{display:flex;gap:10px;width:var(--product-gallery-width);flex-direction:' . $direction . '}
.product-gallery-thumbnails{display:flex;flex-direction:column;gap:10px}
.product-gallery-thumbnails-item{width:50px}
.product-gallery-slides{position:relative;flex-grow:1;aspect-ratio:var(--product-gallery-aspect-ratio)}
.product-gallery-slide{display:none}.product-gallery-slide.selected{display:block}
</style><div style="--product-gallery-aspect-ratio: 3 / 4;--product-gallery-width:50%"><div class="product-gallery"><div class="product-gallery-scroll"><div class="product-gallery-thumbnails">';
    foreach (array('a', 'b', 'c') as $image) {
        $html .= '<button class="product-gallery-thumbnails-item"><img src="' . $image . '.jpg" width="50"></button>';
    }
    $html .= '</div></div><div class="product-gallery-slides">';
    foreach (array('a', 'b', 'c') as $index => $image) {
        $html .= '<div class="product-gallery-slide' . (0 === $index ? ' selected' : '') . '"><img src="' . $image . '.jpg" width="600" height="800"></div>';
    }
    $result = (new HtmlTransformer())->transform($html . '</div></div></div>')->toArray();
    $find = static function (array $blocks) use (&$find): ?array {
        foreach ($blocks as $block) {
            if ('custom/authored-carousel' === $block['blockName']) return $block;
            $found = $find($block['innerBlocks'] ?? array());
            if (null !== $found) return $found;
        }
        return null;
    };
    $block = $find($result['blocks']);
    $assert(null !== $block, $direction . ': carousel detected');
    foreach (array('thumbnailPosition' => $position, 'thumbnailWidth' => '50px', 'thumbnailGap' => '10px', 'stageGap' => '10px', 'stageAspectRatio' => '3/4') as $attribute => $expected) {
        $assert($expected === ($block['attrs'][$attribute] ?? null), $direction . ': ' . $attribute . ' = ' . $expected);
    }
    $assert(3 === count($block['attrs']['thumbnails']) && 3 === count($block['innerBlocks']), 'three thumbnails and slides');
    $assert(str_contains($block['innerHTML'], '--thumbnails-' . $position), $direction . ': rendered placement modifier');
    foreach (array('thumbnail-size:50px', 'thumbnail-gap:10px', 'gap:10px', 'stage-aspect:3/4') as $declaration) {
        $assert(str_contains($block['innerHTML'], '--blocks-engine-carousel-' . $declaration), 'rendered ' . $declaration);
    }
}
echo "Cascade thumbnail carousel regression passed\n";
