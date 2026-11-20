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

// A video sized by an authored aspect-ratio (Tailwind `aspect-video w-full`)
// letterboxes a portrait clip inside a 16:9 box. Projected, the ratio sizes
// the figure, so the <video> must fill that figure instead of growing it to
// the clip's intrinsic portrait height, with no Core figure margin added.
$ratioHtml = '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><title>Ratio video</title><style>'
    . '.frame{max-width:56rem;margin:0 auto;overflow:hidden}'
    . '.aspect-video{aspect-ratio:16/9}.w-full{width:100%}'
    . '</style></head><body>'
    . '<div class="frame"><video src="portrait-270x480.mp4" controls playsinline class="aspect-video w-full"></video></div>'
    . '</body></html>';
$ratio = (new HtmlTransformer())->transform($ratioHtml)->toArray();
$ratioCss = implode("\n", array_map(static fn(array $asset): string => (string) ($asset['content'] ?? ''), $ratio['assets']));
if ( ! preg_match('/<figure class="wp-block-video aspect-video w-full blocks-engine-synthetic-video-figure-ratio">/', $ratio['serialized_blocks']) ) {
    throw new RuntimeException('A source video sized by an authored aspect-ratio marks its core/video figure as a ratio figure.');
}
if ( ! str_contains($ratioCss, ':root :where(figure.blocks-engine-synthetic-video-figure-ratio)>video{width:100%;height:100%}')
    || ! str_contains($ratioCss, ':root :where(figure.blocks-engine-synthetic-video-figure-ratio){margin:0}') ) {
    throw new RuntimeException('The <video> fills its ratio figure, which takes no Core figure margin.');
}
if ( str_contains($ratioCss, 'figure.blocks-engine-synthetic-video-figure-ratio){width:100%;height:100%}') ) {
    throw new RuntimeException('A ratio figure keeps its authored height axis automatic.');
}

// A CSS-owned flow group reverts its children's margins except on the zero-margin
// boxes. The ratio figure joins that list only in a document that serializes
// one, so every other document's flow revert stays byte for byte.
$support = new \Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\EngineSupportCss();
$flowGroup = '<div class="wp-block-group blocks-engine-css-owned-flow">';
$withRatio = implode("\n", array_merge($support->beforeAuthorCss($flowGroup . '<figure class="wp-block-video blocks-engine-synthetic-video-figure-ratio"></figure></div>'), $support->coreDefaultResetCss($flowGroup . '<figure class="wp-block-video blocks-engine-synthetic-video-figure-ratio"></figure></div>')));
$withoutRatio = implode("\n", array_merge($support->beforeAuthorCss($flowGroup . '<p>Text</p></div>'), $support->coreDefaultResetCss($flowGroup . '<p>Text</p></div>')));
if ( ! str_contains($withRatio, '>*:not(.blocks-engine-synthetic-paragraph,.blocks-engine-synthetic-embed-figure,.blocks-engine-synthetic-image-figure,.blocks-engine-synthetic-video-figure-fill,.blocks-engine-lowered-paragraph,.blocks-engine-synthetic-video-figure-ratio)){margin-block-start:revert') ) {
    throw new RuntimeException('A ratio video figure keeps its zero margin inside a CSS-owned flow group.');
}
if ( str_contains($withoutRatio, 'blocks-engine-synthetic-video-figure-ratio') ) {
    throw new RuntimeException('A document without a ratio video figure keeps its flow revert selectors unchanged.');
}

echo "Video figure fill child contract passed.\n";
