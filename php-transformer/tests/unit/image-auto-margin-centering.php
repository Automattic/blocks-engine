<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;
use Automattic\BlocksEngine\PhpTransformer\Css\CssSelectorMatcher;
use Automattic\BlocksEngine\PhpTransformer\Css\CssStylesheetTransformer;
use Automattic\BlocksEngine\PhpTransformer\WordPress\Runtime;

$runtime = new Runtime();
foreach (array(
    '<style>.widget img{display:block;width:200px;margin:0 auto}</style><div class="widget"><img src="/logo.png"></div>',
    '<div><img src="/logo.png" style="display:block;width:200px;margin:0 auto"></div>',
    '<div><a href="/home"><img src="/logo.png" style="display:block;width:200px;margin-left:auto;margin-right:auto"></a></div>',
    '<style>.widget img{display:block;width:200px;margin-inline:auto}</style><div class="widget"><img src="/logo.png"></div>',
) as $html) {
    $result = (new HtmlTransformer())->transform($html)->toArray();
    $markup = $result['serialized_blocks'];
    if (1 !== substr_count($markup, '<!-- wp:image') || str_contains($markup, '<!-- wp:html')
        || array() !== ($runtime->validateBlockSerialization($markup)['findings'] ?? array())
        || $markup !== $runtime->serializeBlocks($runtime->parseBlocks($markup))) {
        throw new RuntimeException('Expected one valid editable image with stable serialization');
    }
    $document = new DOMDocument();
    @$document->loadHTML($markup);
    $image = $document->getElementsByTagName('img')->item(0);
    $figure = $document->getElementsByTagName('figure')->item(0);
    $leafMargin = false;
    $figureMargin = false;
    foreach ($result['assets'] as $asset) {
        if ('css' !== ($asset['kind'] ?? '')) continue;
        (new CssStylesheetTransformer())->visitStyleRules($asset['content'], static function (string $prelude, string $body) use ($image, $figure, &$leafMargin, &$figureMargin): void {
            if (!preg_match('/(?:^|;)\s*margin(?:-[a-z-]+)?\s*:[^;]*auto/', $body)) return;
            foreach (CssStylesheetTransformer::splitSelectorList($prelude) ?? array() as $selector) {
                $parsed = CssSelectorMatcher::parse($selector);
                $leafMargin = $leafMargin || CssSelectorMatcher::matches($image, $parsed)['matches'];
                $figureMargin = $figureMargin || CssSelectorMatcher::matches($figure, $parsed)['matches'];
            }
        });
    }
    if (!$leafMargin || $figureMargin || str_contains($markup, 'margin-left:auto')) {
        throw new RuntimeException('Auto margins must center the sized image leaf, not the full-width figure: ' . $html . ' leaf=' . (int) $leafMargin . ' figure=' . (int) $figureMargin . ' ' . $markup);
    }
}
echo "Image auto-margin centering passed\n";
