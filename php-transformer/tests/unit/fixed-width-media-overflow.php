<?php
declare(strict_types=1);

/**
 * Replaced media with an authored pixel width must not overflow its containing
 * block. Gutenberg already gives this guarantee for core/image and core/embed;
 * generated companions and projected inline geometry must match it.
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Generators\VisualIframeBlockGenerator;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    ++$assertions;
    if ( ! $condition ) {
        throw new RuntimeException($message);
    }
};

$cssFor = static function (array $result): string {
    return implode("\n", array_map(
        static fn (array $asset): string => (string) ($asset['content'] ?? ''),
        array_values(array_filter(
            is_array($result['assets'] ?? null) ? $result['assets'] : array(),
            static fn (array $asset): bool => 'css' === ($asset['kind'] ?? '')
        ))
    ));
};

$iframeSource = '<main><div class="host" style="width:100%"><iframe class="juxtapose" width="735" height="3178" src="https://example.test/compare"></iframe></div></main>';
$iframe = (new HtmlTransformer())->transform($iframeSource)->toArray();
$iframeMarkup = (string) ($iframe['serialized_blocks'] ?? '');
$iframeCss = $cssFor($iframe);
$iframeBlock = $iframe['blocks'][0]['innerBlocks'][0] ?? array();
$assert('custom/visual-iframe' === ($iframeBlock['blockName'] ?? null), 'pixel-width iframe uses the visual iframe companion');
$assert('735' === ($iframeBlock['attrs']['width'] ?? null) && '3178' === ($iframeBlock['attrs']['height'] ?? null), 'companion retains authored iframe dimensions');
$assert(str_contains($iframeMarkup, 'width="735"') && str_contains($iframeMarkup, 'height="3178"'), 'saved iframe markup keeps authored width and height');
$assert(
    str_contains($iframeMarkup, 'max-width:100%') || str_contains($iframeCss, 'iframe{max-width:100%}'),
    'pixel-width iframe is capped to its containing block'
);

$definition = (new VisualIframeBlockGenerator())->definition('custom');
$assert('file:./style.css' === ($definition['block_json']['style'] ?? null), 'visual iframe companion declares a stylesheet');
$assert(
    str_contains((string) ($definition['assets']['style.css'] ?? ''), 'max-width:100%'),
    'visual iframe stylesheet caps replaced iframe media'
);
$editor = (string) ($definition['assets']['index.js'] ?? '');
$assert(str_contains($editor, 'maxWidth') || str_contains($editor, 'max-width'), 'editor iframe projection caps width to the containing block');

$imageSource = '<main><div class="slide" style="width:661px"><img src="/media/slide.jpg" alt="Slide" style="width:661px"></div></main>';
$image = (new HtmlTransformer())->transform($imageSource)->toArray();
$imageMarkup = (string) ($image['serialized_blocks'] ?? '');
$imageCss = $cssFor($image);
$assert(str_contains($imageMarkup, 'width:661px') && str_contains($imageMarkup, 'height:auto'), 'pixel-width image keeps authored width and auto height');
$assert(
    1 === preg_match('/width:661px[^}]*max-width:100%|max-width:100%[^}]*width:661px/', $imageCss)
        || str_contains($imageMarkup, 'max-width:100%'),
    'pixel-width image and its projected box are capped to the containing block'
);

$videoSource = '<main><video src="/media/clip.mp4" width="800" height="450"></video></main>';
$video = (new HtmlTransformer())->transform($videoSource)->toArray();
$videoMarkup = (string) ($video['serialized_blocks'] ?? '');
$videoCss = $cssFor($video);
$assert(str_contains($videoMarkup, 'width="800"') && str_contains($videoMarkup, 'height="450"'), 'pixel-width video keeps authored dimensions');
$assert(
    str_contains($videoMarkup, 'max-width:100%') || str_contains($videoCss, 'video{max-width:100%}'),
    'pixel-width video is capped to its containing block'
);

$relative = (new HtmlTransformer())->transform('<main><img src="/media/fluid.jpg" alt="Fluid" style="width:100%"></main>')->toArray();
$relativeMarkup = (string) ($relative['serialized_blocks'] ?? '');
$assert(str_contains($relativeMarkup, 'width:100%'), 'percentage-width image keeps its relative width');

echo "Fixed-width media overflow tests passed ({$assertions} assertions)\n";
