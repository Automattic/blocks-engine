<?php
declare(strict_types=1);

// A gallery with a single "next" arrow sits beside authored sections inside a
// plain wrapper. The wrapper has no carousel identity of its own, so it must
// not become the carousel root and replace its sibling sections with slides.
require dirname(__DIR__, 2) . '/vendor/autoload.php';
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;
use Automattic\BlocksEngine\PhpTransformer\WordPress\Runtime;

$slides = '';
foreach (range(1, 4) as $index) {
    $slides .= '<div class="gallery-item-container"><img src="https://example.test/photo-' . $index . '.jpg" alt="" width="290" height="292"></div>';
}
$html = '<footer class="site-footer"><div class="footer-grid">'
    . '<div class="photo-strip"><div class="pro-gallery slider"><div class="gallery-horizontal-scroll" data-hook="gallery-column"><div class="gallery-horizontal-scroll-inner">' . $slides . '</div></div>'
    . '<button class="nav-arrows-container" aria-label="Next Item" data-hook="nav-arrow-next"><svg viewBox="0 0 10 10"><path d="M0 0L10 5L0 10Z"></path></svg></button></div></div>'
    . '<section class="newsletter"><h2>Join our newsletter</h2><p>Fresh news every month.</p></section>'
    . '<section class="contact"><p><a href="mailto:hello@example.test">hello@example.test</a></p><p>Copyright 2025 Example</p></section>'
    . '</div></footer>';

$result = (new HtmlTransformer())->transform($html)->toArray();
$markup = (new Runtime())->serializeBlocks($result['blocks']);
$carousels = array();
$visit = static function (array $blocks) use (&$visit, &$carousels): void {
    foreach ($blocks as $block) {
        if (str_ends_with((string) ($block['blockName'] ?? ''), '/authored-carousel')) $carousels[] = $block;
        $visit($block['innerBlocks'] ?? array());
    }
};
$visit($result['blocks']);

$failures = array();
$assert = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) $failures[] = $message;
};
$assert(str_contains($markup, 'Join our newsletter') && str_contains($markup, 'Fresh news every month.'), 'the newsletter section beside the gallery survives');
$assert(str_contains($markup, 'hello@example.test') && str_contains($markup, 'Copyright 2025 Example'), 'the contact section beside the gallery survives');
$assert(1 === count($carousels), 'the gallery itself still becomes one carousel');
foreach ($carousels as $carousel) {
    $assert(4 === count($carousel['innerBlocks']), 'the carousel keeps all four slides');
    $assert(!str_contains((new Runtime())->serializeBlocks(array($carousel)), 'Join our newsletter'), 'the carousel does not own the sibling sections');
}
// A section of only text links beside the gallery is authored navigation,
// not a carousel control, so it survives too.
$stage = '<div class="pro-gallery slider"><div class="gallery-horizontal-scroll" data-hook="gallery-column"><div class="gallery-horizontal-scroll-inner">' . $slides . '</div></div>'
    . '<button class="nav-arrows-container" aria-label="Next Item" data-hook="nav-arrow-next"><svg viewBox="0 0 10 10"><path d="M0 0L10 5L0 10Z"></path></svg></button></div>';
$transform = static fn (string $html): string => (new Runtime())->serializeBlocks((new HtmlTransformer())->transform($html)->toArray()['blocks']);
$links = $transform('<div class="footer-grid"><div class="photo-strip">' . $stage . '</div><nav class="footer-column"><a href="/all">View all</a> <a href="/shop">Shop</a></nav></div>');
$assert(str_contains($links, 'View all') && str_contains($links, 'Shop'), 'a text-links-only section beside the gallery survives');
$assert(1 === substr_count($links, 'class="blocks-engine-authored-carousel '), 'the gallery beside the text links is still one carousel');

// Pagers the carousel rebuilds are not authored content: a thumbnail strip or
// a slide-indexed dot list keeps the outer wrapper as the carousel root.
$pagers = array(
    'image thumbnail strip of the slides' => '<div class="thumbs">' . implode('', array_map(static fn (int $i): string => '<div class="thumb"><img src="https://example.test/photo-' . $i . '.jpg?w=40" alt="" width="40" height="40"></div>', range(1, 4))) . '</div>',
    'linked thumbnail strip' => '<div class="thumbs">' . implode('', array_map(static fn (int $i): string => '<a href="#"><img src="https://example.test/thumb-' . $i . '.jpg" alt="" width="40" height="40"></a>', range(1, 4))) . '</div>',
    'slide-indexed dot list' => '<ol class="dots">' . implode('', array_map(static fn (int $i): string => '<li data-slide-index="' . ($i - 1) . '">' . $i . '</li>', range(1, 4))) . '</ol>',
    'labelled dot buttons' => '<ol class="dots">' . implode('', array_map(static fn (int $i): string => '<li><button aria-label="Go to slide ' . $i . '">' . $i . '</button></li>', range(1, 4))) . '</ol>',
);
foreach ($pagers as $label => $pager) {
    $markup = $transform('<div class="carousel-frame">' . $stage . $pager . '</div>');
    $assert(1 === preg_match('/^\s*<!-- wp:[a-z0-9-]+\/authored-carousel /', $markup), 'the ' . $label . ' beside the stage keeps the outer wrapper as the carousel root');
}

// Repeated images that are not the slides are authored content: logos,
// figures, badges and unrelated thumbnails beside the stage survive.
$contents = array(
    'logo row' => array('<div class="partners"><img src="https://example.test/logo-a.png" alt="Partner A"><img src="https://example.test/logo-b.png" alt="Partner B"><img src="https://example.test/logo-c.png" alt="Partner C"></div>', 'Partner A'),
    'caption-less figure grid' => array('<div class="figure-grid"><figure><img src="https://example.test/g1.jpg" alt="Cake one"></figure><figure><img src="https://example.test/g2.jpg" alt="Cake two"></figure><figure><img src="https://example.test/g3.jpg" alt="Cake three"></figure></div>', 'Cake one'),
    'paragraph of badges' => array('<p><img src="https://example.test/a.png" alt="Badge A"><img src="https://example.test/b.png" alt="Badge B"></p>', 'Badge A'),
    'strip of other images' => array('<div class="thumbs">' . implode('', array_map(static fn (int $i): string => '<div class="thumb"><img src="https://example.test/other-' . $i . '.jpg" alt="Other ' . $i . '"></div>', range(1, 3))) . '</div>', 'Other 1'),
);
foreach ($contents as $label => [$content, $needle]) {
    $markup = $transform('<div class="footer-grid">' . $stage . $content . '</div>');
    $assert(str_contains($markup, $needle), 'a ' . $label . ' beside the gallery survives');
}

if ($failures) {
    fwrite(STDERR, "FAIL: " . implode("\nFAIL: ", $failures) . "\n");
    exit(1);
}
echo "Single-control carousel stays inside its own owner\n";
