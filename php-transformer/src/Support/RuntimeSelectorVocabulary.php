<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\Support;

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
        return '(?:[#.]' . $name . '|' . $name . '\\.' . $name . '|\\[data-' . $name . '(?:=["\'][^"\']{1,80}["\'])?\\]|' . $name . '\\[data-' . $name . '(?:=["\'][^"\']{1,80}["\'])?\\]|canvas|svg|' . implode('|', self::RUNTIME_TAG_SELECTORS) . ')';
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
}
