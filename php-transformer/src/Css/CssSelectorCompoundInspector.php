<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\Css;

/** Shared inspection helpers for parsed selector compounds. */
final class CssSelectorCompoundInspector
{
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
}
