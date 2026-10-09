<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;
use Automattic\BlocksEngine\PhpTransformer\Css\CssSelectorMatcher;
use Automattic\BlocksEngine\PhpTransformer\Css\CssStylesheetTransformer;
use Automattic\BlocksEngine\PhpTransformer\WordPress\Runtime;

$runtime = new Runtime();
foreach ( require dirname(__DIR__) . '/fixtures/image-transform-reference-box.php' as $fixture ) {
    $result = (new HtmlTransformer())->transform('<style>' . $fixture['css'] . '</style>' . $fixture['html'])->toArray();
    $markup = $result['serialized_blocks'];
    $validity = $runtime->validateBlockSerialization($markup);
    if (array() !== ($validity['findings'] ?? array()) || str_contains($markup, '<!-- wp:html') || 1 !== substr_count($markup, '<!-- wp:image')) {
        throw new RuntimeException($fixture['name'] . ': expected one valid native editable image');
    }
    if ($markup !== $runtime->serializeBlocks($runtime->parseBlocks($markup))) {
        throw new RuntimeException($fixture['name'] . ': native image save/parse identity lost');
    }
    if ('authored figure owns its transform' !== $fixture['name']) {
        $document = new DOMDocument();
        @$document->loadHTML($markup);
        $image = $document->getElementsByTagName('img')->item(0);
        $figure = $document->getElementsByTagName('figure')->item(0);
        $leafPlacement = false;
        $figurePlacement = false;
        foreach ($result['assets'] as $asset) {
            if ('css' !== ($asset['kind'] ?? '')) continue;
            (new CssStylesheetTransformer())->visitStyleRules($asset['content'], static function (string $prelude, string $body) use ($image, $figure, &$leafPlacement, &$figurePlacement): void {
                if (!preg_match('/(?:^|;)\s*(?:transform|translate|scale)\s*:/', $body)) return;
                foreach (CssStylesheetTransformer::splitSelectorList($prelude) ?? array() as $selector) {
                    $parsed = CssSelectorMatcher::parse($selector);
                    $leafPlacement = $leafPlacement || CssSelectorMatcher::matches($image, $parsed)['matches'];
                    $figurePlacement = $figurePlacement || CssSelectorMatcher::matches($figure, $parsed)['matches'];
                }
            });
        }
        if (!$leafPlacement || $figurePlacement) {
            throw new RuntimeException($fixture['name'] . ': placement must bind the image leaf, not the synthetic figure reference box');
        }
    }
}
echo "Native image transform reference-box and save contract passed (9 fixtures)\n";
