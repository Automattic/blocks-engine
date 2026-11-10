<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\Css\CssSelectorMatcher;
use Automattic\BlocksEngine\PhpTransformer\Css\CssStylesheetTransformer;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

// A flex avatar ("C") lowers to a zero-margin paragraph inside a CSS-owned flow
// row. Each converted document ships its own engine stylesheet, so a later
// document's flow revert lands after this document's zero-margin reset at the
// same weight. Only the revert selectors themselves can keep the box at 0.
$html = '<!doctype html><style>.row{display:flex;align-items:center;gap:12px}'
    . '.avatar{width:40px;height:40px;display:flex;align-items:center;justify-content:center}</style>'
    . '<div class="row"><div class="avatar">C</div><div><div>Camila S.</div><div>Direito</div></div></div>';
$result = (new HtmlTransformer())->transform($html)->toArray();
$css = implode("\n", array_column($result['assets'] ?? array(), 'content'));

$dom = new DOMDocument();
@$dom->loadHTML('<!doctype html><html><body>' . $result['serialized_blocks'] . '</body></html>');
$avatar = (new DOMXPath($dom))->query('//p[contains(concat(" ", @class, " "), " blocks-engine-lowered-paragraph ")]')->item(0);
if (!$avatar instanceof DOMElement || !str_contains((string) $avatar->parentNode?->getAttribute('class'), 'blocks-engine-css-owned-flow')) {
    throw new RuntimeException('Fixture must lower the avatar to a paragraph inside a CSS-owned flow group.');
}

preg_match_all('/([^{}]+)\{([^{}]*)\}/', $css, $rules, PREG_SET_ORDER);
$reverting = array();
foreach ($rules as $rule) {
    if (!preg_match('/margin(?:-block)?-(?:top|bottom|start|end)\s*:\s*revert/', $rule[2])) {
        continue;
    }
    foreach (CssStylesheetTransformer::splitSelectorList(trim($rule[1])) ?? array() as $selector) {
        $parsed = CssSelectorMatcher::parse(trim($selector));
        if (!$parsed['supported']) {
            throw new RuntimeException('Flow revert selector must stay matchable: ' . $selector);
        }
        if (CssSelectorMatcher::matches($avatar, $parsed)['matches'] ?? false) {
            $reverting[] = trim($selector);
        }
    }
}
if (array() !== $reverting) {
    throw new RuntimeException('Flow margin reverts must not reach the zero-margin avatar: ' . implode(' | ', $reverting));
}
echo "flow-revert-spares-zero-margin-boxes: ok\n";
