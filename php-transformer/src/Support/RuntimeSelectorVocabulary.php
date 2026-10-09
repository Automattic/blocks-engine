<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\Support;

use DOMElement;

/**
 * Shared vocabulary for reasoning about runtime CSS selectors.
 *
 * Answers the selector-level questions the artifact compiler, the runtime
 * dependency parity report, and source-element classification all ask: which
 * selector shapes a captured script can plausibly target, which data-attribute
 * selectors a CSS selector declares, and whether a selector names presentation
 * (an animation or scroll effect) rather than behavior.
 *
 * These are pure functions of their arguments and are static for the same
 * reason {@see SourceDom} is: a caller needs no instance, no constructor
 * argument, and no injected closure to reach one. The vocabulary previously
 * existed as three independent copies of the same 13-token list across two
 * namespaces, which is how the copies were free to disagree.
 */
final class RuntimeSelectorVocabulary
{
    /** Element selectors a captured runtime script may legitimately target. */
    public const RUNTIME_TAG_SELECTORS = array( 'button', 'input', 'select', 'textarea', 'ul', 'ol', 'li' );

    /**
     * Selector shapes recognized inside captured script source.
     */
    public static function scriptSelectorPattern(): string
    {
        $name = '[A-Za-z][A-Za-z0-9_-]*';
        $attribute = '\\[data-' . $name . '(?:=["\'][^"\']{1,80}["\'])?\\]';
        return '(?:' . $name . ')?(?:' . $attribute . '){2,}|(?:[#.]' . $name . '|' . $name . '\\.' . $name . '|' . $attribute . '|' . $name . $attribute . '|canvas|svg|' . implode('|', self::RUNTIME_TAG_SELECTORS) . ')';
    }

    /**
     * Whether a selector names presentation rather than behavior.
     *
     * Only a whole class selector, a whole id selector, or a data-attribute
     * selector is considered. A compound or descendant selector names a
     * position in a document rather than an effect, so it is not presentational
     * on the strength of one of its parts.
     */
    public static function isPresentationalAnimation(string $selector): bool
    {
        $name = '';
        if ( preg_match('/\[(data-[A-Za-z][A-Za-z0-9_-]*)/', $selector, $match) ) {
            $name = substr(strtolower((string) $match[1]), 5);
        } elseif ( preg_match('/^(?:[a-z][a-z0-9-]*\.|\.)([A-Za-z][A-Za-z0-9_-]*)$/', $selector, $match) ) {
            $name = strtolower((string) $match[1]);
        } elseif ( preg_match('/^#([A-Za-z][A-Za-z0-9_-]*)$/', $selector, $match) ) {
            $name = strtolower((string) $match[1]);
        }

        if ( '' === $name ) {
            return false;
        }

        foreach ( preg_split('/[^a-z0-9]+/', $name) ?: array() as $token ) {
            if ( in_array($token, array( 'animate', 'animation', 'appear', 'count', 'counter', 'delay', 'fade', 'motion', 'parallax', 'reveal', 'scroll', 'stagger', 'transition' ), true) ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Behavioral data-attribute selectors declared by a CSS selector.
     *
     * Note: the second scan deliberately runs against the reassigned $selector
     * rather than the original argument, preserving the behavior of the three
     * copies this replaces. That reassignment looks accidental and is worth a
     * separate, deliberate look; it is not changed here so this stays a move.
     *
     * @return array<int, string>
     */
    public static function dataAttributeSelectorsFromCssSelector(string $selector): array
    {
        $selectors = array();
        if ( preg_match_all('/(?:^|[\s>+~,])([a-z][a-z0-9-]*)?\[(data-[A-Za-z][A-Za-z0-9_-]*)(?:\s*[*^$|~]?=\s*(?:"[^"]{0,120}"|\'[^\']{0,120}\'|[^\]\s"\']{1,120}))?\]/', $selector, $matches, PREG_SET_ORDER) ) {
            foreach ( $matches as $match ) {
                $selector = strtolower((string) ($match[1] ?? '')) . '[' . strtolower((string) $match[2]) . ']';
                if ( ! self::isPresentationalAnimation($selector) ) {
                    $selectors[$selector] = true;
                }
            }
        }
        if ( preg_match_all('/\[(data-[A-Za-z][A-Za-z0-9_-]*)(?:\s*[*^$|~]?=\s*(?:"[^"]{0,120}"|\'[^\']{0,120}\'|[^\]\s"\']{1,120}))?\]/', $selector, $matches) ) {
            foreach ( $matches[1] as $attribute ) {
                $selector = '[' . strtolower((string) $attribute) . ']';
                if ( ! self::isPresentationalAnimation($selector) ) {
                    $selectors[$selector] = true;
                }
            }
        }

        return array_keys($selectors);
    }

    /**
     * Whether a bounded runtime selector matches this element.
     *
     * Attribute equality is part of the selector. A missing attribute, a
     * different value, a different tag, or an unrecognized shape does not
     * match. Callers that cannot prove a match must fail closed.
     *
     * @param array<int, string> $bareTags
     */
    public static function matchesElement(DOMElement $element, string $selector, array $bareTags): bool
    {
        $tag = strtolower($element->tagName);
        if ( $selector === $tag && in_array($tag, $bareTags, true) ) {
            return true;
        }
        if ( preg_match('/^([a-z][a-z0-9-]*)\.([A-Za-z][A-Za-z0-9_-]*)$/', $selector, $match) ) {
            return $tag === strtolower((string) $match[1]) && in_array((string) $match[2], preg_split('/\s+/', trim($element->getAttribute('class'))) ?: array(), true);
        }
        $parts = self::parseCompoundAttributeSelector($selector);
        if (null === $parts || ('' !== $parts['tag'] && $tag !== $parts['tag'])) return false;
        foreach ($parts['attributes'] as $attribute) {
            if (!$element->hasAttribute($attribute['attribute'])
                || (null !== $attribute['value'] && $element->getAttribute($attribute['attribute']) !== $attribute['value'])
            ) return false;
        }
        return true;
    }

    /**
     * Canonical script selector. Quoted and unquoted equality values are kept.
     */
    public static function canonicalScriptSelector(string $selector): string
    {
        $selector = trim($selector);
        $parsed = self::parseCompoundAttributeSelector($selector);
        if (null === $parsed) return $selector;
        $canonical = $parsed['tag'];
        foreach ($parsed['attributes'] as $attribute) {
            $canonical .= '[' . $attribute['attribute'];
            if (null !== $attribute['value']) $canonical .= '="' . str_replace(array('\\', '"'), array('\\\\', '\\"'), $attribute['value']) . '"';
            $canonical .= ']';
        }
        return $canonical;
    }

    /**
     * @return array{tag: string, attribute: string, value: ?string}|null
     */
    public static function parseAttributeSelector(string $selector): ?array
    {
        $pattern = '/^(?:([a-z][a-z0-9-]*))?\[(data-[A-Za-z][A-Za-z0-9_-]*)(?:\s*=\s*(?:"((?:\\\\.|[^"\\\\])*)"|\'((?:\\\\.|[^\'\\\\])*)\'|([^\s"\'\]]{1,80})))?\]$/i';
        if ( 1 !== preg_match($pattern, $selector, $match) ) {
            return null;
        }
        $value = null;
        if ( str_contains($selector, '=') ) {
            $raw = '';
            if ( isset($match[3]) && '' !== $match[3] ) {
                $raw = $match[3];
            } elseif ( isset($match[4]) && '' !== $match[4] ) {
                $raw = $match[4];
            } elseif ( isset($match[5]) && '' !== $match[5] ) {
                $raw = $match[5];
            }
            $value = self::unescapeCssString($raw);
        }

        return array(
            'tag' => strtolower((string) ($match[1] ?? '')),
            'attribute' => strtolower((string) $match[2]),
            'value' => $value,
        );
    }

    /** @return array{tag:string,attributes:array<int,array{attribute:string,value:?string}>}|null */
    public static function parseCompoundAttributeSelector(string $selector): ?array
    {
        if (1 !== preg_match('/^(?:([a-z][a-z0-9-]*))?(?:\\[(data-[A-Za-z][A-Za-z0-9_-]*)(?:\\s*=\\s*(?:"((?:\\\\.|[^"\\\\])*)"|\'((?:\\\\.|[^\'\\\\])*)\'|([^\\s"\'\\]]{1,80})))?\\])+$/i', $selector, $match)) return null;
        $attributes = array();
        $attributePattern = '/\\[(data-[A-Za-z][A-Za-z0-9_-]*)(?:\\s*=\\s*(?:"((?:\\\\.|[^"\\\\])*)"|\'((?:\\\\.|[^\'\\\\])*)\'|([^\\s"\'\\]]{1,80})))?\\]/i';
        if (!preg_match_all($attributePattern, $selector, $matches, PREG_SET_ORDER)) return null;
        foreach ($matches as $attributeMatch) {
            $hasValue = str_contains($attributeMatch[0], '=');
            $raw = (string) (($attributeMatch[2] ?? '') ?: (($attributeMatch[3] ?? '') ?: ($attributeMatch[4] ?? '')));
            $attributes[] = array('attribute' => strtolower($attributeMatch[1]), 'value' => $hasValue ? self::unescapeCssString($raw) : null);
        }
        return array('tag' => strtolower((string) ($match[1] ?? '')), 'attributes' => $attributes);
    }

    private static function unescapeCssString(string $value): string
    {
        $unescaped = preg_replace_callback(
            '/\\\\(?:([0-9a-fA-F]{1,6})\s?|(.))/',
            static function (array $match): string {
                if ( isset($match[1]) && '' !== $match[1] ) {
                    $code = hexdec($match[1]);
                    if ( $code < 0x80 ) {
                        return chr($code);
                    }
                    if ( function_exists('mb_chr') && $code <= 0x10FFFF ) {
                        return (string) mb_chr($code, 'UTF-8');
                    }

                    return '';
                }

                return (string) ($match[2] ?? '');
            },
            $value
        );

        return is_string($unescaped) ? $unescaped : $value;
    }
}
