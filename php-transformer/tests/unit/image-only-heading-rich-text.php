<?php
declare(strict_types=1);

require __DIR__ . '/../../vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

/**
 * A heading whose only content is an image (a logo title), optionally inside
 * a link, stays a native core/heading. Gutenberg keeps the `<img>` as its
 * `core/image` RichText object and the `<a>` as its `core/link` format, so the
 * block validates and stays editable. Before, the `<img>` tripped the RichText
 * fallback gate and the whole heading became a core/html island.
 *
 * The narrow scope is part of the contract: text beside an image, `<picture>`
 * and lazy placeholder images keep the core/html fallback, and image-only
 * paragraphs keep their core/image route.
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

$failures = array();
$logo     = '<img alt="Example logo" width="270" height="45" loading="lazy" decoding="async" style="color: transparent; aspect-ratio: 270 / 45;" src="/media/logo.svg">';

// 1. Every heading level keeps a native heading with the linked image inside it.
foreach ( range(1, 6) as $level ) {
    $result  = $transform('<h' . $level . '><a href="https://example.com/" rel="noreferrer" target="_blank">' . $logo . '</a></h' . $level . '>');
    $markup  = (string) ($result['serialized_blocks'] ?? '');
    $heading = $findBlock($result['blocks'] ?? array(), 'core/heading');
    $content = (string) ($heading['attrs']['content'] ?? '');
    if ( null === $heading
        || $level !== (int) ($heading['attrs']['level'] ?? 0)
        || str_contains($markup, '<!-- wp:html')
        || ! preg_match('#^<a\b[^>]*href="https://example\.com/"[^>]*>\s*<img\b[^>]*alt="Example logo"[^>]*>\s*</a>$#', trim($content))
        || ! str_contains($content, 'width="270"')
        || ! str_contains($content, 'height="45"')
        || ! str_contains($content, 'loading="lazy"')
        || ! str_contains($content, 'style="color: transparent; aspect-ratio: 270 / 45;"')
        || ! str_contains($content, 'target="_blank"')
        || 'pass' !== ($result['source_reports']['wp_block_validity']['status'] ?? '') ) {
        $failures[] = 'h' . $level . ' with only a linked image stays a native core/heading with the image and link: ' . $markup;
    }
}

// 2. An unlinked image-only heading, and a heading with two linked images, stay native headings too.
foreach ( array(
    'unlinked'   => array( '<h2>' . $logo . '</h2>', 1 ),
    'two logos'  => array( '<h3><a href="https://example.com/a"><img alt="A" src="/media/a.png" width="40" height="40"></a> <a href="https://example.com/b"><img alt="B" src="/media/b.png" width="40" height="40"></a></h3>', 2 ),
) as $label => [ $html, $images ] ) {
    $result  = $transform($html);
    $markup  = (string) ($result['serialized_blocks'] ?? '');
    $heading = $findBlock($result['blocks'] ?? array(), 'core/heading');
    if ( null === $heading
        || str_contains($markup, '<!-- wp:html')
        || $images !== substr_count((string) ($heading['attrs']['content'] ?? ''), '<img')
        || 'pass' !== ($result['source_reports']['wp_block_validity']['status'] ?? '') ) {
        $failures[] = 'Image-only heading (' . $label . ') stays a native core/heading: ' . $markup;
    }
}

// 3. Source classes on the heading survive, so selectors such as `.card h3.logo-title` and `.logo-title img` still match.
$css     = '.card h3.logo-title{display:flex;align-items:center;min-height:51px;margin:24px 0 18px}.logo-title a{display:inline-flex}.logo-title img{width:100%;height:auto;display:block}';
$result  = $transform('<article class="card"><h3 class="logo-title"><a href="https://example.com/">' . $logo . '</a></h3><p>Card copy.</p></article>', array( 'static_css' => $css ));
$markup  = (string) ($result['serialized_blocks'] ?? '');
$heading = $findBlock($result['blocks'] ?? array(), 'core/heading');
if ( null === $heading
    || str_contains($markup, '<!-- wp:html')
    || ! preg_match('#<h3\b[^>]*class="[^"]*\blogo-title\b[^"]*"[^>]*>\s*<a\b[^>]*>\s*<img\b#', $markup)
    || 'pass' !== ($result['source_reports']['wp_block_validity']['status'] ?? '') ) {
    $failures[] = 'Image-only heading keeps its source class on the native heading: ' . $markup;
}

// 4. Boundaries keep today's behavior.
$boundaries = array(
    'text beside an image stays core/html'          => array( '<h3>Title <img alt="" src="/media/icon.png" width="20" height="20"></h3>', 'core/html' ),
    'a picture element stays core/html'             => array( '<h3><picture><source srcset="/media/logo.webp" type="image/webp"><img alt="Logo" src="/media/logo.png"></picture></h3>', 'core/html' ),
    'a lazy placeholder image stays core/html'      => array( '<h3><img alt="Logo" src="data:image/gif;base64,R0lGODlhAQABAAAAACw=" data-src="/media/logo.png"></h3>', 'core/html' ),
    'a link with text and an image stays core/html' => array( '<h3><a href="https://example.com/"><img alt="" src="/media/icon.png"> Read more</a></h3>', 'core/html' ),
    'an image-only paragraph still becomes core/image' => array( '<p><a href="https://example.com/"><img alt="Logo" src="/media/logo.png" width="270" height="45"></a></p>', 'core/image' ),
);
foreach ( $boundaries as $label => [ $html, $expected ] ) {
    $result = $transform($html);
    if ( $expected !== ($result['blocks'][0]['blockName'] ?? '') ) {
        $failures[] = 'Boundary: ' . $label . ': ' . (string) ($result['serialized_blocks'] ?? '');
    }
}

// 5. Markup the sanitized fallback would clean (event handlers, unsafe URLs)
//    never enters RichText unchanged: it takes the sanitized core/html path,
//    whose fallback reduction may then rebuild the clean heading natively.
$unsafe = array(
    'image event handler' => array( '<h3><img alt="Logo" src="/media/logo.png" onerror="alert(1)"></h3>', 'onerror' ),
    'link event handler'  => array( '<h3><a href="https://example.com/" onclick="track()"><img alt="Logo" src="/media/logo.png"></a></h3>', 'onclick' ),
    'unsafe link URL'     => array( '<h3><a href="javascript:alert(1)"><img alt="Logo" src="/media/logo.png"></a></h3>', 'javascript:' ),
    'unsafe srcset URL'   => array( '<h3><img alt="Logo" src="/media/logo.png" srcset="javascript:alert(2) 2x"></h3>', 'javascript:' ),
);
foreach ( $unsafe as $label => [ $html, $needle ] ) {
    $result = $transform($html);
    $markup = (string) ($result['serialized_blocks'] ?? '');
    if ( str_contains(strtolower($markup), $needle) || ! str_contains($markup, 'alt="Logo"') ) {
        $failures[] = 'Unsafe image-only heading (' . $label . ') is sanitized and keeps the image: ' . $markup;
    }
}

if ( array() !== $failures ) {
    fwrite(STDERR, implode("\n", $failures) . "\n");
    exit(1);
}

echo "Image-only heading rich text passed.\n";
