<?php

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Support;

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
        $html = '';
        foreach ( $element->childNodes as $child ) {
            $html .= $element->ownerDocument->saveHTML($child);
        }

        return trim($html);
    }

    private function innerHtmlPreservingWhitespace(DOMElement $element): string
    {
        $html = '';
        foreach ( $element->childNodes as $child ) {
            $html .= $element->ownerDocument->saveHTML($child);
        }

        return $html;
    }

    private function outerHtml(DOMElement $element): string
    {
        return trim($element->ownerDocument->saveHTML($element) ?: '');
    }

    private function attr(DOMElement $element, string $name): string
    {
        return $element->hasAttribute($name) ? $element->getAttribute($name) : '';
    }

    private function safeAnchor(string $id): string
    {
        $id = trim($id);
        if ( '' === $id || ! preg_match('/^[A-Za-z][A-Za-z0-9_-]*$/', $id) ) {
            return '';
        }

        return $id;
    }

    private function hasClass(DOMElement $element, string $className): bool
    {
        return in_array($className, preg_split('/\s+/', trim($this->attr($element, 'class'))) ?: array(), true);
    }

    private function elementSelector(DOMElement $element): string
    {
        $parts = array();
        $current = $element;
        while ( $current instanceof DOMElement && 'body' !== strtolower($current->tagName) ) {
            $tagName = strtolower($current->tagName);
            $index = 1;
            for ( $sibling = $current->previousSibling; $sibling instanceof DOMNode; $sibling = $sibling->previousSibling ) {
                if ( $sibling instanceof DOMElement && strtolower($sibling->tagName) === $tagName ) {
                    ++$index;
                }
            }
            array_unshift($parts, $tagName . ':nth-of-type(' . $index . ')');
            $current = $current->parentNode instanceof DOMElement ? $current->parentNode : null;
        }

        return implode(' > ', $parts);
    }

    /**
     * @return array<string, string>
     */
    private function htmlAttributes(DOMElement $element): array
    {
        $attributes = array();
        foreach ( $element->attributes ?? array() as $attribute ) {
            $attributes[$attribute->nodeName] = $attribute->nodeValue ?? '';
        }

        ksort($attributes);
        return $attributes;
    }

    /**
     * @return array<int, string>
     */
    private function ancestorTags(DOMElement $element): array
    {
        $tags = array();
        for ( $parent = $element->parentNode; $parent instanceof DOMElement && 'body' !== strtolower($parent->tagName); $parent = $parent->parentNode ) {
            $tags[] = strtolower($parent->tagName);
        }

        return $tags;
    }

    /**
     * @return array<int, string>
     */
    private function classNames(DOMElement $element): array
    {
        return array_values(array_filter(preg_split('/\s+/', trim($this->attr($element, 'class'))) ?: array()));
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

    private function closestTagName(DOMElement $element): ?string
    {
        return $element->parentNode instanceof DOMElement ? strtolower($element->parentNode->tagName) : null;
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

    private function onlyChildElement(DOMElement $element, string $tagName): ?DOMElement
    {
        $match = null;
        foreach ( $element->childNodes as $child ) {
            if ( XML_TEXT_NODE === $child->nodeType && '' === trim($child->textContent ?? '') ) {
                continue;
            }

            if ( ! $child instanceof DOMElement || strtolower($child->tagName) !== $tagName || null !== $match ) {
                return null;
            }

            $match = $child;
        }

        return $match;
    }

    /**
     * @param array<int, string> $excludedTags
     */
    private function innerHtmlWithoutTags(DOMElement $element, array $excludedTags): string
    {
        $html = '';
        foreach ( $element->childNodes as $child ) {
            if ( $child instanceof DOMElement && in_array(strtolower($child->tagName), $excludedTags, true) ) {
                continue;
            }
            $html .= $element->ownerDocument->saveHTML($child);
        }
        return trim($html);
    }

    private function safeFallbackHtml(DOMElement $element): string
    {
        $html = preg_replace('@<(script|style)[^>]*?>.*?</\\1>@si', '', $this->outerHtml($element)) ?? '';
        $html = preg_replace('/\s+on[a-z]+\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)/i', '', $html) ?? '';
        $html = preg_replace('/\s+(?:href|src|xlink:href)\s*=\s*("\s*javascript:[^"]*"|\'\s*javascript:[^\']*\'|javascript:[^\s>]+)/i', '', $html) ?? '';
        $html = preg_replace('/\s+srcdoc\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)/i', '', $html) ?? '';

        return trim($html);
    }

    /**
     * @return array{html: string, bytes: int, truncated: bool}
     */
    private function boundedFallbackHtml(string $html): array
    {
        $bytes = strlen($html);
        if ( $bytes > 2000 ) {
            return array(
                'html'      => substr($html, 0, 2000) . '...',
                'bytes'     => $bytes,
                'truncated' => true,
            );
        }

        return array(
            'html'      => $html,
            'bytes'     => $bytes,
            'truncated' => false,
        );
    }

    /**
     * @return array{text: string, bytes: int, truncated: bool}
     */
    private function boundedFallbackText(string $text): array
    {
        $bytes = strlen($text);
        if ( $bytes > 2000 ) {
            return array(
                'text'      => substr($text, 0, 2000) . '...',
                'bytes'     => $bytes,
                'truncated' => true,
            );
        }

        return array(
            'text'      => $text,
            'bytes'     => $bytes,
            'truncated' => false,
        );
    }

    private function directElementChildCount(DOMElement $element): int
    {
        $count = 0;
        foreach ( $element->childNodes as $child ) {
            if ( $child instanceof DOMElement ) {
                ++$count;
            }
        }

        return $count;
    }

    private function mergeClassNames(string ...$classNames): string
    {
        $classes = array();
        foreach ( $classNames as $className ) {
            foreach ( preg_split('/\s+/', trim($className)) ?: array() as $class ) {
                if ( '' !== $class && ! in_array($class, $classes, true) ) {
                    $classes[] = $class;
                }
            }
        }

        return implode(' ', $classes);
    }

    private function htmlAttributeString(array $attrs): string
    {
        $html = '';
        foreach ( $attrs as $name => $value ) {
            $html .= ' ' . $name . '="' . htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '"';
        }
        return $html;
    }

    /**
     * @param array<int, string> $tagNames
     */
    private function hasAncestorTag(DOMElement $element, array $tagNames): bool
    {
        for ( $node = $element->parentNode; $node instanceof DOMElement && 'body' !== strtolower($node->tagName); $node = $node->parentNode ) {
            if ( in_array(strtolower($node->tagName), $tagNames, true) ) {
                return true;
            }
        }

        return false;
    }

    private function hasSourceNavigationSignal(DOMElement $element): bool
    {
        if ( 'navigation' === strtolower($this->attr($element, 'role')) ) {
            return true;
        }

        foreach ( array( 'class', 'id' ) as $attribute ) {
            foreach ( preg_split('/[^a-z0-9]+/', strtolower($this->attr($element, $attribute))) ?: array() as $token ) {
                if ( in_array($token, array( 'nav', 'navbar', 'navigation', 'menu', 'links' ), true) ) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Sanitize a navigation URL, dropping control characters and javascript: schemes.
     */
    private function safeNavigationUrl(string $url): string
    {
        $url = trim($url);
        if ( '' === $url || preg_match('/[\x00-\x1f\x7f]|javascript\s*:/i', $url) ) {
            return '';
        }

        return $url;
    }

    private function runtimeIslandSelector(DOMElement $element): string
    {
        $id = trim($this->attr($element, 'id'));
        if ( '' !== $id ) {
            return '#' . $id;
        }

        foreach ( preg_split('/\s+/', trim($this->attr($element, 'class'))) ?: array() as $class ) {
            if ( '' !== $class ) {
                return '.' . $class;
            }
        }

        return $this->elementSelector($element);
    }

    /**
     * @return array<int, array<string, string>>
     */
    private function eventMetadata(DOMElement $element): array
    {
        $events = array();
        foreach ( $this->htmlAttributes($element) as $name => $value ) {
            if ( preg_match('/^on([a-z]+)$/i', $name, $matches) ) {
                $events[] = array(
                    'type'      => strtolower($matches[1]),
                    'attribute' => strtolower($name),
                );
            }
            if ( preg_match('/^data-(?:action|on|event)$/i', $name) && '' !== trim($value) ) {
                $events[] = array(
                    'type'      => 'declared',
                    'attribute' => $name,
                );
            }
        }

        return $events;
    }

    private function isSafeSvgContent(string $content): bool
    {
        return '' !== trim($content) && preg_match('/<svg(?:\s|>)/i', $content) && ! preg_match('/<\s*script\b|\son[a-z]+\s*=|javascript\s*:/i', $content);
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return array<int, array<string, mixed>>
     */
    private function dedupeArrayRows(array $rows): array
    {
        $seen = array();
        $deduped = array();
        foreach ( $rows as $row ) {
            $key = json_encode($row, JSON_UNESCAPED_SLASHES);
            if ( ! is_string($key) || isset($seen[$key]) ) {
                continue;
            }
            $seen[$key] = true;
            $deduped[] = $row;
        }

        return $deduped;
    }
}
