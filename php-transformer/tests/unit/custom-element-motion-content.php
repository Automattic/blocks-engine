<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

// Reduced from #6075: incidental motion names must not hide editable light DOM.
$reveal = (string) file_get_contents(__DIR__ . '/fixtures/custom-element-scroll-reveal.html');
$cases = array(
    'scroll reveal (#6212)' => array($reveal, array('core/heading', 'core/paragraph', 'core/image')),
    'unconditional reveal' => array(str_replace('@media (prefers-reduced-motion: no-preference)', '@media all', $reveal), array('core/heading', 'core/paragraph', 'core/image')),
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

$result = (new HtmlTransformer())->transform($reveal)->toArray();
$markup = json_encode($result['blocks']);
foreach ( array('Item one', 'Item two', '$10.00', '$12.00', 'Description of the item.', 'one.jpg') as $content ) {
    if ( ! str_contains((string) $markup, $content) ) {
        fwrite(STDERR, "FAIL: reveal content lost: {$content}\n");
        exit(1);
    }
}

// Real motion surfaces must not become transparent, whether CSS is inline
// or supplied by an author stylesheet.
foreach ( array(
    str_replace('slideIn 0.6s ease', 'slideIn 0.6s ease infinite', $reveal),
    str_replace('slideIn 0.6s ease', 'slideIn 0.6s ease 2', $reveal),
    str_replace('to { transform: translateY(0);', 'to { transform: translateY(-100%);', $reveal),
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
