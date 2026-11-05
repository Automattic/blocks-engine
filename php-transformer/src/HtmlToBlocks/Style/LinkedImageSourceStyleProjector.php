<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style;

use Automattic\BlocksEngine\PhpTransformer\Css\CssSelectorMatcher;
use Automattic\BlocksEngine\PhpTransformer\Css\CssSpecificityProjection;
use Automattic\BlocksEngine\PhpTransformer\Css\CssStylesheetTransformer;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Session\SourceTargetProjectionState;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Support\SourceDom;
use DOMElement;

/** Neutralize source-false display rules in sheets also loaded without projection. */
final class LinkedImageSourceStyleProjector
{
    public function __construct(private readonly StyleResolver $styles) {}

    public function project(DOMElement $image, DOMElement $link, DOMElement $figure, string $classes, string $marker, AuthorStyleAnalysis $author, SourceTargetProjectionState $targets): void
    {
        $wrapper = $figure->parentNode;
        if (!$wrapper instanceof DOMElement || 'div' !== strtolower($wrapper->tagName)
            || !in_array('wp-block-image', preg_split('/\s+/', $wrapper->getAttribute('class')) ?: array(), true)) return;
        // Evaluate the destination topology in a separate document: never mutate
        // the source DOM or invalidate its selector-match caches.
        $dom = new \DOMDocument();
        $dom->appendChild($dom->importNode($image->ownerDocument->documentElement, true));
        $copy = (new \DOMXPath($dom))->query($figure->getNodePath() ?? '')->item(0);
        if (!$copy instanceof DOMElement) return;
        $parent = $copy->parentNode;
        if (!$parent instanceof DOMElement || 'div' !== strtolower($parent->tagName)
            || !in_array('wp-block-image', preg_split('/\s+/', $parent->getAttribute('class')) ?: array(), true)) return;
        $copy->setAttribute('class', 'wp-block-image ' . $classes);
        $parent->parentNode?->replaceChild($copy, $parent);
        $nativeLink = $copy->getElementsByTagName('a')->item(0);
        $nativeImage = $copy->getElementsByTagName('img')->item(0);
        if (!$nativeLink instanceof DOMElement || !$nativeImage instanceof DOMElement) return;
        (new CssStylesheetTransformer())->visitStyleRules($author->combinedCss(), function (string $prelude, string $body, array $conditions) use ($image, $link, $nativeImage, $nativeLink, $marker, $author, $targets): void {
            $display = $this->styles->verbatimCssDeclarations($body)['display'] ?? '';
            if ('' === $display) return;
            foreach (CssStylesheetTransformer::splitSelectorList($prelude) ?? array() as $selector) {
                $parsed = CssSelectorMatcher::parse($selector);
                if (!$parsed['supported']) continue;
                foreach (array(array($image, $nativeImage, '>img'), array($link, $nativeLink, '')) as [$source, $native, $tail]) {
                    if ((CssSelectorMatcher::matches($source, $parsed)['matches'] ?? false) || !(CssSelectorMatcher::matches($native, $parsed)['matches'] ?? false)) continue;
                    $value = $this->styles->declaredPresentation($source, 'display')->resolvedValue();
                    if ('' === $value) $value = $this->styles->defaultTagDisplay($source);
                    $target = ':root :where(.' . $marker . $tail . ')' . CssSpecificityProjection::shims($parsed, $author->specificityShim(), $author->classSpecificityShim(), $author->idSpecificityShim());
                    $targets->record(SourceDom::elementSelector($source), $target, 'display:' . $value . (str_contains($display, '!important') ? '!important' : ''), $conditions);
                }
            }
        });
    }
}
