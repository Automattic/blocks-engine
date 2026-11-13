<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;
use Automattic\BlocksEngine\PhpTransformer\Css\CssSelectorMatcher;
use Automattic\BlocksEngine\PhpTransformer\Css\CssStylesheetTransformer;

// A shared utility must still space ordinary source boxes, but must not
// duplicate an image's margin on the synthetic native figure.
$result = (new HtmlTransformer())->transform('<style>.space{margin-bottom:1rem!important}img{display:block}</style><div class="space">Text</div><img class="space" src="/photo.jpg">')->toArray();
$document = new DOMDocument();
@$document->loadHTML($result['serialized_blocks']);
$figure = $document->getElementsByTagName('figure')->item(0);
$image = $document->getElementsByTagName('img')->item(0);
$ordinary = (new DOMXPath($document))->query('//*[contains(concat(" ",normalize-space(@class)," ")," space ") and not(self::figure)]')->item(0);
if (!$figure || !$image || !$ordinary) throw new RuntimeException('Missing editable source subjects');
$matched = array('image' => false, 'ordinary' => false);
foreach ($result['assets'] as $asset) {
    if ('css' !== ($asset['kind'] ?? '')) continue;
    (new CssStylesheetTransformer())->visitStyleRules($asset['content'], static function (string $prelude, string $body) use ($figure, $image, $ordinary, &$matched): void {
        if (!preg_match('/margin-bottom\s*:\s*1rem/', $body)) return;
        foreach (CssStylesheetTransformer::splitSelectorList($prelude) ?? array() as $selector) {
            $parsed = CssSelectorMatcher::parse($selector);
            if (CssSelectorMatcher::matches($figure, $parsed)['matches']) throw new RuntimeException('Image margin replayed on synthetic figure: ' . $selector);
            foreach (array('image' => $image, 'ordinary' => $ordinary) as $name => $element) {
                $matched[$name] = $matched[$name] || CssSelectorMatcher::matches($element, $parsed)['matches'];
            }
        }
    });
}
if (in_array(false, $matched, true)) throw new RuntimeException('Lost source margin: ' . json_encode($matched));
echo "Image leaf margin routing passed\n";
