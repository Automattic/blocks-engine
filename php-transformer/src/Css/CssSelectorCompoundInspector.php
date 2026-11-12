<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\Css;

/** Shared inspection helpers for selectors and their parsed compounds. */
final class CssSelectorCompoundInspector
{
    /**
     * The subject (rightmost) compound, or an empty compound when there is none.
     *
     * @param array<int, array<string, mixed>> $compounds
     * @return array<string, mixed>
     */
    public static function rightmost(array $compounds): array
    {
        return array() === $compounds ? array() : $compounds[array_key_last($compounds)];
    }

    /** @param array<string, mixed> $compound @return list<string> */
    public static function dataAttributeNames(array $compound): array
    {
        $names = array();
        foreach ($compound['attributes'] ?? array() as $attribute) {
            $name = (string) ($attribute['name'] ?? '');
            if (str_starts_with($name, 'data-')) $names[] = $name;
        }
        foreach ($compound['not'] ?? array() as $negated) {
            foreach ($negated['compounds'] ?? array() as $nested) {
                array_push($names, ...self::dataAttributeNames($nested));
            }
        }
        foreach ($compound['any'] ?? array() as $group) {
            foreach ($group['alternatives'] ?? array() as $alternative) {
                foreach ($alternative['compounds'] ?? array() as $nested) {
                    array_push($names, ...self::dataAttributeNames($nested));
                }
            }
        }
        foreach ($compound['has'] ?? array() as $group) {
            foreach ($group['alternatives'] ?? array() as $alternative) {
                foreach ($alternative['compounds'] ?? array() as $nested) {
                    array_push($names, ...self::dataAttributeNames($nested));
                }
            }
        }
        return array_values(array_unique($names));
    }

    /** @param array<string, mixed> $compound */
    public static function containsDataAttribute(array $compound): bool
    {
        foreach ( $compound['attributes'] ?? array() as $attribute ) {
            if ( str_starts_with((string) ($attribute['name'] ?? ''), 'data-') ) {
                return true;
            }
        }
        foreach ( $compound['not'] ?? array() as $negated ) {
            foreach ( $negated['compounds'] ?? array() as $nested ) {
                if ( self::containsDataAttribute($nested) ) {
                    return true;
                }
            }
        }
        foreach ( $compound['any'] ?? array() as $group ) {
            foreach ( $group['alternatives'] ?? array() as $alternative ) {
                foreach ( $alternative['compounds'] ?? array() as $nested ) {
                    if ( self::containsDataAttribute($nested) ) {
                        return true;
                    }
                }
            }
        }
        foreach ( $compound['has'] ?? array() as $group ) {
            foreach ( $group['alternatives'] ?? array() as $alternative ) {
                foreach ( $alternative['compounds'] ?? array() as $nested ) {
                    if ( self::containsDataAttribute($nested) ) {
                        return true;
                    }
                }
            }
        }
        return false;
    }

    /** @param array<string, mixed> $compound */
    public static function hasLiveAttributePredicate(array $compound): bool
    {
        foreach ( $compound['attributes'] ?? array() as $attribute ) {
            if ( ! str_starts_with((string) ($attribute['name'] ?? ''), 'data-') ) {
                return true;
            }
        }
        foreach ( $compound['not'] ?? array() as $negated ) {
            foreach ( $negated['compounds'] ?? array() as $nested ) {
                if ( self::hasLiveAttributePredicate($nested) ) {
                    return true;
                }
            }
        }
        foreach ( $compound['has'] ?? array() as $group ) {
            foreach ( $group['alternatives'] ?? array() as $alternative ) {
                foreach ( $alternative['compounds'] ?? array() as $nested ) {
                    if ( array() !== ($nested['attributes'] ?? array()) || self::hasLiveAttributePredicate($nested) ) {
                        return true;
                    }
                }
            }
        }
        return false;
    }

    /** The selector with every `:not(...)` removed: what it targets, not what it excludes. */
    public static function positiveSelector(string $selector): string
    {
        $positive = '';
        $depth = 0;
        $length = strlen($selector);
        for ($i = 0; $i < $length; ++$i) {
            if (0 === $depth && 0 === strncasecmp(substr($selector, $i, 5), ':not(', 5)) {
                $depth = 1;
                $i += 4;
                continue;
            }
            if ($depth > 0) {
                if ('(' === $selector[$i]) ++$depth;
                elseif (')' === $selector[$i]) --$depth;
                continue;
            }
            $positive .= $selector[$i];
        }
        return $positive;
    }
}
