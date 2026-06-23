<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Patterns;

use DOMElement;

final class NavigationPattern
{
    /**
     * @param callable(DOMElement): array<string, mixed> $presentationAttributes
     * @param callable(DOMElement): string $innerHtml
     * @param callable(string, array<string, mixed>, array<int, array<string, mixed>>, DOMElement|null): array<string, mixed> $createBlock
     * @return array<string, mixed>|null
     */
    public function match(DOMElement $element, callable $presentationAttributes, callable $innerHtml, callable $createBlock): ?array
    {
        $links = array();
        foreach ( $this->directNavigationAnchors($element) as $anchor ) {
            $links[] = $createBlock('core/navigation-link', array_filter(array(
                'label' => $innerHtml($anchor),
                'url'   => $this->safeNavigationUrl($anchor->hasAttribute('href') ? $anchor->getAttribute('href') : ''),
                'kind'  => 'custom',
            ), static fn ($value): bool => '' !== $value), array(), $anchor);
        }

        if ( array() === $links ) {
            return null;
        }

        return $createBlock('core/navigation', $presentationAttributes($element), $links, $element);
    }

    private function safeNavigationUrl(string $url): string
    {
        $url = trim($url);
        if ( '' === $url || preg_match('/[\x00-\x1f\x7f]|javascript\s*:/i', $url) ) {
            return '';
        }

        return $url;
    }

    /**
     * @return array<int, DOMElement>
     */
    private function directNavigationAnchors(DOMElement $element): array
    {
        $anchors = array();
        foreach ( $element->childNodes as $child ) {
            if ( XML_TEXT_NODE === $child->nodeType && '' === trim($child->textContent ?? '') ) {
                continue;
            }

            if ( $child instanceof DOMElement && 'a' === strtolower($child->tagName) && '' !== trim($child->textContent ?? '') ) {
                $anchors[] = $child;
                continue;
            }

            if ( $child instanceof DOMElement && in_array(strtolower($child->tagName), array( 'ul', 'ol' ), true) ) {
                foreach ( $child->childNodes as $item ) {
                    if ( XML_TEXT_NODE === $item->nodeType && '' === trim($item->textContent ?? '') ) {
                        continue;
                    }

                    if ( ! $item instanceof DOMElement || 'li' !== strtolower($item->tagName) ) {
                        return array();
                    }

                    $anchor = $this->firstChildElement($item, 'a');
                    if ( ! $anchor instanceof DOMElement || '' === trim($anchor->textContent ?? '') || 1 !== $this->childElementCount($item) ) {
                        return array();
                    }

                    $anchors[] = $anchor;
                }
                continue;
            }

            return array();
        }

        return $anchors;
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

    private function childElementCount(DOMElement $element): int
    {
        $count = 0;
        foreach ( $element->childNodes as $child ) {
            if ( $child instanceof DOMElement ) {
                ++$count;
            }
        }

        return $count;
    }
}
