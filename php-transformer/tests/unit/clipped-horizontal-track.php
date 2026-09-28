<?php
declare(strict_types=1);

/**
 * A clipped horizontal strip stays one row. core/columns wraps and stacks
 * below 782px, which grows the clip over the siblings the source kept below
 * a single visible item.
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

$failures = 0;
$passes = 0;

$assert = static function (bool $condition, string $message, string $detail = '') use (&$failures, &$passes): void {
    if ( $condition ) {
        ++$passes;
        return;
    }

    ++$failures;
    fwrite(STDERR, 'FAIL: ' . $message . ('' !== $detail ? ' - ' . $detail : '') . PHP_EOL);
};

$cssFor = static function (array $result, string $source): string {
    return implode("\n", array_map(
        static fn (array $asset): string => (string) ($asset['content'] ?? ''),
        array_values(array_filter(
            is_array($result['assets'] ?? null) ? $result['assets'] : array(),
            static fn (array $asset): bool => $source === ($asset['source'] ?? '')
        ))
    ));
};

$nest = static function (string $html, int $depth): string {
    for ( $level = 0; $level < $depth; ++$level ) {
        $html = '<div class="nest">' . $html . '</div>';
    }

    return $html;
};

$items = static function (int $count): string {
    $html = '';
    for ( $index = 1; $index <= $count; ++$index ) {
        $html .= '<div class="item" style="width:320px;flex-shrink:0;height:240px"><img src="item-' . $index . '.jpg" alt="Item ' . $index . '"><p>Item ' . $index . '</p></div>';
    }

    return $html;
};

$transform = static fn (string $html): array => ( new HtmlTransformer() )->transform($html, array())->toArray();

$flex = $transform($nest(
    '<style>.clip{overflow:hidden;position:relative}</style>'
    . '<div class="clip" style="height:240px">'
    . '<div class="track" style="display:flex">' . $items(3) . '</div>'
    . '</div>'
    . '<section class="after"><h2>After</h2><p>Following section</p></section>',
    14
));
$flexMarkup = (string) ($flex['serialized_blocks'] ?? '');
$flexEngineCss = $cssFor($flex, 'engine-support');
$flexAuthorCss = $cssFor($flex, 'author-css');

$assert(
    ! str_contains($flexMarkup, '<!-- wp:columns'),
    'clipped flex track is not lowered to stacking columns',
    $flexMarkup
);
$assert(
    str_contains($flexEngineCss, 'height:240px') || str_contains($flexMarkup, 'height:240px'),
    'clipped flex track keeps the container height',
    $flexEngineCss
);
$assert(
    str_contains($flexEngineCss, 'overflow:hidden') || str_contains($flexAuthorCss, 'overflow:hidden') || str_contains($flexMarkup, 'overflow:hidden'),
    'clipped flex track keeps overflow clipping',
    $flexEngineCss . $flexAuthorCss
);
$assert(
    str_contains($flexEngineCss, 'display:flex') || str_contains($flexMarkup, 'display:flex'),
    'clipped flex track stays a horizontal flex row',
    $flexEngineCss
);
$afterOutsideColumns = str_contains($flexMarkup, '<h2 class="wp-block-heading">After</h2>')
    && ! str_contains(substr($flexMarkup, 0, (int) strpos($flexMarkup, '<h2 class="wp-block-heading">After</h2>')), '<!-- wp:columns');
$assert(
    str_contains($flexMarkup, 'Following section') && $afterOutsideColumns,
    'the section after the clip remains a sibling, not a stacked slide',
    $flexMarkup
);

$absolute = $transform($nest(
    '<div class="stage" style="height:240px;overflow:hidden;position:relative">'
    . '<div class="item" style="position:absolute;left:0;width:320px;height:240px"><img src="item-1.jpg" alt="Item 1"></div>'
    . '<div class="item" style="position:absolute;left:320px;width:320px;height:240px"><img src="item-2.jpg" alt="Item 2"></div>'
    . '<div class="item" style="position:absolute;left:640px;width:320px;height:240px"><img src="item-3.jpg" alt="Item 3"></div>'
    . '</div>'
    . '<section class="after"><h2>After</h2><p>Following section</p></section>',
    14
));
$absoluteMarkup = (string) ($absolute['serialized_blocks'] ?? '');
$absoluteCss = $cssFor($absolute, 'engine-support') . $absoluteMarkup;

$assert(
    ! str_contains($absoluteMarkup, '<!-- wp:columns'),
    'clipped absolute track is not lowered to stacking columns',
    $absoluteMarkup
);
$assert(
    str_contains($absoluteCss, 'height:240px') && str_contains($absoluteCss, 'overflow:hidden') && str_contains($absoluteCss, 'position:absolute'),
    'clipped absolute track keeps its height, clip, and horizontal offsets',
    $absoluteCss
);

$galleryItems = '';
for ( $index = 1; $index <= 16; ++$index ) {
    $galleryItems .= '<div class="logo"><img src="logo-' . $index . '.png" alt="Logo ' . $index . '"></div>';
}
$gallery = $transform(
    '<style>.gallery-track{display:flex}</style>'
    . '<div class="gallery-viewport" style="height:124px;overflow:hidden;position:relative">'
    . '<div class="wp-block-columns gallery-track" style="position:absolute;visibility:hidden;height:0;left:0;top:0">'
    . $galleryItems
    . '</div></div><section class="after"><h2>After</h2><p>Following section</p></section>'
);
$galleryMarkup = (string) ($gallery['serialized_blocks'] ?? '');
$galleryCss = implode("\n", array_map(static fn (array $asset): string => (string) ($asset['content'] ?? ''), $gallery['assets'] ?? array())) . $galleryMarkup;
$galleryEngineCss = $cssFor($gallery, 'engine-support');
$assert(! str_contains($galleryMarkup, '<!-- wp:columns'), 'positioned logo track is not lowered to core/columns', $galleryMarkup);
$assert(str_contains($galleryMarkup, 'Logo 16'), 'all sixteen gallery images survive conversion', $galleryMarkup);
$assert(substr_count($galleryMarkup, '<img') === 16, 'the gallery retains all sixteen image elements', $galleryMarkup);
$assert(
    str_contains($galleryCss, 'position:absolute') && str_contains($galleryCss, 'display:flex') && str_contains($galleryCss, 'height:0') && str_contains($galleryCss, 'visibility:hidden'),
    'positioned gallery track retains absolute resolved-flex geometry and zero track height',
    $galleryCss
);
$assert(
    str_contains($galleryEngineCss, 'height:124px') && str_contains($galleryEngineCss, 'overflow:hidden') && str_contains($galleryEngineCss, 'position:relative'),
    'gallery viewport remains a bounded clipping region',
    $galleryEngineCss
);

if ( $failures > 0 ) {
    fwrite(STDERR, "Clipped horizontal track contract: {$failures} failed, {$passes} passed\n");
    exit(1);
}

fwrite(STDOUT, "Clipped horizontal track contract passed: {$passes} assertions\n");
