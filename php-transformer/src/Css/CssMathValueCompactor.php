<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\Css;

/**
 * Writes each math function (`calc()`, `min()`, `max()`, `clamp()`) in a
 * declaration value at its smallest equivalent spelling.
 *
 * Captured computed values restate a fluid length as the source runtime
 * resolved it, for example
 * `max(0.5px, 0.0109375 * (clamp(0px, 100 * 1vw * 1, min(9999px, 1920px)) - 0px))`,
 * and projection carries that spelling to every carrier and breakpoint the
 * value reaches. Only rewrites that leave the computed value unchanged are
 * made:
 * - whitespace inside the function is dropped beside `,`, `*`, `/`, `(` and
 *   `)`; the whitespace `+` and `-` require is kept, as one space;
 * - multiplying by `1` is dropped, and `N * 1unit` is written `Nunit` when
 *   `N` is not a divisor's operand;
 * - a trailing `- 0px` / `+ 0px` term is dropped;
 * - `min()` / `max()` over plain pixel lengths is written as the winning
 *   length;
 * - a parenthesized group holding a single number or math function is
 *   unwrapped.
 * Values holding strings, comments, escapes or `url()`, and math functions
 * holding `var()` or any other function or keyword, are left byte for byte,
 * as are custom properties, whose token streams scripts may read.
 */
final class CssMathValueCompactor
{
    private const MATH_OPEN = '/(?<![\w-])(?:-webkit-|-moz-)?(?:calc|min|max|clamp)\(/i';
    private const NUMBER = '-?(?:\d+\.?\d*|\.\d+)';
    private const TOKENS = '/^(?:\s+|(?:\d+\.?\d*|\.\d+)(?:e[+-]?\d+)?(?:[a-z]+|%)?|(?:-webkit-|-moz-)?(?:calc|min|max|clamp)\(|[()+*\/,-])*$/i';

    /** The stylesheet with every declaration's math functions compacted. */
    public static function stylesheet(string $css): string
    {
        if ( ! preg_match(self::MATH_OPEN, $css) ) {
            return $css;
        }
        return (new CssStylesheetTransformer())->transformStyleRules($css, static fn (string $prelude, string $body): string => $prelude . '{' . self::declarations($body) . '}');
    }

    /** The declaration block with every non-custom declaration's value compacted. */
    public static function declarations(string $body): string
    {
        if ( str_contains($body, '{') || str_contains($body, '/*') || ! preg_match(self::MATH_OPEN, $body) ) {
            return $body;
        }
        $changed = false;
        $declarations = array();
        foreach ( CssValueSplitter::splitTopLevel($body, array( ';' )) as $declaration ) {
            if ( preg_match('/^(-?[a-zA-Z][a-zA-Z0-9-]*)(\s*:)(.*)$/s', $declaration, $match) ) {
                $value = self::value($match[3]);
                if ( $value !== $match[3] ) {
                    $changed = true;
                    $declaration = $match[1] . $match[2] . $value;
                }
            }
            $declarations[] = $declaration;
        }
        return $changed ? implode(';', $declarations) : $body;
    }

    /** The value with each top-level math function at its smallest equivalent spelling. */
    public static function value(string $value): string
    {
        if ( preg_match('/["\'\\\\]|\/\*|url\(/i', $value) || ! preg_match(self::MATH_OPEN, $value) ) {
            return $value;
        }
        $output = '';
        $offset = 0;
        while ( preg_match(self::MATH_OPEN, $value, $match, PREG_OFFSET_CAPTURE, $offset) ) {
            $start = $match[0][1];
            $open = $start + strlen($match[0][0]) - 1;
            $close = CssSyntaxScanner::matchingClose($value, $open);
            if ( null === $close ) {
                return $value;
            }
            $output .= substr($value, $offset, $open + 1 - $offset) . self::expression(substr($value, $open + 1, $close - $open - 1)) . ')';
            $offset = $close + 1;
        }
        return $output . substr($value, $offset);
    }

    private static function expression(string $inner): string
    {
        if ( ! preg_match(self::TOKENS, $inner) || ! CssValueSplitter::hasBalancedParens($inner) ) {
            return $inner;
        }
        $number = self::NUMBER;
        $expression = preg_replace(array( '/\s*([,*\/])\s*/', '/\(\s+/', '/\s+\)/', '/\s+/' ), array( '$1', '(', ')', ' ' ), trim($inner)) ?? $inner;
        // A term ends at `,` `)` `*` `/` or a spaced `+` / `-`.
        $termEnd = '(?=[,)*\/]| [+-] |$)';
        do {
            $before = $expression;
            $expression = preg_replace(
                array(
                    // x * 1 is x.
                    '/\*1(?![\d.a-z%])/i',
                    // N * 1unit is Nunit, unless N divides something.
                    '/(?<=^|[(,*]|[+-] )(' . $number . ')\*1([a-z]+|%)' . $termEnd . '/i',
                    // Adding or subtracting a zero length at the end of a sum.
                    '/ [+-] 0px(?=[,)]|$)/',
                    // A group holding one number or one math function.
                    '/(?<![\w-])\(((?:' . $number . ')(?:[a-z]+|%)?|(?:-webkit-|-moz-)?(?:calc|min|max|clamp)(\((?:[^()]|(?2))*\)))\)/i',
                ),
                array( '', '$1$2', '', '$1' ),
                $expression
            ) ?? $before;
            $expression = preg_replace_callback(
                '/(?<![\w-])(min|max)\((' . $number . 'px(?:,' . $number . 'px)+)\)/i',
                static function (array $match): string {
                    $lengths = explode(',', $match[2]);
                    $winner = $lengths[0];
                    foreach ( $lengths as $length ) {
                        $better = 'min' === strtolower($match[1]) ? (float) $length < (float) $winner : (float) $length > (float) $winner;
                        if ( $better ) $winner = $length;
                    }
                    return $winner;
                },
                $expression
            ) ?? $expression;
        } while ( $expression !== $before );
        return $expression;
    }
}
