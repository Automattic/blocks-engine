<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style;

use Automattic\BlocksEngine\PhpTransformer\Css\CssValueSplitter;

/** Shared predicates for comparing CSS values without changing their authored form. */
final class CssValueInspector
{
    public static function comparable(string $value): string
    {
        return strtolower(trim(preg_replace('/\s*!important\s*$/i', '', $value) ?? $value));
    }

    public static function withoutImportant(string $value): string
    {
        return trim(preg_replace('/\s*!\s*important\s*$/i', '', $value) ?? $value);
    }

    public static function isImportant(string $value): bool
    {
        return 1 === preg_match('/\s*!\s*important\s*$/i', $value);
    }

    public static function isNonZero(string $value): bool
    {
        $normalized = strtolower(trim($value));
        if ( '' === $normalized || 'none' === $normalized ) {
            return false;
        }
        foreach ( preg_split('/[\s,]+/', $normalized) ?: array() as $token ) {
            if ( '' !== $token && ! preg_match('/^0(?:\.0+)?[a-z%]*$/', $token) ) {
                return true;
            }
        }
        return false;
    }

    public static function hasDefiniteWidth(string $css): bool
    {
        foreach ( CssValueSplitter::splitTopLevel($css, array( ';' )) as $declaration ) {
            $colon = strpos($declaration, ':');
            if ( false === $colon ) {
                continue;
            }
            $name = strtolower(trim(substr($declaration, 0, $colon)));
            if ( 'width' !== $name && 'min-width' !== $name ) {
                continue;
            }
            $value = strtolower(self::withoutImportant(substr($declaration, $colon + 1)));
            if ( '' === $value || str_contains($value, 'var(') || in_array($value, array( 'auto', 'inherit', 'initial', 'unset', 'none', 'min-content', 'max-content', 'fit-content', 'content' ), true) ) {
                continue;
            }
            return true;
        }
        return false;
    }

    public static function hasDefiniteHeight(string $css): bool
    {
        return self::hasAuthoredLengthProperty($css, 'height');
    }

    public static function hasAuthoredMinimumHeight(string $css): bool
    {
        return self::hasAuthoredLengthProperty($css, 'min-height');
    }

    private static function hasAuthoredLengthProperty(string $css, string $property): bool
    {
        foreach ( CssValueSplitter::splitTopLevel($css, array( ';' )) as $declaration ) {
            $colon = strpos($declaration, ':');
            if ( false === $colon || $property !== strtolower(trim(substr($declaration, 0, $colon))) ) {
                continue;
            }
            $value = strtolower(self::withoutImportant(substr($declaration, $colon + 1)));
            if ( '' === $value || in_array($value, array( 'auto', 'inherit', 'initial', 'unset', 'none', 'min-content', 'max-content', 'fit-content', 'content' ), true) ) {
                continue;
            }
            // A bare custom property may resolve to a keyword such as auto. CSS math
            // functions, including ones containing vars, remain authored lengths.
            if ( str_contains($value, 'var(')
                && 1 !== preg_match('/^(?:calc|min|max|clamp|round|mod|rem|sin|cos|tan|asin|acos|atan|atan2|pow|sqrt|hypot|log|exp|abs|sign)\(/', $value) ) {
                continue;
            }
            return true;
        }
        return false;
    }

    public static function hasAutoHeight(string $css): bool
    {
        foreach ( CssValueSplitter::splitTopLevel($css, array( ';' )) as $declaration ) {
            $colon = strpos($declaration, ':');
            if ( false !== $colon
                && 'height' === strtolower(trim(substr($declaration, 0, $colon)))
                && 'auto' === strtolower(self::withoutImportant(substr($declaration, $colon + 1)))
            ) {
                return true;
            }
        }
        return false;
    }
}
