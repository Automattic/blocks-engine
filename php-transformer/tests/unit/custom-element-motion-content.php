<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

// Reduced from #6075: incidental motion names must not hide editable light DOM.
$cases = array(
    'product modifier' => array(
        '<product-info><div class="product product--thumbnail_slider"><img src="https://example.com/product.jpg" alt="Example product" width="800" height="800"><h1>Example product</h1><p>A short product description.</p></div></product-info>',
        array('core/image', 'core/heading', 'core/paragraph'),
    ),
    'uncontrolled product row' => array(
        '<slider-component class="slider-mobile-gutter"><ul class="product-grid slider slider--tablet" role="list" aria-label="Carousel"><li class="slider__slide"><h3>Kit A</h3></li><li class="slider__slide"><h3>Kit B</h3></li></ul></slider-component>',
        array('core/heading'),
    ),
    'announcement carousel' => array(
        (string) file_get_contents(__DIR__ . '/fixtures/custom-element-announcement-carousel.html'),
        array('custom/authored-carousel', 'core/paragraph'),
    ),
);
foreach ( $cases as $name => [$html, $expected] ) {
    $result = (new HtmlTransformer())->transform($html)->toArray();
    $names = array();
    $walk = static function (array $blocks) use (&$walk, &$names): void {
        foreach ( $blocks as $block ) {
            $names[] = $block['blockName'] ?? '';
            $walk($block['innerBlocks'] ?? array());
        }
    };
    $walk($result['blocks'] ?? array());
    if ( array() !== ($result['fallbacks'] ?? array()) || in_array('core/html', $names, true) || array_diff($expected, $names) ) {
        fwrite(STDERR, "FAIL: {$name}: expected editable content without fallbacks\n" . json_encode($result) . "\n");
        exit(1);
    }
}

// Real motion surfaces must not become transparent, whether CSS is inline
// or supplied by an author stylesheet.
foreach ( array(
    '<motion-widget><div class="slider" style="transform:translateX(-100%);overflow:hidden"><p>Runtime slide</p></div></motion-widget>',
    '<style>.slider { animation: advance 5s infinite; } @keyframes advance { to { transform:translateX(-100%); } }</style><motion-widget><div class="slider"><p>Runtime slide</p></div></motion-widget>',
) as $html ) {
    $result = (new HtmlTransformer())->transform($html)->toArray();
    if ( array() === ($result['fallbacks'] ?? array()) ) {
        fwrite(STDERR, "FAIL: runtime motion must still refuse\n");
        exit(1);
    }
}
echo "Custom element motion content tests passed\n";
