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

    /**
     * The phrasing label of a disclosure control, without its decorative icon.
     *
     * The target block (core/accordion-heading, core/details) stores the label
     * as RichText and draws its own toggle icon, so the source icon — an svg,
     * an aria-hidden subtree, or a text-less flow wrapper such as
     * `div > div > div + div` — is removed from a clone of the DOM before
     * serializing. Removing whole nodes keeps the label balanced whatever the
     * icon's nesting.
     */
    private function disclosureLabelHtml(DOMElement $element, callable $innerHtml): string
    {
        $label = $element->cloneNode(true);
        if ( ! $label instanceof DOMElement ) {
            return '';
        }

        $decorative = array();
        foreach ( $label->getElementsByTagName('*') as $descendant ) {
            if ( $descendant instanceof DOMElement && $this->isDecorativeDisclosureIcon($descendant) ) {
                $decorative[] = $descendant;
            }
        }
        foreach ( $decorative as $node ) {
            $node->parentNode?->removeChild($node);
        }

        return trim($innerHtml($label));
    }

    private function isDecorativeDisclosureIcon(DOMElement $element): bool
    {
        if ( 'svg' === strtolower($element->tagName) || 'true' === strtolower($this->trimmedAttribute($element, 'aria-hidden')) ) {
            return true;
        }

        return 1 === preg_match('/^(?:div|figure|p|section)$/', strtolower($element->tagName))
            && '' === trim($element->textContent)
            && 0 === $element->getElementsByTagName('img')->length;
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

    private function containsNode(DOMElement $ancestor, DOMElement $node): bool
    {
        for ( $current = $node; $current instanceof DOMElement; $current = $current->parentNode ) {
            if ( $current->isSameNode($ancestor) ) {
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
