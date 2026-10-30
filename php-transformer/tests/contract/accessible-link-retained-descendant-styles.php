<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\Css\CssSelectorMatcher;
use Automattic\BlocksEngine\PhpTransformer\Css\CssStylesheetTransformer;

/**
 * Contract coverage for issue #3139.
 *
 * A link whose aria-label differs from its visible text becomes the generated
 * accessible-link block and keeps its descendants. Those descendants keep
 * their source identity: the single wrapper child stays a
 * `<span class="SB__container">`, and every front-end author rule that reached
 * a descendant in the source still reaches the emitted element, rather than
 * a paragraph carrier the block never renders.
 */

$assert = static function (bool $condition, string $message, string $detail = ''): void {
    if (! $condition) {
        throw new RuntimeException($message . ('' === $detail ? '' : "\n" . substr($detail, 0, 3000)));
    }
};

$css = '.SB__container{display:flex;align-items:center;width:100%;height:100%}'
    . '.SB__icon{flex-shrink:0;height:50px}'
    . '#comp-1 .style-1__root{border-radius:50px;background:#000;padding-left:34px}'
    . '#comp-1 .style-1__root .SB__label{color:#fff;font-size:20px}';
$html = '<!doctype html><html><head><title>Start</title><style>' . $css . '</style></head><body>'
    . '<main><section><div id="comp-1">'
    . '<a href="https://example.com/start" class="SB__root style-1__root" aria-label="Get started creating your website">'
    . '<span class="SB__container"><span class="SB__label">Get Started</span><span class="SB__icon" aria-hidden="true"><span>&gt;</span></span></span>'
    . '</a></div></section></main></body></html>';

$result = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array('index.html' => $html)))->toArray();
$markup = (string) ($result['serialized_blocks'] ?? '');
$assert(str_contains($markup, '<!-- wp:custom/accessible-link '), 'The distinct aria-label lowers the link to the accessible-link block.', $markup);

// The front-end stylesheets the page loads, in document order.
$plan = $result['source_reports']['wordpress_site_plan'] ?? array();
$frontend = '';
foreach ($plan['assets'] ?? array() as $asset) {
    if ('css' === ($asset['kind'] ?? null) && in_array($asset['stylesheet_target'] ?? 'both', array('frontend', 'both'), true)) {
        $frontend .= (string) ($asset['content'] ?? '') . "\n";
    }
}
$assert(str_contains($frontend, 'SB__label'), 'The front-end author stylesheet carries the projected label rule.', $frontend);

$document = new DOMDocument();
$previous = libxml_use_internal_errors(true);
$document->loadHTML('<?xml encoding="utf-8" ?><!doctype html><html><body>' . $markup . '</body></html>');
libxml_clear_errors();
libxml_use_internal_errors($previous);
$xpath = new DOMXPath($document);
$byClass = static function (string $class) use ($xpath): ?DOMElement {
    $node = $xpath->query('//a//*[contains(concat(" ", normalize-space(@class), " "), " ' . $class . ' ")]')->item(0);
    return $node instanceof DOMElement ? $node : null;
};

$container = $byClass('SB__container');
$label = $byClass('SB__label');
$assert($container instanceof DOMElement && 'span' === $container->tagName, 'The emitted link keeps the source wrapper as <span class="SB__container">.', $markup);
$assert($label instanceof DOMElement && $label->parentNode === $container, 'The label stays inside the retained container.', $markup);

// Every non-editor front-end rule that reaches $element, as declaration bodies.
$bodies = static function (DOMElement $element) use ($frontend): array {
    $matched = array();
    (new CssStylesheetTransformer())->transformStyleRules($frontend, static function (string $prelude, string $body) use ($element, &$matched): string {
        foreach (CssStylesheetTransformer::splitSelectorList($prelude) ?? array() as $selector) {
            $selector = trim($selector);
            if (str_contains($selector, '.editor-styles-wrapper')) {
                continue;
            }
            $match = CssSelectorMatcher::matches($element, CssSelectorMatcher::parse($selector));
            if ($match['supported'] && $match['matches']) {
                $matched[] = $body;
                break;
            }
        }
        return $prelude . '{' . $body . '}';
    });
    return $matched;
};
$declares = static function (array $bodies, string $property, string $value): bool {
    $pattern = '/(?:^|;)\s*' . preg_quote($property, '/') . '\s*:\s*(?:' . preg_quote($value, '/') . '|var\([^;{}]*,\s*' . preg_quote($value, '/') . '\s*\))\s*(?:!important)?\s*(?:;|$)/i';
    foreach ($bodies as $body) {
        if (1 === preg_match($pattern, $body)) {
            return true;
        }
    }
    return false;
};

$labelBodies = $bodies($label);
$assert($declares($labelBodies, 'color', '#fff'), 'A front-end rule carrying color:#fff matches the emitted label.', $frontend);
$assert($declares($labelBodies, 'font-size', '20px'), 'A front-end rule carrying font-size:20px matches the emitted label.', $frontend);
$assert($declares($bodies($container), 'display', 'flex'), 'A front-end rule carrying display:flex matches the emitted container.', $frontend);

$icon = $byClass('SB__icon');
$assert($icon instanceof DOMElement && $declares($bodies($icon), 'flex-shrink', '0'), 'A front-end rule carrying flex-shrink:0 matches the emitted icon.', $frontend);

echo "accessible-link retained descendant styles contract passed.\n";
