<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

/*
 * Generated components carry repeated structural units that native blocks
 * cannot represent. A document flow (paragraphs, figures, and buttons each in
 * the same bare wrapper, framed by empty placeholders and split into layout
 * cells) is prose and media that native blocks own, so it stays native and
 * editable. Genuine repeated units keep their generated component.
 */

$assert = static function (bool $condition, string $message): void {
    if (! $condition) {
        throw new RuntimeException($message);
    }
};

$deep = static function (string $html): string {
    for ($depth = 0; $depth < 16; ++$depth) {
        $html = '<div class="frame-' . $depth . '">' . $html . '</div>';
    }
    return $html;
};

$transform = static fn (string $html): array => (new HtmlTransformer())->transform($html)->toArray();

$components = static fn (array $result): array => array_values(array_filter(
    $result['source_reports']['generated_blocks'] ?? array(),
    static fn (array $definition): bool => 'Layout Shell' !== ($definition['block_json']['title'] ?? null)
));

$count = static fn (array $result, string $comment): int => substr_count((string) ($result['serialized_blocks'] ?? ''), $comment);

$copy = static fn (string $text): string => '<p class="body-copy"><span>' . $text . '</span></p>';
$blankLine = '<div class="body-copy"><span><br></span></div>';
$spacer = '<div class="gap"></div>';

// 1. A two-cell layout: an illustrating image beside a run of paragraphs. The
// cells share one class but are not peers, and the paragraphs are prose.
$layout = '<div class="layout-stack">'
    . '<div class="layout-cell">' . $spacer . '<div class="figure-host"><figure class="frame"><img src="/media/portrait.jpg" alt="Portrait" width="600" height="800"></figure></div>' . $spacer . '</div>'
    . '<div class="layout-cell">' . $copy('First paragraph of the article body.') . $blankLine . $copy('Second paragraph of the article body.') . $blankLine . $copy('Third paragraph of the article body.') . '</div>'
    . '</div>';

// 2. The article body: every block sits in the same bare wrapper, separated
// by empty markers, holding a layout, prose, a file card, and a link button.
$body = '<div class="article-body">'
    . '<div class="gap"></div>'
    . '<div data-breakout="full">' . $layout . '</div><div data-marker="b1"></div>'
    . '<div data-breakout="normal">' . $copy('Download the worksheet below.') . '</div><div data-marker="b2"></div>'
    . '<div data-breakout="normal"><div class="embed-host"><div class="file-card"><button type="button"><svg viewBox="0 0 4 4"><path d="M0 0h4v4H0z"></path></svg></button><span>worksheet.pdf</span></div></div></div><div data-marker="b3"></div>'
    . '<div data-breakout="normal">' . $blankLine . '</div><div data-marker="b4"></div>'
    . '<div data-breakout="normal">' . $copy('Questions? Read more about the practice.') . '</div><div data-marker="b5"></div>'
    . '<div data-breakout="normal"><div class="embed-host"><div class="button-host"><a class="cta" href="/about/">Learn More</a></div></div></div><div data-marker="b6"></div>'
    . '<div data-breakout="normal">' . $copy('All photos are original work.') . '</div>'
    . '</div>';

// 3. The page root: two empty slot placeholders frame the article.
$page = '<div class="post-page">'
    . '<div><div id="slot-above" data-slot="above"></div></div>'
    . '<div><div id="slot-below" data-slot="below"></div></div>'
    . '<div class="post-article"><article><header><h1>Article title</h1></header>' . $body . '</article></div>'
    . '</div>';

$flow = $transform($deep($page));
$flowMarkup = (string) ($flow['serialized_blocks'] ?? '');
$assert(array() === $components($flow), 'A document flow generates no opaque component: ' . json_encode(array_column(array_column($components($flow), 'block_json'), 'name')));
$assert(0 === substr_count($flowMarkup, '<!-- wp:custom/gallery-') && 0 === substr_count($flowMarkup, '<!-- wp:custom/collection-'), 'No gallery or collection component wraps the article.');
$assert(0 === $count($flow, '<!-- wp:html'), 'The article emits no core/html.');
foreach (array('First paragraph of the article body.', 'Second paragraph of the article body.', 'Third paragraph of the article body.', 'Download the worksheet below.', 'Questions? Read more about the practice.', 'All photos are original work.') as $text) {
    $assert(1 === preg_match('/<!-- wp:paragraph[^>]*-->\s*<p[^>]*>(?:<span[^>]*>)?' . preg_quote($text, '/') . '/', $flowMarkup), 'Prose materializes as a native paragraph: ' . $text);
}
$assert(1 === preg_match('/<!-- wp:heading[^>]*-->\s*<h1[^>]*>Article title<\/h1>/', $flowMarkup), 'The article title materializes as a native heading.');
$assert(1 === preg_match('/<!-- wp:image[^>]*-->.*?src="\/media\/portrait\.jpg"/s', $flowMarkup), 'The illustrating image materializes as a native image.');
$assert(str_contains($flowMarkup, 'href="/about/"') && str_contains($flowMarkup, 'Learn More'), 'The link button keeps its destination and label.');

// 4. A run of uniformly styled paragraphs alone is prose, not a collection.
$prose = $transform($deep('<div class="rich-text">' . $copy('One.') . $copy('Two.') . $copy('Three.') . $copy('Four.') . '</div>'));
$assert(array() === $components($prose), 'A run of paragraphs generates no component.');
$assert(4 === $count($prose, '<!-- wp:paragraph'), 'Each paragraph materializes natively.');

// 5. Genuine repeated units keep their generated component: content cards and
// an image grid whose items share one shape.
$cards = $transform($deep('<div class="story-collection">'
    . '<article><h3>One</h3><p>First story</p></article>'
    . '<article><h3>Two</h3><p>Second story</p></article>'
    . '<article><h3>Three</h3><p>Third story</p></article>'
    . '</div>'));
$assert(1 === count($components($cards)) && 1 === $count($cards, '<!-- wp:custom/collection-'), 'Repeated content cards keep one generated collection.');

$grid = $transform($deep('<image-grid class="tiles">'
    . '<div class="tile"><img src="/media/a.jpg" alt="A"></div>'
    . '<div class="tile"><img src="/media/b.jpg" alt="B"></div>'
    . '<div class="tile"><img src="/media/c.jpg" alt="C"></div>'
    . '</image-grid>'));
$assert(1 === count($components($grid)) && 1 === $count($grid, '<!-- wp:custom/gallery-'), 'A repeated image grid keeps one generated gallery.');

echo "document-flow generated-component boundary contract passed\n";
