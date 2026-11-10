<?php

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Support;

use DOMElement;

/**
 * Fallback HTML and label helpers that depend on the using class.
 *
 * Safe fallback HTML honors the overridable source-tag marker, and label
 * normalization uses the class's Runtime. Stateless DOM helpers are called
 * on {@see SourceDom} directly.
 */
trait DomHelpersTrait
{
    private function normalizedNavigationLabel(string $label): string
    {
        return trim(preg_replace('/\s+/', ' ', html_entity_decode($this->runtime->stripAllTags($label), ENT_QUOTES | ENT_HTML5, 'UTF-8')) ?? $label);
    }

    private function safeFallbackHtml(DOMElement $element): string
    {
        $fallback = $this->sourceTagProjectedClone($element);
        if ( $fallback instanceof DOMElement ) {
            return SourceDom::safeFallbackHtmlString(trim($fallback->ownerDocument->saveHTML($fallback) ?: ''));
        }

        return SourceDom::safeFallbackHtmlString(SourceDom::outerHtml($element));
    }

    private function sourceTagProjectedClone(DOMElement $element): ?DOMElement
    {
        $clone = $element->cloneNode(true);
        if ( ! $clone instanceof DOMElement ) {
            return null;
        }
        SourceDom::materializeMissingImageSources($clone);
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
            $element->setAttribute('class', SourceDom::mergeClassNames(SourceDom::attr($element, 'class'), $marker));
        }
    }

    protected function fallbackSourceTagMarker(string $tagName): string
    {
        return '';
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
        // Work on a clone so admission and sanitization share the namespace
        // policy without changing source DOM evidence. Collect first: removing
        // a declaration must not affect resolution of descendant attributes.
        $element = $element->cloneNode(true);
        $removals = array();
        foreach ( array_merge(array($element), iterator_to_array($element->getElementsByTagName('*'), false)) as $node ) {
            foreach ( SourceDom::htmlAttributes($node) as $name => $value ) {
                if ( SvgMaterializer::isForeignSvgAttribute($node, $name, $value) ) {
                    $removals[] = array($node, $name);
                }
            }
        }
        foreach ( $removals as [$node, $name] ) {
            $node->removeAttribute($name);
        }

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

}
