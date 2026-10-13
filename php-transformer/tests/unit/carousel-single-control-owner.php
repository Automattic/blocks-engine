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
if ($failures) {
    fwrite(STDERR, "FAIL: " . implode("\nFAIL: ", $failures) . "\n");
    exit(1);
}
echo "Single-control carousel stays inside its own owner\n";
