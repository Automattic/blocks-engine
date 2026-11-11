<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style;

use Automattic\BlocksEngine\PhpTransformer\Support\RenderEquivalentMarkup;
use Automattic\BlocksEngine\PhpTransformer\Css\CssValueSplitter;

/** Shared predicates for comparing CSS values without changing their authored form. */
final class CssValueInspector
{
    public static function comparable(string $value): string
    {
        return strtolower(self::withoutImportant($value));
    }

    /**
     * One declaration's value in comparable form; empty when it is absent.
     *
     * @param array<string, mixed> $declarations
     */
    public static function declared(array $declarations, string $property): string
    {
        return self::comparable((string) ($declarations[$property] ?? ''));
    }

    /**
     * Expand a 1–4 value CSS box shorthand into physical sides.
     *
     * @return array{0: string, 1: string, 2: string, 3: string} top, right, bottom, left
     */
    public static function expandBoxShorthand(string $value): array
    {
        $parts = CssValueSplitter::splitTopLevelWhitespace(trim($value));
        $count = count($parts);

        return match ( true ) {
            1 === $count => array( $parts[0], $parts[0], $parts[0], $parts[0] ),
            2 === $count => array( $parts[0], $parts[1], $parts[0], $parts[1] ),
            3 === $count => array( $parts[0], $parts[1], $parts[2], $parts[1] ),
            4 <= $count => array( $parts[0], $parts[1], $parts[2], $parts[3] ),
            default => array( '', '', '', '' ),
        };
    }

    public static function isBorderWidthToken(string $token): bool
    {
        $lower = strtolower($token);

        return 1 === preg_match('/^[0-9.]+(?:px|em|rem|%|pt|vw|vh)?$/i', $token)
            || in_array($lower, array( 'thin', 'medium', 'thick' ), true);
    }

    /**
     * A definite, non-percentage length whose used size can overflow its
     * containing block when the viewport is narrower than the authored value.
     */
    public static function isAbsoluteLength(string $value): bool
    {
        return 1 === preg_match('/^(?:\d+|\d*\.\d+)(?:px|em|rem|ch|ex|cm|mm|in|pt|pc)?$/', self::comparable($value));
    }

    /**
     * An authored declaration value safe to restate verbatim inside a
     * generated rule: `!important` stripped, '' when the value is malformed
     * (unbalanced parens or quotes, a trailing escape, or a token that would
     * break out of the declaration block).
     */
    public static function carriableDeclarationValue(string $rawValue): string
    {
        $value = trim(preg_replace('/\s*!\s*important\s*$/i', '', trim($rawValue)) ?? $rawValue);
        if ( '' === $value || preg_match('~[{}<>;]|/\*~', $value) ) {
            return '';
        }
        if ( substr_count($value, '(') !== substr_count($value, ')') ) {
            return '';
        }
        if ( 0 !== substr_count($value, '"') % 2 || 0 !== substr_count($value, "'") % 2 ) {
            return '';
        }
        // An odd trailing run of backslashes escapes whatever follows the value,
        // which in the emitted rule is the closing brace.
        if ( 1 === preg_match('/(\\\\+)$/', $value, $trailing) && 0 !== strlen($trailing[1]) % 2 ) {
            return '';
        }

        return $value;
    }

    public static function withoutImportant(string $value): string
    {
        return trim(preg_replace('/\s*!\s*important\s*$/i', '', $value) ?? $value);
    }

    /**
     * Whether a colour value paints nothing: the `transparent` keyword, a
     * zero-alpha hex, or an `rgba()`/`hsla()` whose alpha is zero.
     */
    public static function isTransparentColor(string $value): bool
    {
        $normalized = self::comparable($value);
        if ( '' === $normalized ) {
            return false;
        }
        if ( 'transparent' === $normalized ) {
            return true;
        }

        $compact = preg_replace('/\s+/', '', $normalized) ?? '';
        if ( in_array($compact, array( '#0000', '#00000000' ), true) ) {
            return true;
        }

        return 1 === preg_match('/^(?:rgba?|hsla?)\((?:[^,]+,){3}0(?:\.0+)?\)$/', $compact)
            || 1 === preg_match('#^(?:rgba?|hsla?)\([^/]+/0(?:\.0+)?%?\)$#', $compact);
    }

    public static function isImportant(string $value): bool
    {
        return 1 === preg_match('/\s*!\s*important\s*$/i', $value);
    }

    /** @see RenderEquivalentMarkup::isZeroLength() */
    public static function isZeroLength(string $value): bool
    {
        return RenderEquivalentMarkup::isZeroLength($value);
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
     * Whether a declaration set moves its box out of sight: an absolutely
     * positioned box shifted by a negative `left` or `top` offset of at least
     * 999px, the same off-screen threshold `hidesText()` applies to
     * `text-indent`.
     *
     * @param array<string, string> $declarations
     */
    public static function isMovedOffscreenBox(array $declarations): bool
    {
        if ( ! in_array(self::comparable((string) ($declarations['position'] ?? '')), array( 'absolute', 'fixed' ), true) ) {
            return false;
        }
        foreach ( array( 'left', 'top' ) as $property ) {
            if ( 1 === preg_match('/^-(\d+(?:\.\d+)?)(?:px)?$/D', self::comparable((string) ($declarations[$property] ?? '')), $match) && (float) $match[1] >= 999 ) {
                return true;
            }
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

    /**
     * Whether a lowercased, normalized value is one core/media-text's layout
     * declarations (`display`, `flex-direction`, `flex-basis`, `width`,
     * `grid-template-columns`, …) can carry; other properties pass.
     */
    public static function isValidMediaTextLayoutValue(string $property, string $value): bool
    {
        if ( 'display' === $property ) {
            return in_array($value, array(
                'block', 'contents', 'flow-root', 'flex', 'grid', 'inline', 'inline-block',
                'inline-flex', 'inline-grid', 'inline-table', 'list-item', 'none', 'ruby',
                'ruby-base', 'ruby-base-container', 'ruby-text', 'ruby-text-container',
                'table', 'table-caption', 'table-cell', 'table-column', 'table-column-group',
                'table-footer-group', 'table-header-group', 'table-row', 'table-row-group',
            ), true) || 1 === preg_match('/^(?:block|inline)\s+(?:flow|flow-root|flex|grid|ruby)(?:\s+list-item)?$/', $value);
        }

        if ( 'flex-direction' === $property ) {
            return in_array($value, array( 'column', 'column-reverse', 'row', 'row-reverse' ), true);
        }

        if ( 'flex-flow' === $property ) {
            $directions = array( 'column', 'column-reverse', 'row', 'row-reverse' );
            $wraps = array( 'nowrap', 'wrap', 'wrap-reverse' );
            $seenDirection = false;
            $seenWrap = false;
            $components = CssValueSplitter::splitTopLevelWhitespace($value);
            if ( array() === $components || 2 < count($components) ) {
                return false;
            }
            foreach ( $components as $component ) {
                if ( in_array($component, $directions, true) && ! $seenDirection ) {
                    $seenDirection = true;
                    continue;
                }
                if ( in_array($component, $wraps, true) && ! $seenWrap ) {
                    $seenWrap = true;
                    continue;
                }
                return false;
            }
            return true;
        }

        if ( 'order' === $property ) {
            return is_numeric($value);
        }

        if ( 'align-items' === $property ) {
            return in_array($value, array(
                'anchor-center', 'baseline', 'center', 'dialog', 'end', 'first baseline',
                'flex-end', 'flex-start', 'last baseline', 'normal', 'self-end', 'self-start',
                'start', 'stretch',
            ), true) || 1 === preg_match('/^(?:safe|unsafe)\s+(?:center|end|flex-end|flex-start|self-end|self-start|start)$/', $value);
        }

        if ( 'direction' === $property ) {
            return in_array($value, array( 'ltr', 'rtl' ), true);
        }

        if ( in_array($property, array( 'flex-basis', 'width' ), true) ) {
            return in_array($value, array( 'auto', 'contain', 'content', 'fit-content', 'max-content', 'min-content', 'stretch' ), true)
                || 1 === preg_match('/^[+-]?(?:\d+(?:\.\d*)?|\.\d+)(?:%|[a-z]+)?$/i', $value)
                || 1 === preg_match('/^(?:calc|clamp|fit-content|max|min|var)\(.+\)$/i', $value);
        }

        if ( 'grid-template-columns' === $property ) {
            return self::isValidGridTemplateColumns($value);
        }

        return true;
    }

    private static function isValidGridTemplateColumns(string $value): bool
    {
        if ( in_array($value, array( 'masonry', 'none', 'subgrid' ), true) ) {
            return true;
        }

        $tracks = CssValueSplitter::splitTopLevelWhitespace($value);
        if ( array() === $tracks ) {
            return false;
        }
        foreach ( $tracks as $track ) {
            if ( in_array($track, array( 'auto', 'max-content', 'min-content' ), true)
                || 1 === preg_match('/^\[[^\]]+\]$/', $track)
                || 1 === preg_match('/^[+-]?(?:\d+(?:\.\d*)?|\.\d+)(?:%|fr|[a-z]+)$/i', $track)
                || 1 === preg_match('/^(?:calc|clamp|fit-content|max|min|minmax|repeat|var)\(.+\)$/i', $track)
            ) {
                continue;
            }
            return false;
        }

        return true;
    }
}
