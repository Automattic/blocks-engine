<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\Css\CssSelectorMatcher;
use Automattic\BlocksEngine\PhpTransformer\Css\CssStylesheetTransformer;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

// A block-level, auto-centered logo inside a plain inline link (Start Bootstrap
// Agency's client row). In the source the image breaks out of the inline link
// and centers across the column. Core saves the link as an inline-block anchor
// that shrink-wraps the image and pins it to the line start, so the synthetic
// figure's anchor must stay inline, as the source link was.
$anchorDisplay = static function (string $html): array {
    $result = (new HtmlTransformer())->transform($html)->toArray();
    $document = new DOMDocument();
    @$document->loadHTML($result['serialized_blocks']);
    $anchor = $document->getElementsByTagName('figure')->item(0)?->getElementsByTagName('a')->item(0);
    if (!$anchor instanceof DOMElement) throw new RuntimeException('Expected a linked image figure: ' . $result['serialized_blocks']);
    $displays = array();
    foreach ($result['assets'] as $asset) {
        if ('css' !== ($asset['kind'] ?? '')) continue;
        (new CssStylesheetTransformer())->visitStyleRules($asset['content'], static function (string $prelude, string $body) use ($anchor, &$displays): void {
            if (!preg_match('/(?:^|;)\s*display\s*:\s*([a-z-]+)/', $body, $display)) return;
            foreach (CssStylesheetTransformer::splitSelectorList($prelude) ?? array() as $selector) {
                if (CssSelectorMatcher::matches($anchor, CssSelectorMatcher::parse($selector))['matches']) $displays[] = $display[1];
            }
        });
    }
    return array($displays, $result['serialized_blocks']);
};

$css = '<style>.d-block{display:block!important}.mx-auto{margin-left:auto!important;margin-right:auto!important}.img-brand{height:2.75rem}</style>';
[$displays, $markup] = $anchorDisplay($css . '<div class="col"><a href="#!"><img class="img-fluid img-brand d-block mx-auto" src="/logo.svg" alt="Logo"></a></div>');
if (!in_array('inline', $displays, true)) {
    throw new RuntimeException('The block image\'s source link must stay inline so the image centers on its column: ' . $markup);
}

// An inline image in the same link keeps core's anchor box.
[$displays, $markup] = $anchorDisplay('<div class="col"><a href="#!"><img src="/logo.svg" alt="Logo" width="120" height="40"></a></div>');
if (in_array('inline', $displays, true)) {
    throw new RuntimeException('An inline linked image must not restyle its anchor: ' . $markup);
}
echo "OK: block image in inline link keeps an inline anchor\n";
