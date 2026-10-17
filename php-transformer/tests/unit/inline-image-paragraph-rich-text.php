<?php
declare(strict_types=1);

require __DIR__ . '/../../vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

/**
 * A paragraph that mixes an inline image with text stays a native, editable
 * core/paragraph. Gutenberg carries the `<img>` as its `core/image` RichText
 * object, so the paragraph keeps the image's src, alt and size beside its text
 * and links. Before, the `<img>` tripped the RichText fallback gate and the
 * whole paragraph silently became a core/html island.
 *
 * The narrow scope is part of the contract: an image inside a custom host or a
 * placeholder `data:` image keeps the core/html fallback, a `<picture>` keeps
 * its media row, and an image-only paragraph keeps its core/image route.
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
$transform = static fn (string $html): array => ( new HtmlTransformer() )->transform($html)->toArray();

$failures = array();
$image    = '<img class="wp-image-12 alignnone" src="/media/logo.png" alt="Example logo" width="91" height="47">';

// 1. An image before, between, or beside linked text stays inside one editable paragraph.
foreach ( array(
    'leading image'     => array( '<p>' . $image . ' Learn more.</p>', '#^' . preg_quote($image, '#') . ' Learn more\.$#' ),
    'image within text' => array( '<p>Learn ' . $image . ' more.</p>', '#^Learn ' . preg_quote($image, '#') . ' more\.$#' ),
    'promo banner'      => array( '<div class="banner"><div class="banner-text"><span>' . $image . ' Learn how X is <a href="https://example.com/rx">transforming</a> the region.</span></div></div>', '#^<span>' . preg_quote($image, '#') . '\s+Learn how X is <a href="https://example\.com/rx">transforming</a> the region\.</span>$#' ),
) as $name => [ $source, $pattern ] ) {
    $result    = $transform($source);
    $markup    = (string) ($result['serialized_blocks'] ?? '');
    $paragraph = $findBlock($result['blocks'] ?? array(), 'core/paragraph');
    if ( null === $paragraph
        || str_contains($markup, '<!-- wp:html')
        || 1 !== preg_match($pattern, trim((string) ($paragraph['attrs']['content'] ?? '')))
        || 'pass' !== ($result['source_reports']['wp_block_validity']['status'] ?? '') ) {
        $failures[] = $name . ' stays an editable core/paragraph carrying the inline image: ' . $markup;
    }
}

// 2. Images RichText cannot carry as-is keep their existing routes.
foreach ( array(
    'image in a custom host stays core/html'      => array( '<p>Before <media-frame><img src="/media/a.png" width="30" height="30" alt="A"></media-frame> after</p>', 'core/html' ),
    'picture beside text keeps its media row'     => array( '<p><picture><source srcset="/media/a.webp"><img src="/media/a.png" alt="A"></picture> Caption</p>', 'core/group' ),
    'placeholder image beside text stays core/html' => array( '<p><img src="data:image/gif;base64,R0lGODlhAQABAAAAACw=" alt="A"> Caption</p>', 'core/html' ),
    'image-only paragraph stays core/image'       => array( '<p><img src="/media/a.png" alt="A" width="91" height="47"></p>', 'core/image' ),
) as $name => [ $source, $expected ] ) {
    $result = $transform($source);
    if ( $expected !== ($result['blocks'][0]['blockName'] ?? null) ) {
        $failures[] = $name . ': ' . (string) ($result['serialized_blocks'] ?? '');
    }
}

// 3. An event handler never reaches the paragraph's RichText.
$handler = (string) ($transform('<p><img src="/media/a.png" alt="A" onload="track()"> Caption</p>')['serialized_blocks'] ?? '');
if ( str_contains(strtolower($handler), 'onload') || str_contains($handler, 'track()') ) {
    $failures[] = 'an image event handler is dropped: ' . $handler;
}

if ( array() !== $failures ) {
    fwrite(STDERR, 'FAIL: ' . implode("\nFAIL: ", $failures) . "\n");
    exit(1);
}

echo "Inline image paragraph rich text passed.\n";
