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
        if ( 'nav' !== strtolower($element->tagName) && ! $this->hasNavigationSignal($element) && ! $this->hasDirectListNavigationSignal($element) ) {
            return null;
        }

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
        $isListRoot = in_array(strtolower($element->tagName), array( 'ul', 'ol' ), true);
        foreach ( $element->childNodes as $child ) {
            if ( XML_TEXT_NODE === $child->nodeType && '' === trim($child->textContent ?? '') ) {
                continue;
            }

            if ( $isListRoot && $child instanceof DOMElement && 'li' === strtolower($child->tagName) ) {
                $anchor = $this->singleNavigationAnchor($child);
                if ( ! $anchor instanceof DOMElement || '' === trim($anchor->textContent ?? '') ) {
                    return array();
                }

                $anchors[] = $anchor;
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

                    $anchor = $this->singleNavigationAnchor($item);
                    if ( ! $anchor instanceof DOMElement || '' === trim($anchor->textContent ?? '') ) {
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

    private function singleNavigationAnchor(DOMElement $element): ?DOMElement
    {
        $anchors = array();
        $this->collectAnchors($element, $anchors);

        return 1 === count($anchors) ? $anchors[0] : null;
    }

    /**
     * @param array<int, DOMElement> $anchors
     */
    private function collectAnchors(DOMElement $element, array &$anchors): void
    {
        foreach ( $element->childNodes as $child ) {
            if ( ! $child instanceof DOMElement ) {
                continue;
            }

            if ( 'a' === strtolower($child->tagName) ) {
                $anchors[] = $child;
                continue;
            }

            if ( in_array(strtolower($child->tagName), array( 'span', 'div', 'p' ), true) ) {
                $this->collectAnchors($child, $anchors);
            }
        }
    }

    private function hasNavigationSignal(DOMElement $element): bool
    {
        if ( 'navigation' === strtolower($element->hasAttribute('role') ? $element->getAttribute('role') : '') ) {
            return true;
        }

        foreach ( array( 'class', 'id' ) as $attribute ) {
            $value = $element->hasAttribute($attribute) ? $element->getAttribute($attribute) : '';
            foreach ( preg_split('/[^a-z0-9]+/', strtolower($value)) ?: array() as $token ) {
                if ( in_array($token, array( 'nav', 'navbar', 'navigation', 'menu', 'links' ), true) ) {
                    return true;
                }
            }
        }

        return false;
    }

    private function hasDirectListNavigationSignal(DOMElement $element): bool
    {
        foreach ( $element->childNodes as $child ) {
            if ( $child instanceof DOMElement && in_array(strtolower($child->tagName), array( 'ul', 'ol' ), true) && $this->hasNavigationSignal($child) ) {
                return true;
            }
        }

        return false;
    }
}
