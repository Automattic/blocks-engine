<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

// An image that fills its frame through logical sizes
// (`.frame img{block-size:100%;inline-size:100%}`, as Wix image components
// author it) is sized exactly like one written with width/height. Ignoring
// `block-size` left core/image with only the inline `width:100%`, so its
// save forced `height:auto` and the cover crop followed the file's aspect
// ratio instead of the frame's.
$image = static function (string $css, string $style): string {
    $result = ( new HtmlTransformer() )->transform(
        '<style>.frame{position:relative;height:361px}' . $css . '</style>'
        . '<main><div class="frame"><img alt="" src="card.jpg" style="' . $style . '"></div></main>'
    )->toArray();
    $markup = (string) ($result['serialized_blocks'] ?? '');
    return preg_match('/<img[^>]*>/', $markup, $match) ? $match[0] : $markup;
};

$logical = $image('.frame img{block-size:100%;inline-size:100%}', 'object-fit: cover;width: 100%');
$physical = $image('.frame img{height:100%;width:100%}', 'object-fit: cover;width: 100%');

if ( ! str_contains($logical, 'style="object-fit:cover;width:100%;height:100%"') ) {
    fwrite(STDERR, "FAIL: block-size:100% must reach the image block as height:100%, not core's height:auto\n" . $logical . "\n");
    exit(1);
}
if ( $logical !== $physical ) {
    fwrite(STDERR, "FAIL: logical and physical image sizes must lower identically\n" . $logical . "\n" . $physical . "\n");
    exit(1);
}

// The min/max logical forms bound the same physical axes.
$bounded = ( new HtmlTransformer() )->transform(
    '<style>.thumb img{max-inline-size:120px}</style><main><div class="thumb"><img alt="" src="thumb.jpg"></div></main>'
)->toArray();
$boundedPhysical = ( new HtmlTransformer() )->transform(
    '<style>.thumb img{max-width:120px}</style><main><div class="thumb"><img alt="" src="thumb.jpg"></div></main>'
)->toArray();
if ( ($bounded['serialized_blocks'] ?? '') !== ($boundedPhysical['serialized_blocks'] ?? '') ) {
    fwrite(STDERR, "FAIL: max-inline-size must lower like max-width\n" . ($bounded['serialized_blocks'] ?? '') . "\n" . ($boundedPhysical['serialized_blocks'] ?? '') . "\n");
    exit(1);
}

echo "Image logical size fill: 3 assertions passed\n";
