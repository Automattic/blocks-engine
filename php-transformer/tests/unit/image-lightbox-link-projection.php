<?php
declare(strict_types=1);

/**
 * Gallery builders wrap a thumbnail in a link to the full-size file and let a
 * script (fancybox, colorbox, photoswipe) intercept the click to open it in an
 * overlay. Carrying that link through verbatim produces an image that
 * navigates away to a bare JPEG instead of opening in place. Core owns the
 * behavior natively, so the trigger belongs on the image block.
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

$failures = 0;
$passes = 0;
$assert = static function (bool $ok, string $message, string $detail = '') use (&$failures, &$passes): void {
    if ( $ok ) {
        ++$passes;
        return;
    }
    ++$failures;
    fwrite(STDERR, 'FAIL: ' . $message . ( '' !== $detail ? ' - ' . $detail : '' ) . PHP_EOL);
};

$transform = static fn (string $html): string => (string) ( ( new HtmlTransformer() )->transform($html, array())->toArray()['serialized_blocks'] ?? '' );

// A Weebly gallery thumbnail: rel="lightbox[group]" plus a link to the original.
$weebly = $transform(
    '<main><figure><a href="/uploads/img-1631_orig.jpeg" rel="lightbox[gallery219311460416557837]" title="Yosemite" class="w-fancybox">'
    . '<img src="/media/img-1631.jpeg" class="galleryImage" width="800" height="600" alt="Yosemite"></a></figure></main>'
);
$assert(
    str_contains($weebly, '"lightbox":{"enabled":true}'),
    'a rel="lightbox[...]" thumbnail opts the image into the native lightbox',
    $weebly
);
$assert(
    ! str_contains($weebly, '"linkDestination"') && ! str_contains($weebly, 'href="/uploads/img-1631_orig.jpeg"'),
    'the source trigger link is not left on the image, which would suppress the core lightbox',
    $weebly
);

// Class-based triggers from other gallery scripts.
foreach ( array( 'fancybox', 'colorbox', 'magnific-popup', 'photoswipe', 'prettyPhoto' ) as $className ) {
    $markup = $transform(
        '<main><figure><a href="/media/full.jpg" class="' . $className . '"><img src="/media/thumb.jpg" width="800" height="600" alt="Thumb"></a></figure></main>'
    );
    $assert(
        str_contains($markup, '"lightbox":{"enabled":true}'),
        'a ' . $className . ' trigger opts the image into the native lightbox',
        $markup
    );
}

// data-* triggers.
$dataTrigger = $transform('<main><figure><a href="/media/full.png" data-fancybox="gallery"><img src="/media/thumb.png" width="800" height="600" alt="T"></a></figure></main>');
$assert(
    str_contains($dataTrigger, '"lightbox":{"enabled":true}'),
    'a data-fancybox trigger opts the image into the native lightbox',
    $dataTrigger
);

// A real destination must keep its link.
$destination = $transform('<main><figure><a href="/about-me.html"><img src="/media/thumb.jpg" width="800" height="600" alt="About"></a></figure></main>');
$assert(
    ! str_contains($destination, '"lightbox"') && str_contains($destination, 'href="/about-me.html"'),
    'an image linking to a page keeps its link and gains no lightbox',
    $destination
);

// A plain link to an image file is a download/destination, not a viewer.
$plainImageLink = $transform('<main><figure><a href="/media/full.jpg"><img src="/media/thumb.jpg" width="800" height="600" alt="T"></a></figure></main>');
$assert(
    ! str_contains($plainImageLink, '"lightbox"') && str_contains($plainImageLink, 'href="/media/full.jpg"'),
    'an unmarked link to an image file is left as an ordinary link',
    $plainImageLink
);

// A lightbox-marked link that does not resolve to an image is not a viewer.
$nonImage = $transform('<main><figure><a href="/gallery.html" rel="lightbox"><img src="/media/thumb.jpg" width="800" height="600" alt="T"></a></figure></main>');
$assert(
    ! str_contains($nonImage, '"lightbox":{"enabled":true}'),
    'a lightbox-marked link to a document is not projected onto the image',
    $nonImage
);

if ( 0 < $failures ) {
    fwrite(STDERR, "image lightbox link projection FAILED: {$passes} passed, {$failures} failed\n");
    exit(1);
}
echo "image lightbox link projection passed: {$passes} assertions\n";
