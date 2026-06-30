<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Patterns;

use DOMElement;

use const XML_TEXT_NODE;

final class QuotePattern
{
    /**
     * Inline blockquote entry point (convertElement blockquote-tag branch).
     *
     * @param array<int, array<string, mixed>> $fallbacks
     * @param callable(DOMElement): string $citationFromElement
     * @param callable(DOMElement, array<int, string>): string $innerHtmlWithoutTags
     * @param callable(string): string $stripAllTags
     * @param callable(DOMElement): array<string, mixed> $presentationAttributes
     * @param callable(DOMElement, array<int, array<string, mixed>>&, array<int, string>): array<int, array<string, mixed>> $convertChildrenWithoutTags
     * @param callable(string): bool $isInlineContentElement
     * @param callable(string, array<string, mixed>, array<int, array<string, mixed>>, DOMElement|null): array<string, mixed> $createBlock
     * @return array<string, mixed>|null
     */
    public function matchBlockquote(
        DOMElement $element,
        array &$fallbacks,
        callable $citationFromElement,
        callable $innerHtmlWithoutTags,
        callable $stripAllTags,
        callable $presentationAttributes,
        callable $convertChildrenWithoutTags,
        callable $isInlineContentElement,
        callable $createBlock
    ): ?array {
        $citation = $citationFromElement($element);
        $value = $innerHtmlWithoutTags($element, array( 'cite', 'footer' ));
        if ( '' === trim($stripAllTags($value)) ) {
            return null;
        }

        if ( $this->hasClass($element, 'wp-block-pullquote') ) {
            return $createBlock('core/pullquote', array_filter(array_merge($presentationAttributes($element), array(
                'value'    => $value,
                'citation' => $citation,
            )), static fn ($value): bool => '' !== $value), array(), $element);
        }

        $innerBlocks = $this->phrasingQuoteChildren($element, $value, $isInlineContentElement, $createBlock);
        if ( array() === $innerBlocks ) {
            $innerBlocks = $convertChildrenWithoutTags($element, $fallbacks, array( 'cite', 'footer' ));
        }
        if ( array() === $innerBlocks ) {
            $innerBlocks[] = $createBlock('core/paragraph', array( 'content' => $value ));
        }

        return $createBlock('core/quote', array_filter(array_merge($presentationAttributes($element), array( 'citation' => $citation )), static fn ($value): bool => '' !== $value), $innerBlocks, $element);
    }

    /**
     * figure>blockquote entry point (convertElement figure-tag branch).
     *
     * @param array<int, array<string, mixed>> $fallbacks
     * @param callable(DOMElement): string $citationFromElement
     * @param callable(DOMElement): string $innerHtml
     * @param callable(DOMElement, array<int, string>): string $innerHtmlWithoutTags
     * @param callable(string): string $stripAllTags
     * @param callable(DOMElement): array<string, mixed> $presentationAttributes
     * @param callable(DOMElement, array<int, array<string, mixed>>&, array<int, string>): array<int, array<string, mixed>> $convertChildrenWithoutTags
     * @param callable(string, array<string, mixed>, array<int, array<string, mixed>>, DOMElement|null): array<string, mixed> $createBlock
     * @return array<string, mixed>|null
     */
    public function matchFigureBlockquote(
        DOMElement $figure,
        DOMElement $blockquote,
        array &$fallbacks,
        callable $citationFromElement,
        callable $innerHtml,
        callable $innerHtmlWithoutTags,
        callable $stripAllTags,
        callable $presentationAttributes,
        callable $convertChildrenWithoutTags,
        callable $createBlock
    ): ?array {
        $citation = $citationFromElement($blockquote);
        $caption = $this->firstChildElement($figure, 'figcaption');
        if ( '' === $citation && $caption instanceof DOMElement ) {
            $citation = $innerHtml($caption);
        }

        $value = $innerHtmlWithoutTags($blockquote, array( 'cite', 'footer' ));
        if ( '' === trim($stripAllTags($value)) ) {
            return null;
        }

        $attrs = array_filter(array_merge($presentationAttributes($figure), array( 'citation' => $citation )), static fn ($value): bool => is_array($value) ? array() !== $value : '' !== $value);

        if ( $this->hasClass($figure, 'wp-block-pullquote') || $this->hasClass($blockquote, 'wp-block-pullquote') ) {
            return $createBlock('core/pullquote', array_merge($attrs, array( 'value' => $value )), array(), $figure);
        }

        $innerBlocks = $convertChildrenWithoutTags($blockquote, $fallbacks, array( 'cite', 'footer' ));
        if ( array() === $innerBlocks ) {
            $innerBlocks[] = $createBlock('core/paragraph', array( 'content' => $value ));
        }

        return $createBlock('core/quote', $attrs, $innerBlocks, $figure);
    }

    /**
     * @param callable(string): bool $isInlineContentElement
     * @param callable(string, array<string, mixed>, array<int, array<string, mixed>>, DOMElement|null): array<string, mixed> $createBlock
     * @return array<int, array<string, mixed>>
     */
    private function phrasingQuoteChildren(DOMElement $element, string $value, callable $isInlineContentElement, callable $createBlock): array
    {
        foreach ( $element->childNodes as $child ) {
            if ( XML_TEXT_NODE === $child->nodeType ) {
                continue;
            }
            if ( ! $child instanceof DOMElement ) {
                continue;
            }

            $tagName = strtolower($child->tagName);
            if ( in_array($tagName, array( 'cite', 'footer' ), true) ) {
                continue;
            }
            if ( 'br' === $tagName || $isInlineContentElement($tagName) ) {
                continue;
            }

            return array();
        }

        return array( $createBlock('core/paragraph', array( 'content' => $value )) );
    }

    private function hasClass(DOMElement $element, string $className): bool
    {
        $class = $element->hasAttribute('class') ? $element->getAttribute('class') : '';
        return in_array($className, preg_split('/\s+/', trim($class)) ?: array(), true);
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
}
