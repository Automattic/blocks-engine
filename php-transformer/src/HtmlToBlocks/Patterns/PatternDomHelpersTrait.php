<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Patterns;

use DOMElement;

trait PatternDomHelpersTrait
{
    private function attr(DOMElement $element, string $name): string
    {
        return $element->hasAttribute($name) ? $element->getAttribute($name) : '';
    }

    private function hasClass(DOMElement $element, string $className): bool
    {
        return in_array($className, preg_split('/\s+/', trim($this->attr($element, 'class'))) ?: array(), true);
    }

    private function firstChildElement(DOMElement $element, string $tagName): ?DOMElement
    {
        foreach ( $element->childNodes as $child ) {
            if ( $child instanceof DOMElement && strtolower($child->tagName) === $tagName ) {
                return $child;
            }
        }

        return null;
    }

    private function hasDirectChildElement(DOMElement $element, string $tagName): bool
    {
        foreach ( $element->childNodes as $child ) {
            if ( $child instanceof DOMElement && $tagName === strtolower($child->tagName) ) {
                return true;
            }
        }

        return false;
    }

    private function trimmedAttribute(DOMElement $element, string $name): string
    {
        return $element->hasAttribute($name) ? trim($element->getAttribute($name)) : '';
    }

    private function disclosureLabelHtml(DOMElement $element, callable $innerHtml): string
    {
        $html = $innerHtml($element);
        $html = preg_replace('/<svg\b[^>]*>.*?<\/svg>/is', '', $html) ?? $html;
        $html = preg_replace('/<([a-z][a-z0-9]*)\b[^>]*\baria-hidden\s*=\s*(["\'])?true\2[^>]*>.*?<\/\1>/is', '', $html) ?? $html;

        return trim($html);
    }

    private function hasRuntimeHeavyDescendant(DOMElement $element): bool
    {
        foreach ( $element->getElementsByTagName('*') as $candidate ) {
            if ( $candidate instanceof DOMElement && in_array(strtolower($candidate->tagName), array( 'script', 'canvas', 'template', 'iframe', 'form' ), true) ) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<int, DOMElement>
     */
    private function directChildElements(DOMElement $element): array
    {
        $children = array();
        foreach ( $element->childNodes as $child ) {
            if ( XML_TEXT_NODE === $child->nodeType && '' !== trim($child->textContent ?? '') ) {
                return array();
            }

            if ( $child instanceof DOMElement ) {
                $children[] = $child;
            }
        }

        return $children;
    }
}
