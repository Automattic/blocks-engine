<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style;

/** Bounded substitution shared by element-scoped custom-property resolvers. */
final class CssVariableExpander
{
    private const MAX_EXPANSION_DEPTH = 5;
    private const MAX_EXPANDED_BYTES = 4096;

    /**
     * A defined variable replaces its whole reference without evaluating the
     * fallback. Otherwise expand the fallback, including nested functions.
     * Missing, cyclic, oversized or malformed values remain invalid (null).
     *
     * @param callable(string): ?string $resolve
     */
    public static function expand(string $value, callable $resolve, int $depth = 0): ?string
    {
        if ( $depth > self::MAX_EXPANSION_DEPTH || strlen($value) > self::MAX_EXPANDED_BYTES ) {
            return null;
        }
        $result = '';
        $offset = 0;
        while ( false !== ($start = strpos($value, 'var(', $offset)) ) {
            $close = self::matchingParenthesis($value, $start + 3);
            if ( null === $close ) {
                return null;
            }
            $arguments = substr($value, $start + 4, $close - $start - 4);
            $comma = self::topLevelComma($arguments);
            $name = trim(null === $comma ? $arguments : substr($arguments, 0, $comma));
            if ( 1 !== preg_match('/^--[A-Za-z0-9_-]+$/D', $name) ) {
                return null;
            }
            $resolved = $resolve($name);
            $replacement = null !== $resolved
                ? self::expand($resolved, $resolve, $depth + 1)
                : (null === $comma ? null : self::expand(trim(substr($arguments, $comma + 1)), $resolve, $depth + 1));
            if ( null === $replacement ) {
                return null;
            }
            $result .= substr($value, $offset, $start - $offset) . $replacement;
            if ( strlen($result) > self::MAX_EXPANDED_BYTES ) {
                return null;
            }
            $offset = $close + 1;
        }
        $result .= substr($value, $offset);

        return strlen($result) > self::MAX_EXPANDED_BYTES ? null : trim($result);
    }

    private static function matchingParenthesis(string $value, int $open): ?int
    {
        $depth = 0;
        for ( $index = $open, $length = strlen($value); $index < $length; ++$index ) {
            if ( '(' === $value[$index] ) {
                ++$depth;
            } elseif ( ')' === $value[$index] && 0 === --$depth ) {
                return $index;
            }
        }
        return null;
    }

    private static function topLevelComma(string $arguments): ?int
    {
        $depth = 0;
        for ( $index = 0, $length = strlen($arguments); $index < $length; ++$index ) {
            $character = $arguments[$index];
            if ( '(' === $character ) {
                ++$depth;
            } elseif ( ')' === $character ) {
                --$depth;
            } elseif ( ',' === $character && 0 === $depth ) {
                return $index;
            }
        }
        return null;
    }
}
