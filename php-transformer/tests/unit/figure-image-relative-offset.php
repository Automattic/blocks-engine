<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\Css\CssSelectorMatcher;
use Automattic\BlocksEngine\PhpTransformer\Css\CssStylesheetTransformer;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;
use Automattic\BlocksEngine\PhpTransformer\WordPress\Runtime;

// Issue #6445: a focal-point crop shifts the image inside its source figure
// (`position:relative;top:-156px`). The figure block is the source figure, so
// the image's relative offset must stay on the image leaf or the copy shows
// the top of the image instead of the source's crop. Its inline definite
// width overflows the frame in the source, which never clamps it on screen (a
// print-only clamp does not count), so core's `max-width:100%` must not either.
$leafRules = static function (string $style): array {
    $html = '<style>@media print{img{max-width:100% !important}}.frame{position:relative;overflow:hidden;height:432px}.content-fill{position:absolute;top:0;right:0;bottom:0;left:0}</style>'
        . '<div class="frame"><figure id="thumbnail" class="content-fill" style="overflow: hidden"><img data-image-focal-point="0.5,0.5" style="' . $style . '" alt="Transient" src="/media/hero.webp"></figure></div>';
    $result = (new HtmlTransformer())->transform($html)->toArray();
    $markup = $result['serialized_blocks'];
    if (array() !== ((new Runtime())->validateBlockSerialization($markup)['findings'] ?? array()) || 1 !== substr_count($markup, '<!-- wp:image')) {
        throw new RuntimeException('Expected one valid native image block.');
    }
    $document = new DOMDocument();
    @$document->loadHTML($markup);
    $image = $document->getElementsByTagName('img')->item(0);
    $figure = $document->getElementsByTagName('figure')->item(0);
    $leaf = array();
    $figureRules = array();
    foreach ($result['assets'] as $asset) {
        if ('css' !== ($asset['kind'] ?? '')) continue;
        (new CssStylesheetTransformer())->visitStyleRules($asset['content'], static function (string $prelude, string $body) use ($image, $figure, &$leaf, &$figureRules): void {
            foreach (CssStylesheetTransformer::splitSelectorList($prelude) ?? array() as $selector) {
                $parsed = CssSelectorMatcher::parse($selector);
                if (CssSelectorMatcher::matches($image, $parsed)['matches']) $leaf[] = $body;
                if (CssSelectorMatcher::matches($figure, $parsed)['matches']) $figureRules[] = $body;
            }
        });
    }
    return array(implode(';', $leaf), implode(';', $figureRules));
};

[$leafBody, $figureBody] = $leafRules('position: relative;font-size: 0px;left: 0px;top: -155.812px;width: 1440px;height: 959.609px');
if (! preg_match('/(?:^|;)\s*position\s*:\s*relative\b/', $leafBody) || ! preg_match('/(?:^|;)\s*top\s*:\s*-155\.812px\b/', $leafBody)) {
    throw new RuntimeException('The image leaf must keep its source relative offset: ' . $leafBody);
}
if (! preg_match('/(?:^|;)\s*max-width\s*:\s*none\b/', $leafBody)) {
    throw new RuntimeException('The image leaf must keep the source unclamped width: ' . $leafBody);
}
if (str_contains($figureBody, '-155.812px')) {
    throw new RuntimeException('The source figure must not take the image offset.');
}

// A viewport-sized crop's pixel offsets were measured at the capture width
// only; they must follow the frame at every width (centred focal point).
[$leafBody] = $leafRules('position: relative;font-size: 0px;left: 0px;top: -226.312px;width: max(674px, 100vw);height: max(449px, 66.64vw)');
$top = 'calc(-1 * clamp(0px, 0.5 * max(449px, 66.64vw) - 50%, max(449px, 66.64vw) - 100%))';
$left = 'calc(-1 * clamp(0px, 0.5 * max(674px, 100vw) - 50%, max(674px, 100vw) - 100%))';
if (! str_contains($leafBody, 'top:' . $top) || ! str_contains($leafBody, 'left:' . $left) || str_contains($leafBody, '-226.312px')) {
    throw new RuntimeException('A viewport-sized crop must re-express its offsets against the frame: ' . $leafBody);
}
echo "Figure image relative offset stays on the image leaf\n";
