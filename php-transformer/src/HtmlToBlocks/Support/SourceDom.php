<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Support;

use Automattic\BlocksEngine\PhpTransformer\AssetAnalysis\SrcsetParser;
use Automattic\BlocksEngine\PhpTransformer\Support\DeterministicRowDeduplicator;
use DOMDocument;
use DOMElement;
use DOMNode;

/**
 * The engine's shared DOM reading vocabulary.
 *
 * Reading an attribute, counting element children, serializing inner HTML,
 * building a selector for an element — the small, exact operations that every
 * part of the transformer performs constantly.
 *
 * These are pure functions of their arguments. They are static deliberately:
 * a pure function needs no instance, so a caller needs no constructor argument
 * and no injected closure to reach one. That is the whole point. This
 * vocabulary previously lived only in {@see DomHelpersTrait}, which meant a
 * class that was not already the transformer could not reach it, and the
 * package accumulated three separate workarounds — forwarding closures through
 * context objects, copy-pasted private duplicates, and independent
 * reimplementations that were free to disagree.
 *
 * Operations needing transform state (source-tag markers, fallback
 * sanitization, the WordPress runtime) are not part of this vocabulary and
 * remain on {@see DomHelpersTrait}.
 */
final class SourceDom
{
    public static function innerHtml(DOMElement $element): string
    {
        $element = self::canonicalizedSerializationClone($element);
        $html = '';
        foreach ( $element->childNodes as $child ) {
            $html .= $element->ownerDocument->saveHTML($child);
        }

        return trim($html);
    }

    public static function innerHtmlPreservingWhitespace(DOMElement $element): string
    {
        $element = self::canonicalizedSerializationClone($element);
        $html = '';
        foreach ( $element->childNodes as $child ) {
            $html .= $element->ownerDocument->saveHTML($child);
        }

        return $html;
    }

    public static function outerHtml(DOMElement $element): string
    {
        $element = self::canonicalizedSerializationClone($element);
        return trim($element->ownerDocument->saveHTML($element) ?: '');
    }

    public static function canonicalizedSerializationClone(DOMElement $element): DOMElement
    {
        $clone = $element->cloneNode(true);
        if ( ! $clone instanceof DOMElement ) {
            throw new \LogicException('Cloning a DOMElement must produce a DOMElement.');
        }
        self::canonicalizeLinkUrls($clone);
        return $clone;
    }

    public static function canonicalizeLinkUrls(DOMElement $element): void
    {
        $anchors = 'a' === strtolower($element->tagName) ? array( $element ) : array();
        foreach ( $element->getElementsByTagName('a') as $anchor ) {
            if ( $anchor instanceof DOMElement ) {
                $anchors[] = $anchor;
            }
        }

        foreach ( $anchors as $anchor ) {
            if ( ! $anchor->hasAttribute('href') ) {
                continue;
            }

            $href = LinkUrlSanitizer::sanitize($anchor->getAttribute('href'));
            if ( '' === $href ) {
                $anchor->removeAttribute('href');
                continue;
            }
            if ( $href !== $anchor->getAttribute('href') ) {
                $anchor->setAttribute('href', $href);
            }
        }
    }

    public static function attr(DOMElement $element, string $name): string
    {
        return $element->hasAttribute($name) ? $element->getAttribute($name) : '';
    }

    public static function safeAnchor(string $id): string
    {
        $id = trim($id);
        if ( '' === $id || ! preg_match('/^[A-Za-z][A-Za-z0-9_-]*$/', $id) ) {
            return '';
        }

        return $id;
    }

    public static function hasClass(DOMElement $element, string $className): bool
    {
        return in_array($className, preg_split('/\s+/', trim(self::attr($element, 'class'))) ?: array(), true);
    }

    public static function elementSelector(DOMElement $element): string
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
    public static function htmlAttributes(DOMElement $element): array
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
    public static function ancestorTags(DOMElement $element): array
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
    public static function classNames(DOMElement $element): array
    {
        return array_values(array_filter(preg_split('/\s+/', trim(self::attr($element, 'class'))) ?: array()));
    }

    public static function childElementCount(DOMElement $element): int
    {
        $count = 0;
        foreach ( $element->childNodes as $child ) {
            if ( $child instanceof DOMElement ) {
                ++$count;
            }
        }

        return $count;
    }

    public static function closestTagName(DOMElement $element): ?string
    {
        return $element->parentNode instanceof DOMElement ? strtolower($element->parentNode->tagName) : null;
    }

    public static function firstChildElement(DOMElement $element, string $tagName): ?DOMElement
    {
        foreach ( $element->childNodes as $child ) {
            if ( $child instanceof DOMElement && strtolower($child->tagName) === $tagName ) {
                return $child;
            }
        }
        return null;
    }

    public static function onlyChildElement(DOMElement $element, string $tagName): ?DOMElement
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
    public static function innerHtmlWithoutTags(DOMElement $element, array $excludedTags): string
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

    public static function safeFallbackHtmlString(string $html): string
    {
        $html = preg_replace('@<(script|style)[^>]*?>.*?</\\1>@si', '', $html) ?? '';
        $html = preg_replace('@<link\b[^>]*\/?>@si', '', $html) ?? '';
        $html = preg_replace('/\s+on[a-z]+\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)/i', '', $html) ?? '';
        $html = preg_replace_callback(
            '/\s+([a-zA-Z_:][\w:.-]*)\s*=\s*("([^"]*)"|\'([^\']*)\'|([^\s>]+))/i',
            function (array $matches): string {
                $attribute = strtolower($matches[1]);
                $value = $matches[3] ?? $matches[4] ?? $matches[5] ?? '';
                if ( 'srcset' === $attribute ) {
                    $srcset = self::safeFallbackSrcset($value);
                    return '' === $srcset
                        ? ''
                        : ' ' . $matches[1] . '="' . htmlspecialchars($srcset, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '"';
                }
                return self::isFallbackUrlAttribute($attribute) && ! self::safeFallbackUrl($value, $attribute)
                    ? ''
                    : $matches[0];
            },
            $html
        ) ?? '';
        $html = preg_replace_callback('@<meta\b[^>]*>@i', function (array $matches): string {
            $tag = $matches[0];
            if ( ! preg_match('/\bhttp-equiv\s*=\s*("refresh"|\'refresh\'|refresh)(?:\s|>)/i', $tag) ) {
                return $tag;
            }
            return preg_match('/\bcontent\s*=\s*("[^\"]*(?:url\s*=\s*)?(?:javascript|vbscript|data|file)\s*:[^\"]*"|\'[^\']*(?:url\s*=\s*)?(?:javascript|vbscript|data|file)\s*:[^\']*\'|[^\s>]*(?:javascript|vbscript|data|file)\s*:)/i', $tag)
                ? ''
                : $tag;
        }, $html) ?? '';
        $html = preg_replace('/\s+srcdoc\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)/i', '', $html) ?? '';

        return trim($html);
    }

    public static function isFallbackUrlAttribute(string $attribute): bool
    {
        return in_array($attribute, array(
            'action', 'archive', 'background', 'cite', 'codebase', 'data', 'formaction',
            'href', 'longdesc', 'manifest', 'ping', 'poster', 'profile', 'src', 'usemap', 'xlink:href',
        ), true);
    }

    /**
     * Keep only safe srcset candidates while retaining their source-selection
     * descriptors. The URL policy deliberately matches fallback image `src`.
     */
    public static function safeFallbackSrcset(string $srcset): string
    {
        $candidates = array();
        foreach ( SrcsetParser::parse($srcset) as $candidate ) {
            if ( self::safeFallbackUrl($candidate['url'], 'src') ) {
                $candidates[] = $candidate['url'] . ( '' !== $candidate['descriptor'] ? ' ' . $candidate['descriptor'] : '' );
            }
        }

        return implode(', ', $candidates);
    }

    public static function safeFallbackUrl(string $url, string $attribute): bool
    {
        $normalized = strtolower(preg_replace('/[\x00-\x20\x7f]+/', '', html_entity_decode($url, ENT_QUOTES | ENT_HTML5, 'UTF-8')) ?? '');
        for ( $index = 0; $index < 2 && str_contains($normalized, '%'); ++$index ) {
            $normalized = rawurldecode($normalized);
        }

        if ( '' === $normalized || ! preg_match('/^([a-z][a-z0-9+.-]*):/i', $normalized, $scheme) ) {
            return true;
        }
        if ( in_array($scheme[1], array( 'http', 'https', 'mailto', 'tel' ), true) ) {
            return true;
        }

        return 'src' === $attribute && (bool) preg_match('#^data:image/(?:avif|gif|jpeg|png|webp);base64,[a-z0-9+/=]+$#i', $normalized);
    }

    /**
     * @return array{html: string, bytes: int, truncated: bool}
     */
    public static function boundedFallbackHtml(string $html): array
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
    public static function boundedFallbackText(string $text): array
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

    public static function directElementChildCount(DOMElement $element): int
    {
        $count = 0;
        foreach ( $element->childNodes as $child ) {
            if ( $child instanceof DOMElement ) {
                ++$count;
            }
        }

        return $count;
    }

    public static function mergeClassNames(string ...$classNames): string
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

    public static function htmlAttributeString(array $attrs): string
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
    public static function hasAncestorTag(DOMElement $element, array $tagNames): bool
    {
        for ( $node = $element->parentNode; $node instanceof DOMElement && 'body' !== strtolower($node->tagName); $node = $node->parentNode ) {
            if ( in_array(strtolower($node->tagName), $tagNames, true) ) {
                return true;
            }
        }

        return false;
    }

    public static function hasSourceNavigationSignal(DOMElement $element): bool
    {
        if ( 'navigation' === strtolower(self::attr($element, 'role')) ) {
            return true;
        }

        foreach ( array( 'class', 'id' ) as $attribute ) {
            foreach ( preg_split('/[^a-z0-9]+/', strtolower(self::attr($element, $attribute))) ?: array() as $token ) {
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
    public static function safeNavigationUrl(string $url): string
    {
        return LinkUrlSanitizer::sanitize($url);
    }

    public static function runtimeIslandSelector(DOMElement $element): string
    {
        $id = trim(self::attr($element, 'id'));
        if ( '' !== $id ) {
            return '#' . $id;
        }

        foreach ( preg_split('/\s+/', trim(self::attr($element, 'class'))) ?: array() as $class ) {
            if ( '' !== $class ) {
                return '.' . $class;
            }
        }

        return self::elementSelector($element);
    }

    /**
     * @return array<int, array<string, string>>
     */
    public static function eventMetadata(DOMElement $element): array
    {
        $events = array();
        foreach ( self::htmlAttributes($element) as $name => $value ) {
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

    public static function isSafeSvgContent(string $content): bool
    {
        return '' !== trim($content) && preg_match('/<svg(?:\s|>)/i', $content) && ! preg_match('/<\s*script\b|\son[a-z]+\s*=|javascript\s*:/i', $content);
    }

    /**
     * Whether an inline SVG carries any drawable artwork worth preserving.
     *
     * Returns true when the SVG has at least one shape/structure element
     * (path/circle/rect/text/g/...) outside of any foreignObject subtree.
     * Elements that carry no visual artwork on their own — script/style/
     * foreignObject (all stripped during sanitization) and the metadata-only
     * title/desc/metadata — do not count. An SVG whose only content is unsafe
     * has nothing left to render once sanitized, so callers fall back to the
     * bounded diagnostic instead of emitting an empty graphic.
     */
    public static function svgHasDrawableContent(DOMElement $element): bool
    {
        foreach ( $element->getElementsByTagName('*') as $child ) {
            if ( ! $child instanceof DOMElement ) {
                continue;
            }
            $tag = strtolower($child->tagName);
            if ( in_array($tag, array( 'script', 'style', 'foreignobject', 'title', 'desc', 'metadata', 'defs' ), true) ) {
                continue;
            }
            if ( self::hasAncestorTag($child, array( 'foreignobject' )) ) {
                continue;
            }
            // A <use> that points at an external sprite file (href="sprite.svg#id"
            // rather than a local "#id") cannot be inlined: the referenced symbol
            // lives in another file that does not travel with the imported markup,
            // so the emitted <use> would render nothing. It carries no drawable
            // artwork of its own, so it does not, by itself, make the SVG
            // preservable — the SVG falls through to the bounded fallback
            // diagnostic instead of emitting a broken external reference. A local
            // <use href="#id"> (resolved against an in-document symbol/defs) and
            // any real shape element still count as drawable.
            if ( 'use' === $tag && self::isExternalSpriteUse($child) ) {
                continue;
            }

            return true;
        }

        return false;
    }

    /**
     * Whether a <use> element references an external sprite file rather than an
     * in-document fragment. An external reference is any href/xlink:href whose
     * target is not a bare local "#id" fragment.
     */
    public static function isExternalSpriteUse(DOMElement $element): bool
    {
        $href = trim(self::attr($element, 'href'));
        if ( '' === $href ) {
            $href = trim(self::attr($element, 'xlink:href'));
        }

        return '' !== $href && '#' !== substr($href, 0, 1);
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return array<int, array<string, mixed>>
     */
    public static function dedupeArrayRows(array $rows): array
    {
        return DeterministicRowDeduplicator::dedupe($rows);
    }

    /**
     * Whether $container is $element or one of its ancestors. Shared DOM
     * ancestry helper; consumed by form dispatch and navigation suppression.
     */
    public static function elementContains(DOMElement $container, DOMElement $element): bool
    {
        for ( $node = $element; $node instanceof DOMElement; $node = $node->parentNode ) {
            if ( $node->isSameNode($container) ) {
                return true;
            }
        }

        return false;
    }
}
