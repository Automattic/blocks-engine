<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\Css\CssSelectorMatcher;
use Automattic\BlocksEngine\PhpTransformer\Css\CssStylesheetTransformer;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

$assert = static function (bool $ok, string $message): void {
    if (! $ok) throw new RuntimeException($message);
};

// A layered text effect: an inline-grid wrapper stacks its label child and
// two generated copies of the label (read from a data attribute) in one
// named grid area. The wrapper lowers to a RichText carrier mark.
$html = '<style>'
    . '.fx{display:inline-grid;grid-template-areas:"stack";color:red}'
    . '.fx>*,.fx:before,.fx:after{grid-area:stack}'
    . '.fx:before,.fx:after{content:attr(data-copy);color:transparent}'
    . '.fx .label{display:block;background-clip:text}'
    . '.box{text-align:center}'
    . '</style><main><div class="box"><div><span class="fx" data-copy="Hello" data-unused="x">'
    . '<span class="label">Hello</span></span></div></div></main>';
$result = (new HtmlTransformer())->transform($html)->toArray();
$css = '';
foreach ($result['assets'] as $asset) {
    if ('author-css' === ($asset['source'] ?? '')) $css .= $asset['content'];
}
$dom = new DOMDocument();
$dom->loadHTML('<?xml encoding="UTF-8"><body>' . $result['serialized_blocks'] . '</body>', LIBXML_NOERROR);
$xpath = new DOMXPath($dom);
$carrier = $xpath->query('//mark[contains(@style,"--blocks-engine-richtext-marker")]')->item(0);
$assert($carrier instanceof DOMElement, 'The wrapper lowers to a RichText carrier mark');
$label = $xpath->query('.//*[contains(concat(" ",@class," ")," label ")]', $carrier)->item(0);
$assert($label instanceof DOMElement && $label->parentNode === $carrier, 'The label stays a direct child of the carrier');

$assert('Hello' === $carrier->getAttribute('data-copy'), 'The carrier keeps the attribute its generated content reads');
$assert(! $carrier->hasAttribute('data-unused'), 'Attributes no generated content reads are not copied');

$declares = static function (DOMElement $element, string $declaration) use ($css): bool {
    $matched = false;
    (new CssStylesheetTransformer())->transformStyleRules($css, static function (string $selector, string $body) use ($element, $declaration, &$matched): string {
        if (str_contains($body, $declaration)) {
            foreach (CssStylesheetTransformer::splitSelectorList($selector) ?? array() as $arm) {
                $parsed = CssSelectorMatcher::parse($arm);
                $matched = $matched || ($parsed['supported'] && CssSelectorMatcher::matches($element, $parsed)['matches']);
            }
        }
        return $selector . '{' . $body . '}';
    });
    return $matched;
};
$assert($declares($carrier, 'grid-template-areas:"stack"'), 'The carrier keeps its own grid rule');
$assert($declares($label, 'grid-area:stack'), 'A child rule under the carrier still places the label');
$assert($declares($label, 'background-clip:text'), 'A descendant rule under the carrier still reaches the label');

echo "RichText carrier descendant rules passed\n";
