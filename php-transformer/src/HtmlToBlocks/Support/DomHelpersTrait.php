<?php

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Support;

use Automattic\BlocksEngine\PhpTransformer\AssetAnalysis\SrcsetParser;
use Automattic\BlocksEngine\PhpTransformer\Support\DeterministicRowDeduplicator;
use DOMElement;
use DOMNode;

/**
 * Shared low-level DOM/HTML/string helpers.
 *
 * These are broadly-shared, behavior-neutral utilities (DOM traversal,
 * selector computation, attribute/class access, bounded/safe HTML extraction,
 * and label normalization) extracted verbatim from HtmlTransformer so future
 * decomposition slices can depend on them without dragging HtmlTransformer
 * along. Pure move: no logic or signature changes.
 */
trait DomHelpersTrait
{
    private function normalizedNavigationLabel(string $label): string
    {
        return trim(preg_replace('/\s+/', ' ', html_entity_decode($this->runtime->stripAllTags($label), ENT_QUOTES | ENT_HTML5, 'UTF-8')) ?? $label);
    }

    private function innerHtml(DOMElement $element): string
    {
        return SourceDom::innerHtml($element);
    }

    private function innerHtmlPreservingWhitespace(DOMElement $element): string
    {
        return SourceDom::innerHtmlPreservingWhitespace($element);
    }

    private function outerHtml(DOMElement $element): string
    {
        return SourceDom::outerHtml($element);
    }

    private function canonicalizedSerializationClone(DOMElement $element): DOMElement
    {
        return SourceDom::canonicalizedSerializationClone($element);
    }

    private function canonicalizeLinkUrls(DOMElement $element): void
    {
        SourceDom::canonicalizeLinkUrls($element);
    }

    private function attr(DOMElement $element, string $name): string
    {
        return SourceDom::attr($element, $name);
    }

    private function safeAnchor(string $id): string
    {
        return SourceDom::safeAnchor($id);
    }

    private function hasClass(DOMElement $element, string $className): bool
    {
        return SourceDom::hasClass($element, $className);
    }

    private function elementSelector(DOMElement $element): string
    {
        return SourceDom::elementSelector($element);
    }

    private function htmlAttributes(DOMElement $element): array
    {
        return SourceDom::htmlAttributes($element);
    }

    private function ancestorTags(DOMElement $element): array
    {
        return SourceDom::ancestorTags($element);
    }

    private function classNames(DOMElement $element): array
    {
        return SourceDom::classNames($element);
    }

    private function childElementCount(DOMElement $element): int
    {
        return SourceDom::childElementCount($element);
    }

    private function closestTagName(DOMElement $element): ?string
    {
        return SourceDom::closestTagName($element);
    }

    private function firstChildElement(DOMElement $element, string $tagName): ?DOMElement
    {
        return SourceDom::firstChildElement($element, $tagName);
    }

    private function onlyChildElement(DOMElement $element, string $tagName): ?DOMElement
    {
        return SourceDom::onlyChildElement($element, $tagName);
    }

    private function innerHtmlWithoutTags(DOMElement $element, array $excludedTags): string
    {
        return SourceDom::innerHtmlWithoutTags($element, $excludedTags);
    }

    private function safeFallbackHtml(DOMElement $element): string
    {
        $fallback = $this->sourceTagProjectedClone($element);
        if ( $fallback instanceof DOMElement ) {
            return $this->safeFallbackHtmlString(trim($fallback->ownerDocument->saveHTML($fallback) ?: ''));
        }

        return $this->safeFallbackHtmlString($this->outerHtml($element));
    }

    private function sourceTagProjectedClone(DOMElement $element): ?DOMElement
    {
        $clone = $element->cloneNode(true);
        if ( ! $clone instanceof DOMElement ) {
            return null;
        }
        $this->materializeFallbackSourceTagMarker($clone);
        foreach ( $clone->getElementsByTagName('*') as $descendant ) {
            if ( $descendant instanceof DOMElement ) {
                $this->materializeFallbackSourceTagMarker($descendant);
            }
        }
        return $clone;
    }

    private function materializeFallbackSourceTagMarker(DOMElement $element): void
    {
        $marker = $this->fallbackSourceTagMarker(strtolower($element->tagName));
        if ( '' !== $marker ) {
            $element->setAttribute('class', $this->mergeClassNames($this->attr($element, 'class'), $marker));
        }
    }

    protected function fallbackSourceTagMarker(string $tagName): string
    {
        $markers = isset($this->sourceTagMarkers) && is_array($this->sourceTagMarkers)
            ? $this->sourceTagMarkers
            : array();
        return $markers[$tagName] ?? '';
    }

    private function safeFallbackHtmlString(string $html): string
    {
        return SourceDom::safeFallbackHtmlString($html);
    }

    private function isFallbackUrlAttribute(string $attribute): bool
    {
        return SourceDom::isFallbackUrlAttribute($attribute);
    }

    private function safeFallbackSrcset(string $srcset): string
    {
        return SourceDom::safeFallbackSrcset($srcset);
    }

    private function safeFallbackUrl(string $url, string $attribute): bool
    {
        return SourceDom::safeFallbackUrl($url, $attribute);
    }

    private function boundedFallbackHtml(string $html): array
    {
        return SourceDom::boundedFallbackHtml($html);
    }

    private function boundedFallbackText(string $text): array
    {
        return SourceDom::boundedFallbackText($text);
    }

    private function directElementChildCount(DOMElement $element): int
    {
        return SourceDom::directElementChildCount($element);
    }

    private function mergeClassNames(string ...$classNames): string
    {
        return SourceDom::mergeClassNames(...$classNames);
    }

    private function htmlAttributeString(array $attrs): string
    {
        return SourceDom::htmlAttributeString($attrs);
    }

    private function hasAncestorTag(DOMElement $element, array $tagNames): bool
    {
        return SourceDom::hasAncestorTag($element, $tagNames);
    }

    private function hasSourceNavigationSignal(DOMElement $element): bool
    {
        return SourceDom::hasSourceNavigationSignal($element);
    }

    private function safeNavigationUrl(string $url): string
    {
        return SourceDom::safeNavigationUrl($url);
    }

    private function runtimeIslandSelector(DOMElement $element): string
    {
        return SourceDom::runtimeIslandSelector($element);
    }

    private function eventMetadata(DOMElement $element): array
    {
        return SourceDom::eventMetadata($element);
    }

    private function isSafeSvgContent(string $content): bool
    {
        return SourceDom::isSafeSvgContent($content);
    }

    private function svgHasDrawableContent(DOMElement $element): bool
    {
        return SourceDom::svgHasDrawableContent($element);
    }

    private function isExternalSpriteUse(DOMElement $element): bool
    {
        return SourceDom::isExternalSpriteUse($element);
    }

    /**
     * Sanitize inline SVG markup for safe inline preservation.
     *
     * Strips only the genuinely-unsafe parts of an inline SVG — `<script>` /
     * `<style>` elements, `<foreignObject>` (which can embed arbitrary HTML and
     * scripts), event-handler attributes, and `javascript:` URLs — while keeping
     * the SVG shape and structure markup (`svg`/`path`/`circle`/`rect`/`g`/
     * `text`/`polygon`/...) intact so the artwork still renders. This preserves
     * the graphic rather than dropping the whole SVG when a single unsafe
     * attribute or element is present.
     */
    private function sanitizeInlineSvgMarkup(DOMElement $element): string
    {
        // safeFallbackHtml() already removes <script>/<style> elements, on*
        // handlers, javascript: in href/src/xlink:href, and srcdoc.
        $html = $this->safeFallbackHtml($element);

        // foreignObject can carry arbitrary embedded HTML (iframes, objects,
        // embeds) that shape markup never needs; drop it entirely (DOMDocument
        // lowercases the tag name when serializing parsed HTML).
        $html = preg_replace('@<foreignobject\b[^>]*>.*?</foreignobject>@si', '', $html) ?? $html;
        $html = preg_replace('@<foreignobject\b[^>]*/?>@si', '', $html) ?? $html;
        $html = preg_replace('@<link\b[^>]*\/?>@si', '', $html) ?? $html;

        // Neutralize any residual javascript: carried in remaining attributes
        // (e.g. a style attribute), dropping the whole attribute so the shape
        // it belongs to survives.
        $html = preg_replace('/\s+[a-zA-Z_:][\w:.-]*\s*=\s*("[^"]*javascript:[^"]*"|\'[^\']*javascript:[^\']*\'|[^\s>]*javascript:[^\s>]*)/i', '', $html) ?? $html;

        return trim($html);
    }

    private function dedupeArrayRows(array $rows): array
    {
        return SourceDom::dedupeArrayRows($rows);
    }

    private function elementContains(DOMElement $container, DOMElement $element): bool
    {
        return SourceDom::elementContains($container, $element);
    }
}
