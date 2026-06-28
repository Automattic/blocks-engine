<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Patterns;

use DOMElement;

final class CodeWindowPattern
{
    /**
     * @param callable(DOMElement): array<string, mixed> $presentationAttributes
     * @param callable(DOMElement): string $innerHtml
     * @param callable(DOMElement, DOMElement): array<string, mixed> $codePresentationAttributes
     * @param callable(DOMElement): string $codeContent
     * @param callable(string, array<string, mixed>, array<int, array<string, mixed>>, DOMElement|null): array<string, mixed> $createBlock
     * @return array<string, mixed>|null
     */
    public function match(DOMElement $element, callable $presentationAttributes, callable $innerHtml, callable $codePresentationAttributes, callable $codeContent, callable $createBlock): ?array
    {
        $pre = $this->firstChildElement($element, 'pre');
        if ( ! $pre instanceof DOMElement ) {
            return null;
        }

        $code = $this->firstChildElement($pre, 'code');
        if ( ! $code instanceof DOMElement ) {
            return null;
        }

        $label = $this->codeWindowLabel($element, $pre, $innerHtml);
        if ( '' === $label && ! $this->hasClass($element, 'code-window') && ! $this->hasClass($element, 'code-frame') ) {
            return null;
        }

        $children = array();
        if ( '' !== $label ) {
            $children[] = $createBlock('core/paragraph', array( 'content' => $label ));
        }
        $children[] = $createBlock('core/code', array_merge($codePresentationAttributes($pre, $code), array( 'content' => $codeContent($code) )), array(), $pre);

        return $createBlock('core/group', $presentationAttributes($element), $children, $element);
    }

    /**
     * @param callable(DOMElement): string $innerHtml
     */
    private function codeWindowLabel(DOMElement $element, DOMElement $pre, callable $innerHtml): string
    {
        foreach ( array( 'data-label', 'data-title', 'data-filename', 'aria-label' ) as $attribute ) {
            $value = trim($this->attr($element, $attribute));
            if ( '' !== $value ) {
                return htmlspecialchars($value, ENT_NOQUOTES | ENT_SUBSTITUTE, 'UTF-8');
            }
        }

        foreach ( $element->childNodes as $child ) {
            if ( ! $child instanceof DOMElement || $child->isSameNode($pre) ) {
                continue;
            }

            $tagName = strtolower($child->tagName);
            if ( 'figcaption' === $tagName || 'header' === $tagName || $this->hasClass($child, 'code-label') || $this->hasClass($child, 'filename') || $this->hasClass($child, 'window-title') ) {
                return $innerHtml($child);
            }
        }

        return '';
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

    private function attr(DOMElement $element, string $name): string
    {
        return $element->hasAttribute($name) ? $element->getAttribute($name) : '';
    }

    private function hasClass(DOMElement $element, string $className): bool
    {
        return in_array($className, preg_split('/\s+/', trim($this->attr($element, 'class'))) ?: array(), true);
    }
}
