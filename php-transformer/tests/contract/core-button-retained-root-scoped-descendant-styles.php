<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\Css\CssSelectorMatcher;
use Automattic\BlocksEngine\PhpTransformer\Css\CssStylesheetTransformer;

/**
 * Contract coverage for issue #3218.
 *
 * A link whose aria-label equals its visible text becomes core/button and
 * keeps its descendant markup inside wp-block-button__link. Those retained
 * descendants keep their source identity: the anchor keeps its own classes,
 * the single container child stays in place, and author rules scoped through
 * the source root (including its :hover variant) still reach the emitted
 * elements on the front end.
 *
 * The anchor keeps the source's `wixui-button` class: the reduced `SB__`
 * class names carry no button token, and without padding the anchor has no
 * style signal either, so that class is what makes the recognizer take the
 * core/button path, as it does for the wix.com source.
 */

$assert = static function (bool $condition, string $message, string $detail = ''): void {
    if (! $condition) {
        throw new RuntimeException($message . ('' === $detail ? '' : "\n" . substr($detail, 0, 4000)));
    }
};

$css = '.SB__root{display:block;background:#0e1f85;border-radius:50px}'
    . '.SB__container{display:flex;align-items:center}'
    . '.SB__label{color:#fff}'
    . '.SB__icon{flex-shrink:0;height:50px}'
    . '#comp-1 .style-1__root .SB__icon{width:17px;height:17px}'
    . '#comp-1 .style-1__root:hover .SB__icon{fill:#fff}';
$html = '<!doctype html><html><head><title>Start</title><style>' . $css . '</style></head><body>'
    . '<div id="comp-1"><a class="SB__root style-1__root wixui-button" aria-label="Start Now" href="https://example.com/">'
    . '<span class="SB__container"><span class="SB__label">Start Now</span>'
    . '<span class="SB__icon" aria-hidden="true"><span><svg viewBox="0 0 12 8" width="12" height="8"><path d="M0 0h12v8H0z"/></svg></span></span>'
    . '</span></a></div></body></html>';

$result = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array('index.html' => $html)))->toArray();
$markup = (string) ($result['serialized_blocks'] ?? '');
$assert(str_contains($markup, '<!-- wp:button'), 'The link whose aria-label equals its text lowers to core/button.', $markup);
$assert(! str_contains($markup, 'accessible-link'), 'The link does not take the accessible-link path.', $markup);

// The front-end stylesheets the page loads, in document order.
$plan = $result['source_reports']['wordpress_site_plan'] ?? array();
$frontend = '';
foreach ($plan['assets'] ?? array() as $asset) {
    if ('css' === ($asset['kind'] ?? null) && in_array($asset['stylesheet_target'] ?? 'both', array('frontend', 'both'), true)) {
        $frontend .= (string) ($asset['content'] ?? '') . "\n";
    }
}
$assert(str_contains($frontend, 'SB__icon'), 'The front-end author stylesheet carries the projected icon rules.', $frontend);

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

$icon = $byClass('SB__icon');
$container = $byClass('SB__container');
$assert($icon instanceof DOMElement, 'The emitted button link retains the icon span.', $markup);
$assert($container instanceof DOMElement, 'The emitted button link retains <span class="SB__container">.', $markup);

// Every non-editor front-end rule (selector, body) that reaches $element.
$rules = static function (DOMElement $element, ?callable $rewrite = null) use ($frontend): array {
    $matched = array();
    (new CssStylesheetTransformer())->transformStyleRules($frontend, static function (string $prelude, string $body) use ($element, $rewrite, &$matched): string {
        foreach (CssStylesheetTransformer::splitSelectorList($prelude) ?? array() as $selector) {
            $selector = trim($selector);
            if (str_contains($selector, '.editor-styles-wrapper')) {
                continue;
            }
            if (null !== $rewrite) {
                $selector = $rewrite($selector);
                if (null === $selector) {
                    continue;
                }
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

$assert($declares($rules($icon), 'width', '17px'), 'A front-end rule carrying width:17px matches the emitted icon.', $frontend . "\n\n" . $markup);
$assert($declares($rules($container), 'display', 'flex'), 'A front-end rule carrying display:flex matches the emitted container.', $frontend . "\n\n" . $markup);

// The :hover variant, with :hover stripped, matches the emitted icon chain.
$hoverBodies = $rules($icon, static function (string $selector): ?string {
    return str_contains($selector, ':hover') ? str_replace(':hover', '', $selector) : null;
});
$assert($declares($hoverBodies, 'fill', '#fff'), 'The projected :hover rule (with :hover stripped) matches the emitted icon through its anchor chain.', $frontend . "\n\n" . $markup);

echo "core/button retained root-scoped descendant styles contract passed.\n";
