<?php
declare(strict_types=1);

// A post listing holds full posts, one of which embeds a small image
// slideshow, and ends with an "<<Previous" link to the next listing page.
// That link navigates to another document, so it is page navigation, not a
// slideshow control. Counting it as one made the whole listing the carousel
// root and replaced every post with a slide of the embedded slideshow.
require dirname(__DIR__, 2) . '/vendor/autoload.php';
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;
use Automattic\BlocksEngine\PhpTransformer\WordPress\Runtime;

$slides = '';
foreach (range(1, 3) as $index) {
    $slides .= '<li class="slide"><img src="https://example.test/photo-' . $index . '.jpg" alt="Photo ' . $index . '" width="600" height="400"></li>';
}
$post = static fn (string $title, string $body): string => '<div class="post-item"><h2 class="post-title"><a href="/news/' . strtolower(str_replace(' ', '-', $title)) . '/">' . $title . '</a></h2>'
    . '<p class="post-date">4/30/2024</p><div class="post-content">' . $body . '</div></div>';
$listing = static fn (string $navigation): string => '<div id="listing-content">'
    . $post('First Update', '<p>The first update explains the new building plans in detail.</p>')
    . $post('Photo Report', '<p>Photos from the distribution week.</p><div id="gallery-1-slideshow" class="slideshow"><ul class="slideshow-slides">' . $slides . '</ul></div>')
    . $post('Third Update', '<p>The third update thanks every volunteer for their help.</p>')
    . $navigation
    . '</div>';

$transform = static function (string $html): array {
    $blocks = (new HtmlTransformer())->transform($html)->toArray()['blocks'];
    $carousels = array();
    $visit = static function (array $blocks) use (&$visit, &$carousels): void {
        foreach ($blocks as $block) {
            if (str_ends_with((string) ($block['blockName'] ?? ''), '/authored-carousel')) $carousels[] = (new Runtime())->serializeBlocks(array($block));
            $visit($block['innerBlocks'] ?? array());
        }
    };
    $visit($blocks);
    return array((new Runtime())->serializeBlocks($blocks), $carousels);
};

$failures = array();
$assert = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) $failures[] = $message;
};
foreach (array(
    'absolute' => '<div class="page-nav-previous"><a href="https://example.test/news/previous/2" class="post-link">&lt;&lt;Previous</a></div>',
    'relative' => '<div class="page-nav"><a href="/news/page/2/">Next</a></div>',
) as $case => $navigation) {
    [$markup, $carousels] = $transform($listing($navigation));
    foreach (array('The first update explains the new building plans in detail.', 'The third update thanks every volunteer for their help.', 'Photos from the distribution week.') as $text) {
        $assert(str_contains($markup, $text), "{$case}: listing post text survives: {$text}");
    }
    foreach ($carousels as $carousel) {
        $assert(!str_contains($carousel, 'First Update') && !str_contains($carousel, 'Third Update'), "{$case}: no carousel owns the listing posts");
    }
    $assert(str_contains($markup, 'news/previous/2') || str_contains($markup, 'news/page/2/'), "{$case}: the page navigation link survives as a link");
}

if (array() !== $failures) {
    fwrite(STDERR, implode("\n", $failures) . "\n");
    exit(1);
}
fwrite(STDOUT, "carousel-page-navigation-link: passed\n");
