<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\Css;

/** Shared inspection helpers for parsed selector compounds. */
final class CssSelectorCompoundInspector
{
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
        foreach ( $compound['any'] ?? array() as $group ) {
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
                if ( array() !== ($nested['attributes'] ?? array()) || self::hasLiveAttributePredicate($nested) ) {
                    return true;
                }
            }
        }
        return false;
    }
}
