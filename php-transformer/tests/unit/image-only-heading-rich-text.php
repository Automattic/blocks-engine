<?php
declare(strict_types=1);

require __DIR__ . '/../../vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

/**
 * A heading keeps its native semantics while it carries text: an inline
 * `<img>` beside that text is a RichText image object and an `<a>` around it
 * is a RichText link format, so the block validates and stays editable
 * (#3496). A heading with no text has no heading semantics, so its one media
 * element lowers as the media block it visually is (#4303): an image becomes
 * a core/image block the same way a text-less paragraph unwraps, and an
 * iframe takes the embed route.
 *
 * Wrapped and mixed inline images share the paragraph RichText path, while
 * `<picture>` and lazy placeholder images follow the paragraph media route.
 */

$findBlock = static function (array $blocks, string $name) use (&$findBlock): ?array {
    foreach ( $blocks as $block ) {
        if ( $name === ($block['blockName'] ?? '') ) {
            return $block;
        }
        $nested = $findBlock($block['innerBlocks'] ?? array(), $name);
        if ( null !== $nested ) {
            return $nested;
        }
    }
    return null;
};
$transform = static fn (string $html, array $options = array()): array => ( new HtmlTransformer() )->transform($html, $options)->toArray();
$decode    = static fn (string $markup): string => preg_replace_callback('/\\\\u([0-9a-fA-F]{4})/', static fn (array $m): string => json_decode('"\u' . $m[1] . '"'), $markup) ?? $markup;

$failures = array();
$logo     = '<img alt="Example logo" width="270" height="45" loading="lazy" decoding="async" style="color: transparent; aspect-ratio: 270 / 45;" src="/media/logo.svg">';

// 1. Every heading level lowers a text-less linked logo to a core/image block
//    that keeps the link, the source and the image geometry.
foreach ( range(1, 6) as $level ) {
    $result = $transform('<h' . $level . '><a href="https://example.com/" rel="noreferrer" target="_blank">' . $logo . '</a></h' . $level . '>');
    $markup = (string) ($result['serialized_blocks'] ?? '');
    $image  = $findBlock($result['blocks'] ?? array(), 'core/image');
    if ( null === $image
        || null !== $findBlock($result['blocks'] ?? array(), 'core/heading')
        || str_contains($markup, '<!-- wp:html')
        || ! str_contains($markup, 'src="/media/logo.svg"')
        || ! str_contains($markup, 'alt="Example logo"')
        || ! str_contains($markup, 'style="width:270px;height:45px"')
        || ! str_contains($markup, 'href="https://example.com/"')
        || ! str_contains($markup, 'target="_blank"')
        || ! str_contains($markup, 'rel="noreferrer"')
        || 'pass' !== ($result['source_reports']['wp_block_validity']['status'] ?? '') ) {
        $failures[] = 'h' . $level . ' with only a linked image lowers to a core/image block keeping the link and source: ' . $markup;
    }
}

// 2. An unlinked image-only heading becomes a core/image block, while a
//    heading holding two images stays a native heading (#3496).
foreach ( array(
    'unlinked'   => array( '<h2>' . $logo . '</h2>', 'core/image' ),
    'two logos'  => array( '<h3><a href="https://example.com/a"><img alt="A" src="/media/a.png" width="40" height="40"></a> <a href="https://example.com/b"><img alt="B" src="/media/b.png" width="40" height="40"></a></h3>', 'core/heading' ),
) as $label => [ $html, $expected ] ) {
    $result = $transform($html);
    $markup = (string) ($result['serialized_blocks'] ?? '');
    $block  = $findBlock($result['blocks'] ?? array(), $expected);
    if ( null === $block
        || str_contains($markup, '<!-- wp:html')
        || 'core/image' === $expected && ! str_contains($markup, 'src="/media/logo.svg"')
        || 'core/heading' === $expected && 2 !== substr_count((string) ($block['attrs']['content'] ?? ''), '<img')
        || 'pass' !== ($result['source_reports']['wp_block_validity']['status'] ?? '') ) {
        $failures[] = 'Image-only heading (' . $label . ') lowers to ' . $expected . ': ' . $markup;
    }
}

// 3. The heading's selector identity follows the image block: rules that
//    matched `h3.logo-title` (`.card h3.logo-title`) are re-addressed to the
//    figure's semantic carrier class, and the figure keeps `logo-title` so
//    the `.logo-title img` rules still reach the image.
$css     = '.card h3.logo-title{display:flex;align-items:center;min-height:51px;margin:24px 0 18px}.logo-title a{display:inline-flex}.logo-title img{width:100%;height:auto;display:block}';
$result  = $transform('<article class="card"><h3 class="logo-title"><a href="https://example.com/">' . $logo . '</a></h3><p>Card copy.</p></article>', array( 'static_css' => $css ));
$markup  = (string) ($result['serialized_blocks'] ?? '');
$sheet   = implode("\n", array_column(
    array_filter($result['assets'] ?? array(), static fn (array $asset): bool => 'css' === ($asset['kind'] ?? '')),
    'content'
));
$carriers = array();
if ( 1 === preg_match('/blocks-engine-semantic-[a-f0-9]+-\d+/', $markup, $carrierMatch) ) {
    $carriers = array( $carrierMatch[0] );
}
$image = $findBlock($result['blocks'] ?? array(), 'core/image');
if ( null === $image
    || str_contains($markup, '<!-- wp:html')
    || array() === $carriers
    || ! str_contains($markup, 'src="/media/logo.svg"')
    || ! str_contains($markup, 'style="width:100%;height:auto"')
    || ! preg_match('/' . preg_quote($carriers[0] ?? '~~none~~', '/') . '[^{}]*\{[^{}]*min-height:51px/', $sheet)
    || ! str_contains($sheet, '.logo-title img')
    || 'pass' !== ($result['source_reports']['wp_block_validity']['status'] ?? '') ) {
    $failures[] = 'Image-only heading keeps its selector identity on the image block: ' . $markup;
}

// 4. Headings that hold text keep #3496: the image stays an inline RichText
//    object and the heading keeps its level, alignment and sources.
$image = '<img alt="" src="/a.png" style="width:50px;height:50px">';
$inlineImages = array(
    'image beside text' => $image . ' Text',
    'wrapped image beside text' => '<u><span>' . $image . '</span></u> Text',
    'multiple marked images' => '<u>' . $image . '</u> <span><img alt="" src="/b.png"></span>',
    'linked image beside text' => '<a href="/details">' . $image . ' Read more</a>',
);
foreach ( $inlineImages as $label => $inlineContent ) {
    foreach ( range(1, 6) as $level ) {
        $result = $transform('<h' . $level . ' style="text-align:center">' . $inlineContent . '</h' . $level . '>');
        $markup = (string) ($result['serialized_blocks'] ?? '');
        $heading = $findBlock($result['blocks'] ?? array(), 'core/heading');
        $content = (string) ($heading['attrs']['content'] ?? '');
        if ( null === $heading
            || $level !== ($heading['attrs']['level'] ?? null)
            || 'center' !== ($heading['attrs']['style']['typography']['textAlign'] ?? null)
            || null !== $findBlock($result['blocks'] ?? array(), 'core/html')
            || null !== $findBlock($result['blocks'] ?? array(), 'core/image')
            || 'pass' !== ($result['source_reports']['wp_block_validity']['status'] ?? '') ) {
            $failures[] = 'h' . $level . ' (' . $label . ') keeps native heading semantics: ' . $markup;
        }
        preg_match_all('/src="([^"]+)"/', $inlineContent, $sources);
        foreach ( $sources[1] as $src ) {
            if ( ! str_contains($content, 'src="' . $src . '"') ) {
                $failures[] = 'h' . $level . ' (' . $label . ') keeps image source ' . $src . ': ' . $markup;
            }
        }
        if ( str_contains($inlineContent, 'Text') && ! str_contains($content, 'Text') ) {
            $failures[] = 'h' . $level . ' (' . $label . ') keeps its text: ' . $markup;
        }
    }
}

// 5. Boundaries. Text-less headings follow the paragraph media route for
//    every media form their image recognizer accepts; a paragraph beside a
//    `<br>` keeps its RichText image and an empty heading keeps its box.
$boundaries = array(
    'an image-only heading becomes core/image'         => array( '<h3>' . $logo . '</h3>', 'core/image' ),
    'a picture-only heading follows the media route'   => array( '<h3><picture><source srcset="/media/logo.webp" type="image/webp"><img alt="Logo" src="/media/logo.png"></picture></h3>', 'custom/responsive-media' ),
    'a lazy placeholder image follows the media route' => array( '<h3><img alt="Logo" src="data:image/gif;base64,R0lGODlhAQABAAAAACw=" data-src="/media/logo.png"></h3>', 'core/image' ),
    'an image-only paragraph still becomes core/image' => array( '<p><a href="https://example.com/"><img alt="Logo" src="/media/logo.png" width="270" height="45"></a></p>', 'core/image' ),
    'an image paragraph keeps a br beside the image'   => array( '<p><img alt="L" src="/media/logo.png" width="40" height="40"><br></p>', 'core/paragraph' ),
);
foreach ( $boundaries as $label => [ $html, $expected ] ) {
    $result = $transform($html);
    if ( $expected !== ($result['blocks'][0]['blockName'] ?? '') ) {
        $failures[] = 'Boundary: ' . $label . ': ' . (string) ($result['serialized_blocks'] ?? '');
    }
}

// 6. Markup the sanitizers would clean (event handlers, unsafe URLs) never
//    reaches the lowered media blocks unchanged: the img and link routes
//    sanitize it and keep the image itself.
$unsafe = array(
    'image event handler' => array( '<h3><img alt="Logo" src="/media/logo.png" onerror="alert(1)"></h3>', 'onerror' ),
    'link event handler'  => array( '<h3><a href="https://example.com/" onclick="track()"><img alt="Logo" src="/media/logo.png"></a></h3>', 'onclick' ),
    'unsafe link URL'     => array( '<h3><a href="javascript:alert(1)"><img alt="Logo" src="/media/logo.png"></a></h3>', 'javascript:' ),
    'unsafe srcset URL'   => array( '<h3><img alt="Logo" src="/media/logo.png" srcset="javascript:alert(2) 2x"></h3>', 'javascript:' ),
);
foreach ( $unsafe as $label => [ $html, $needle ] ) {
    $result = $transform($html);
    $markup = $decode((string) ($result['serialized_blocks'] ?? ''));
    if ( str_contains(strtolower($markup), $needle) || ! str_contains($markup, 'alt="Logo"') ) {
        $failures[] = 'Unsafe image-only heading (' . $label . ') is sanitized and keeps the image: ' . $markup;
    }
}

// 6. The figure stands in for the heading, so an author rule that sizes the
//    image through the heading's class reaches the image through the figure.
foreach ( array( 'heading' => '<h1 class="logo"><a href="/">%s</a></h1>', 'paragraph' => '<p class="logo">%s</p>' ) as $label => $wrapper ) {
    $result = $transform('<!doctype html><html><head><style>.brand .logo img{max-height:40px}</style></head><body class="brand">' . sprintf($wrapper, '<img src="logo.png" alt="Brand">') . '<p>Copy</p></body></html>');
    $css    = (string) ($result['source_reports']['author_stylesheet_projections'][0]['content'] ?? '');
    $figure = preg_match('/<figure class="[^"]*\\b(blocks-engine-semantic-[a-f0-9]+-\\d+)\\b/', (string) ($result['serialized_blocks'] ?? ''), $match) ? $match[1] : '';
    if ( '' === $figure || 1 !== preg_match('/:where\\(\\.' . preg_quote($figure, '/') . '\\)\\.wp-block-image > (?:a > )?img[^{]*\\{[^}]*max-height:40px/', $css) ) {
        $failures[] = 'Image-only ' . $label . ' rule reaches its image through the figure: ' . $css;
    }
}

if ( array() !== $failures ) {
    fwrite(STDERR, implode("\n", $failures) . "\n");
    exit(1);
}

echo "Image-only heading rich text passed.\n";
