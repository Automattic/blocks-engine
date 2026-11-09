<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\Css\CssSelectorMatcher;
use Automattic\BlocksEngine\PhpTransformer\Css\CssStylesheetTransformer;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

// Native <button> grid items flatten both synthesized wrappers, so the link
// is the grid item and stretches to the row like the source button. A
// full-width projection must size that link, not restate display:block on
// the wrappers (which would make the link a content-height child again).
$css = '.grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:10px}'
    . '.opt{display:flex;flex-direction:column;justify-content:space-between;padding:12px;border:1px solid #ccc;text-align:left}'
    . '.tag{width:fit-content;margin-bottom:6px;font-size:10px}.lbl{font-size:12px}';
$option = static fn (string $tag, string $label): string => '<button type="button" class="opt"><span class="tag">' . $tag . '</span><span class="lbl">' . $label . '</span></button>';
$result = (new HtmlTransformer())->transform(
    '<style>' . $css . '</style><div class="grid">'
    . $option('Popular', 'Card in up to 12 instalments with a longer wrapping label') . $option('5% off', 'PIX') . $option('Flexible', 'Boleto')
    . '</div>'
)->toArray();
$styles = implode("\n", array_column($result['assets'] ?? array(), 'content'));

$dom = new DOMDocument();
@$dom->loadHTML('<!doctype html><html><body>' . $result['serialized_blocks'] . '</body></html>');
$xpath = new DOMXPath($dom);
$wrappers = $xpath->query('//*[contains(concat(" ", @class, " "), " blocks-engine-layout-neutral-buttons ") or contains(concat(" ", @class, " "), " blocks-engine-layout-child-button ")]');
$links = $xpath->query('//*[contains(concat(" ", @class, " "), " wp-block-button__link ")]');
if (6 !== $wrappers->length || 3 !== $links->length) {
    throw new RuntimeException('Each grid option must lower to a neutralized buttons/button wrapper pair around one link.');
}

preg_match_all('/([^{}]+)\{([^{}]*)\}/', $styles, $rules, PREG_SET_ORDER);
$matching = static function (DOMElement $element, string $selectors): bool {
    foreach (CssStylesheetTransformer::splitSelectorList(trim($selectors)) ?? array() as $selector) {
        $parsed = CssSelectorMatcher::parse(trim($selector));
        if ($parsed['supported'] && (CssSelectorMatcher::matches($element, $parsed)['matches'] ?? false)) {
            return true;
        }
    }
    return false;
};
foreach ($wrappers as $wrapper) {
    foreach ($rules as $rule) {
        if (preg_match('/(?:^|;)display:(?!contents)[a-z-]+!important/', $rule[2]) && $matching($wrapper, $rule[1])) {
            throw new RuntimeException('A neutralized wrapper must stay display:contents: ' . trim($rule[1]) . '{' . $rule[2] . '}');
        }
    }
}
echo "neutralized-button-width-keeps-contents: ok\n";
