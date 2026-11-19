<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

// The poster/metadata may have a different intrinsic ratio. The authored
// ratio must reach the native video, not just core/video's synthetic figure.
$result = ( new HtmlTransformer() )->transform(
    '<style>.clip{aspect-ratio:16/9;width:100%}@media(min-width:768px){.clip{aspect-ratio:4/3}}</style>'
    . '<main><video class="clip" src="clip.mp4" poster="poster.jpg" controls></video></main>'
)->toArray();
$block = $result['blocks'][0] ?? array();
$css = implode("\n", array_column($result['assets'], 'content'));
$marker = 'blocks-engine-video-aspect-ratio';
if ( 'core/video' !== ($block['blockName'] ?? '')
    || ! str_contains($block['attrs']['className'] ?? '', $marker)
    || ! str_contains($css, '.' . $marker . '>video{aspect-ratio:inherit}')
    || ! str_contains($css, 'aspect-ratio:16/9')
    || ! str_contains($css, 'aspect-ratio:4/3') ) {
    fwrite(STDERR, "FAIL: authored video aspect ratio must reach the native video through its figure carrier\n");
    exit(1);
}
// No ratio is invented for intrinsic-size videos.
$plain = ( new HtmlTransformer() )->transform('<video src="clip.mp4"></video>')->toArray();
if ( str_contains($plain['blocks'][0]['attrs']['className'] ?? '', $marker) ) {
    fwrite(STDERR, "FAIL: intrinsic video must retain its native aspect ratio\n");
    exit(1);
}
echo "video aspect ratio ok\n";
