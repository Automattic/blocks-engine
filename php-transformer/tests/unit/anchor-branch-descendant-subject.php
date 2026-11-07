<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\Css\CssSelectorMatcher;
use Automattic\BlocksEngine\PhpTransformer\Css\CssStylesheetTransformer;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

// The device gate invokes ancestry identity projection, rather than merely
// replaying the original descendant selector. Exercise marker and ID carriers.
foreach (array('', ' id="image-leaf"') as $imageIdentity) {
    $result = (new HtmlTransformer())->transform('<style>:where([data-blocks-engine-capture-device-document="desktop"]) .wrap img{filter:url(#f)}</style><main data-blocks-engine-capture-device-document="desktop"><div id="image-frame" class="wrap"><img' . $imageIdentity . ' src="/photo.jpg" alt="Photo" width="100" height="100"></div></main>', array())->toArray();
    $document = new DOMDocument();
    @$document->loadHTML($result['serialized_blocks']);
    // Device scopes are carried by the runtime canvas rather than block markup.
    $document->getElementsByTagName('body')->item(0)->setAttribute('data-be-d', 'desktop');
    $xpath = new DOMXPath($document);
    $wrapper = $xpath->query('//*[@id="image-frame"]')->item(0);
    $image = $document->getElementsByTagName('img')->item(0);
    $matchesWrapper = false;
    $matchesImage = false;
    foreach ($result['assets'] as $asset) {
        if ('css' !== ($asset['kind'] ?? '')) continue;
        (new CssStylesheetTransformer())->visitStyleRules($asset['content'], static function (string $prelude, string $body) use ($wrapper, $image, &$matchesWrapper, &$matchesImage): void {
            if (!str_contains($body, 'filter:')) return;
            foreach (CssStylesheetTransformer::splitSelectorList($prelude) ?? array() as $selector) {
                $parsed = CssSelectorMatcher::parse($selector);
                if (!$parsed['supported']) throw new RuntimeException('Unsupported filter selector: ' . $selector);
                foreach (array($wrapper, $image?->parentNode) as $box) {
                    $matchesWrapper = $matchesWrapper || ($box instanceof DOMElement && CssSelectorMatcher::matches($box, $parsed)['matches']);
                }
                $matchesImage = $matchesImage || ($image instanceof DOMElement && CssSelectorMatcher::matches($image, $parsed)['matches']);
            }
        });
    }
    $failures = array();
    if (!$wrapper instanceof DOMElement) $failures[] = 'The wrapper retains its identity.';
    if ($matchesWrapper) $failures[] = 'The descendant filter rule must not match the wrapper.';
    if (!$matchesImage) $failures[] = 'The descendant filter rule must match the image.';
    if (array() !== $failures) {
        fwrite(STDERR, implode("\n", $failures) . "\n");
        exit(1);
    }
}
echo "OK: anchor branches retain descendant subjects\n";
