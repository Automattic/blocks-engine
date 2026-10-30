<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\Css\CssSelectorMatcher;
use Automattic\BlocksEngine\PhpTransformer\Css\CssStylesheetTransformer;

$assert = static function (bool $condition, string $message): void {
    if (! $condition) {
        throw new RuntimeException($message);
    }
};

$result = (new ArtifactCompiler())->compile(require dirname(__DIR__) . '/fixtures/linked-native-button-icon.php')->toArray();
$markup = (string) ($result['serialized_blocks'] ?? '');
preg_match_all('/<!-- wp:button (\{.*?\}) -->/', $markup, $matches);
$assert(2 === count($matches[1]), 'Both linked native buttons become core/button.');
foreach ($matches[1] as $index => $json) {
    $attrs = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
    $style = $attrs['style'] ?? array();
    $assert('#fff' === ($style['color']['background'] ?? null), "Button $index keeps its white background.");
    $assert('6px' === ($style['border']['radius'] ?? null), "Button $index keeps its radius.");
    $assert(array('top' => '24px', 'right' => '28px', 'bottom' => '24px', 'left' => '28px') === ($style['spacing']['padding'] ?? null), "Button $index keeps its authored padding.");
}
$assert(! str_contains($markup, 'is-style-outline'), 'Neither filled button is restyled as an outline.');
$assert(! str_contains($markup, 'background-color:transparent'), 'Neither filled button gets a transparent background.');

$document = new DOMDocument();
$previous = libxml_use_internal_errors(true);
$document->loadHTML('<?xml encoding="utf-8" ?><html><body>' . $markup . '</body></html>');
libxml_clear_errors();
libxml_use_internal_errors($previous);
$xpath = new DOMXPath($document);
$link = $xpath->query('//a[@href="#quote"]')->item(0);
$assert($link instanceof DOMElement, 'The icon button keeps its navigation destination.');
$assert(0 === $document->getElementsByTagName('button')->length, 'The nested native button is replaced, not retained as an interactive descendant.');
$assert(1 === $xpath->query('./img[contains(@src,"materialized-svg/")]', $link)->length, 'The SVG icon is materialized directly beside the label.');
$assert('Request a quote' === trim($link->firstChild->textContent ?? ''), 'The label and icon are sibling flex items.');

// Verify that the emitted front-end rules reach the link itself, not just
// its block wrapper: inline-flex defaults to a non-wrapping horizontal row.
$frontend = '';
foreach ($result['source_reports']['wordpress_site_plan']['assets'] ?? array() as $asset) {
    if ('css' === ($asset['kind'] ?? null) && in_array($asset['stylesheet_target'] ?? 'both', array('frontend', 'both'), true)) {
        $frontend .= (string) ($asset['content'] ?? '') . "\n";
    }
}
$declarations = array();
(new CssStylesheetTransformer())->transformStyleRules($frontend, static function (string $prelude, string $body) use ($link, &$declarations): string {
    foreach (CssStylesheetTransformer::splitSelectorList($prelude) ?? array() as $selector) {
        if (str_contains($selector, '.editor-styles-wrapper')) {
            continue;
        }
        $match = CssSelectorMatcher::matches($link, CssSelectorMatcher::parse(trim($selector)));
        if ($match['supported'] && $match['matches']) {
            foreach (explode(';', $body) as $declaration) {
                $parts = explode(':', $declaration, 2);
                if (2 === count($parts)) {
                    $declarations[trim($parts[0])] = trim(str_replace('!important', '', $parts[1]));
                }
            }
            break;
        }
    }
    return $prelude . '{' . $body . '}';
});
$assert('inline-flex' === ($declarations['display'] ?? null), 'The button surface keeps inline-flex, placing the label and icon on one row.');
$assert('row' === ($declarations['flex-direction'] ?? 'row'), 'The button surface is horizontal.');
$assert('nowrap' === ($declarations['flex-wrap'] ?? 'nowrap'), 'The icon cannot wrap onto a separate flex line.');
$assert('center' === ($declarations['align-items'] ?? null), 'The button surface keeps centered label/icon alignment.');
$assert('8px' === ($declarations['gap'] ?? null), 'The button surface keeps its label/icon gap.');

echo "Linked native button icon contract passed.\n";
