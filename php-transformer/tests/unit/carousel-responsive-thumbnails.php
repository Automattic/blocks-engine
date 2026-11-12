<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Generators\AuthoredCarouselBlockGenerator;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

$assert = static function (bool $condition, string $message): void {
    if (!$condition) { fwrite(STDERR, 'FAIL: ' . $message . PHP_EOL); exit(1); }
};
$find = static function (array $blocks) use (&$find): ?array {
    foreach ($blocks as $block) {
        if ('custom/authored-carousel' === $block['blockName']) return $block;
        $found = $find($block['innerBlocks'] ?? array());
        if (null !== $found) return $found;
    }
    return null;
};
$markup = '<div data-x="y" class="product-gallery"><div class="wrap"><div class="rail">';
foreach (array('a', 'b', 'c') as $image) {
    $markup .= '<button><img src="' . $image . '.jpg" width="50"></button>';
}
$markup .= '</div></div><div class="slides">';
foreach (array('a', 'b', 'c') as $index => $image) {
    $markup .= '<div class="slide' . (0 === $index ? ' selected' : '') . '"><img src="' . $image . '.jpg" width="600" height="800"></div>';
}
$markup .= '</div></div>';
$baseCss = '.product-gallery{display:flex;gap:10px}.rail{display:flex;flex-direction:column}.slides{position:relative;aspect-ratio:3/4}.slide{display:none}.slide.selected{display:block}';
$generator = new AuthoredCarouselBlockGenerator();
$baseline = null;
foreach (array(
    'unchanged' => array('', null),
    'mobile-first wrapper' => array('.wrap{display:none}@media (min-width:768px){[data-x=y] .wrap{display:block}}', 768),
    'desktop-first rail' => array('@media (max-width:767px){.rail{display:none}}', 768),
    'always hidden' => array('.wrap{display:none}', null),
    'cascade overrides hide' => array('@media (max-width:767px){.rail{display:none}.rail{display:flex}}', null),
    'print only' => array('@media print and (max-width:767px){.rail{display:none}}', null),
    'root excluded' => array('@media (max-width:767px){.product-gallery{display:none}}', null),
) as $name => [$css, $expected]) {
    $result = (new HtmlTransformer())->transform('<style>' . $baseCss . $css . '</style>' . $markup)->toArray();
    $block = $find($result['blocks']);
    $css = implode("\n", array_map(
        static fn(array $asset): string => 'after-author' === ($asset['stylesheet_placement'] ?? '') ? (string) ($asset['content'] ?? '') : '',
        array_filter($result['assets'] ?? array(), 'is_array')
    ));
    $assert(null !== $block, $name . ': carousel detected');
    $attrs = $block['attrs'];
    $assert($expected === ($attrs['thumbnailsHiddenBelow'] ?? null), $name . ': breakpoint');
    if (null === $expected) {
        $assert(!array_key_exists('thumbnailsHiddenBelow', $attrs), $name . ': attribute absent');
        $assert(!str_contains($block['innerHTML'], 'data-carousel-thumbnails-hidden-below'), $name . ': no visibility output');
        $assert(!str_contains($css, 'data-carousel-thumbnails-hidden-below'), $name . ': no visibility rule');
        $without = $attrs;
        unset($without['thumbnailsHiddenBelow']);
        $assert($generator->shell($attrs) === $generator->shell($without), $name . ': shell byte-identical');
        if ('unchanged' === $name) $baseline = $block['innerHTML'];
        if ('always hidden' === $name) $assert($baseline === $block['innerHTML'], 'always hidden: output unchanged');
    } else {
        $assert(is_int($attrs['thumbnailsHiddenBelow']), $name . ': integer pixels');
        $assert(str_contains($block['innerHTML'], 'data-carousel-thumbnails-hidden-below="768"'), $name . ': root carries breakpoint');
        $assert(!str_contains($block['innerHTML'], '<style'), $name . ': no inline style element in saved markup');
        $root = '.blocks-engine-authored-carousel[data-carousel-thumbnails-hidden-below="768"]';
        foreach (array('@media (max-width:767px){' . $root . '{', $root . '>.blocks-engine-authored-carousel__thumbnails{display:none!important}', 'grid-column:1/-1!important', '--blocks-engine-carousel-stage-width:100%') as $fragment) {
            $assert(str_contains($css, $fragment), $name . ': generated stylesheet has ' . $fragment);
        }
        $assert('3/4' === $attrs['stageAspectRatio'], $name . ': stage ratio preserved');
    }
}
$definition = $generator->definition('custom');
$assert('integer' === $definition['block_json']['attributes']['thumbnailsHiddenBelow']['type'], 'typed schema');
$assert(str_contains($definition['assets']['index.js'], "props[ 'data-carousel-thumbnails-hidden-below' ]"), 'editor save carries breakpoint attribute');
$assert(!str_contains($definition['assets']['index.js'], "createElement( 'style'"), 'editor save emits no style element');
echo "Responsive carousel thumbnails regression passed\n";
