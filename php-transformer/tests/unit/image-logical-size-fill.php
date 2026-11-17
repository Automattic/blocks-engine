<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

// #6378: Wix sizes its cover images with logical properties
// (`._image ._wowImage img{block-size:100%;inline-size:100%}`). The image
// shape reader only knew `height`, so core/image saved its forced
// `height:auto` beside the inline `width:100%`, the image stopped filling its
// frame, and the cover crop resampled at a different scale.
$failures = array();
$assert = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) $failures[] = $message;
};
$image = static function (string $css): string {
    $result = (new HtmlTransformer())->transform(
        '<style>.frame{position:relative;width:214px;height:283px}.frame .wow{position:absolute;inset:0}' . $css . '</style>'
        . '<div class="frame"><div class="wow"><picture><img src="https://example.com/a.png" alt="" style="object-fit:cover;width:100%"></picture></div></div>',
        array()
    )->toArray();
    preg_match('/<img[^>]*>/', $result['serialized_blocks'], $match);
    return $match[0] ?? '';
};

$logical = $image('.frame .wow img{block-size:100%;inline-size:100%}');
$assert(str_contains($logical, 'style="object-fit:cover;width:100%;height:100%"'), 'block-size:100% fills the frame like height:100%: ' . $logical);
$physical = $image('.frame .wow img{height:100%;width:100%}');
$assert(str_contains($physical, 'style="object-fit:cover;width:100%;height:100%"'), 'physical height:100% still fills the frame: ' . $physical);
$unsized = $image('');
$assert(str_contains($unsized, 'style="object-fit:cover;width:100%;height:auto"'), 'an image without an authored height keeps core height:auto: ' . $unsized);

if ($failures) { fwrite(STDERR, implode("\n", $failures) . "\n"); exit(1); }
echo "Image logical size fill passed: 3 assertions\n";
