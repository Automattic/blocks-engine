<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

// A video whose own class fixes its box (`aspect-video`) while a portrait
// poster supplies its intrinsic size. core/video moves the class to a new
// figure, so support CSS must make the <video> fill that figure, or it takes
// the poster's aspect ratio and grows the figure (and the page) past the
// source's 16:9 letterboxed frame.
$html = '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><title>Video repro</title><style>'
    . 'body{margin:0}'
    . 'img,video{max-width:100%;height:auto}'
    . '.frame{max-width:56rem;margin:0 auto;overflow:hidden}'
    . '.aspect-video{aspect-ratio:16/9}'
    . '.w-full{width:100%}'
    . '.fixed-h{height:320px}'
    . '</style></head><body>'
    . '<div class="frame"><video class="aspect-video w-full" controls poster="portrait-591x1280.jpg" src="promo.mp4"></video></div>'
    . '<div class="frame"><video class="fixed-h w-full" controls poster="portrait-591x1280.jpg" src="promo.mp4"></video></div>'
    . '<div class="frame"><video class="w-full" controls src="intrinsic.mp4"></video></div>'
    . '</body></html>';

$result = (new HtmlTransformer())->transform($html)->toArray();
$blocks = (string) $result['serialized_blocks'];
$css = implode("\n", array_map(static fn(array $asset): string => (string) ($asset['content'] ?? ''), $result['assets']));

if ( ! str_contains($blocks, '<figure class="wp-block-video aspect-video w-full blocks-engine-synthetic-video-figure-ratio">') ) {
    throw new RuntimeException('A source video sized by an aspect ratio marks its core/video figure as a sized figure.');
}
if ( ! str_contains($blocks, '<figure class="wp-block-video fixed-h w-full blocks-engine-synthetic-video-figure-ratio">') ) {
    throw new RuntimeException('A source video sized by a fixed height marks its core/video figure as a sized figure.');
}
if ( ! str_contains($blocks, '<figure class="wp-block-video w-full">') ) {
    throw new RuntimeException('A video sized only by its intrinsic media keeps its unmarked core/video figure.');
}
if ( ! str_contains($css, ':root :where(figure.blocks-engine-synthetic-video-figure-ratio)>video{width:100%;height:100%}') ) {
    throw new RuntimeException('The <video> fills its sized figure so the source box, not the poster, sets its height.');
}
if ( ! str_contains($css, ':where(figure.blocks-engine-synthetic-video-figure-ratio){object-fit:contain}')
    || ! str_contains($css, '.wp-block-video.blocks-engine-synthetic-video-figure-ratio>video{object-fit:inherit}') ) {
    throw new RuntimeException('The filled <video> letterboxes its poster like the native element instead of Core\'s poster cover.');
}
if ( ! str_contains($css, ':root :where(figure.blocks-engine-synthetic-video-figure-ratio){margin:0}') ) {
    throw new RuntimeException('The figure core/video injects adds no default figure margin below the source box.');
}

echo "Video figure sized box child contract passed.\n";
