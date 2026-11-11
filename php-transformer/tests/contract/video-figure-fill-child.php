<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

// A video that fills its frame through an attribute-keyed rule: the projected
// rule lands on the core/video figure, so support CSS must size the <video>
// inside it too, or it takes its intrinsic aspect ratio and overflows.
$html = '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><title>Video repro</title><style>'
    . 'body{margin:0}'
    . '.player{margin:40px;width:1055px;height:594px;background:#000}'
    . '[data-player] video{width:100%;height:100%}'
    . '</style></head><body>'
    . '<div class="player" data-player><video autoplay muted playsinline src="portrait-270x480.mp4"></video></div>'
    . '</body></html>';

$result = (new HtmlTransformer())->transform($html)->toArray();
$css = implode("\n", array_map(static fn(array $asset): string => (string) ($asset['content'] ?? ''), $result['assets']));

if ( ! str_contains($result['serialized_blocks'], 'blocks-engine-synthetic-video-figure-fill') ) {
    throw new RuntimeException('A source video filling both axes marks its core/video figure as a fill figure.');
}
if ( ! str_contains($css, ':root :where(figure.blocks-engine-synthetic-video-figure-fill)>video{width:100%;height:100%}') ) {
    throw new RuntimeException('Fill sizing reaches the <video> inside the fill figure so it keeps the source frame box.');
}

echo "Video figure fill child contract passed.\n";
