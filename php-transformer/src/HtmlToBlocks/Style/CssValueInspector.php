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

    /**
     * A definite, non-percentage length whose used size can overflow its
     * containing block when the viewport is narrower than the authored value.
     */
    public static function isAbsoluteLength(string $value): bool
    {
        return 1 === preg_match('/^(?:\d+|\d*\.\d+)(?:px|em|rem|ch|ex|cm|mm|in|pt|pc)?$/', self::comparable($value));
    }

    public static function withoutImportant(string $value): string
    {
        return trim(preg_replace('/\s*!\s*important\s*$/i', '', $value) ?? $value);
    }

    public static function isImportant(string $value): bool
    {
        return 1 === preg_match('/\s*!\s*important\s*$/i', $value);
    }

    /**
     * Whether a CSS length/box value contributes real geometry. A universal reset
     * (`* { margin: 0; padding: 0 }`) sets zero-valued box properties on every
     * element; those must not be treated as box chrome or every wrapper would be
     * disqualified from collapsing to a paragraph. Treats empty, `0`, `none`, and
     * all-zero shorthand values (`0 0 0 0`, `0px`) as no geometry.
     */
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

    /**
     * Whether one declaration, on its own, keeps an element's text from being
     * seen while its box may still paint: an off-screen `text-indent` (the
     * image-replacement idiom), a zero `font-size`, or hidden `visibility`.
     */
    public static function hidesText(string $property, string $value): bool
    {
        $value = self::comparable($value);
        switch ( strtolower($property) ) {
            case 'visibility':
                return in_array($value, array( 'hidden', 'collapse' ), true);
            case 'font-size':
                return 1 === preg_match('/^0(?:\.0+)?(?:[a-z]+|%)?$/', $value);
            case 'text-indent':
                if ( 1 !== preg_match('/^-(\d+(?:\.\d+)?|\.\d+)(px|em|rem|ch|%|vw)?$/', $value, $match) ) {
                    return false;
                }
                $offset = (float) $match[1];
                return match ( $match[2] ?? '' ) {
                    'em', 'rem', 'ch' => $offset >= 50,
                    '%', 'vw' => $offset >= 100,
                    default => $offset >= 999,
                };
        }

        return false;
    }

    /**
     * Whether a declaration set clips its box out of sight: the screen-reader
     * text pattern (an absolutely positioned box of at most 1px, overflow
     * hidden, clipped by `clip`/`clip-path`) or a zero-size overflow-hidden box.
     *
     * @param array<string, string> $declarations
     */
    public static function isVisuallyClippedBox(array $declarations): bool
    {
        $value = static fn (string $property): string => self::comparable((string) ($declarations[$property] ?? ''));
        if ( 'hidden' !== $value('overflow') ) {
            return false;
        }

        $isAtMost = static fn (string $length, string $limit): bool => 1 === preg_match('/^(?:0|' . $limit . ')(?:px)?$/', $length);
        if ( $isAtMost($value('width'), '0') && $isAtMost($value('height'), '0') ) {
            return true;
        }

        return 'absolute' === $value('position')
            && $isAtMost($value('width'), '1')
            && $isAtMost($value('height'), '1')
            && (str_starts_with($value('clip'), 'rect(') || str_starts_with($value('clip-path'), 'inset('));
    }
}
