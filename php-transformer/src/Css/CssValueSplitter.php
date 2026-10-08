<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\Css;

/**
 * Syntax-aware splitting for CSS declaration lists and values.
 *
 * Naive `explode(';', ...)`, `explode(',', ...)`, and `preg_split('/\s+/', ...)`
 * over CSS values break apart the inside of functional notation —
 * `rgba(251, 247, 241, .95)`, `clamp(3.5rem, 8vw, 6.5rem)`, `var(--x, 0)`, and
 * `linear-gradient(90deg, red, blue)` all carry commas and spaces that are NOT
 * top-level delimiters. Splitting them mid-function yields truncated, invalid
 * tokens (`rgba(251,`) that no longer round-trip through the block style object,
 * which is what produces "unexpected or invalid content" and mangled spacing.
 *
 * Every method here only treats a delimiter as a separator when it appears at
 * paren depth 0 and outside quotes, escapes, and comments, so CSS tokens stay
 * whole. A comment is one lexical unit whatever it holds: splitting
 * `/*border: 5px solid red;*\/` at its `;` turned the first half into the
 * property `/*border` and threw the `*\/` away, so every emitted sheet that
 * re-serialized the rule commented out everything up to the next `*\/`.
 */
final class CssValueSplitter
{
    /**
     * Split on any of the given single-character delimiters, but only when the
     * delimiter occurs outside of `(...)`, quotes, and escapes.
     * Empty/whitespace-only segments are dropped and remaining segments are
     * trimmed.
     *
     * @param array<int, string> $delimiters
     * @return array<int, string>
     */
    public static function splitTopLevel(string $input, array $delimiters): array
    {
        $parts = self::split($input, $delimiters, false);

        $trimmed = array();
        foreach ( $parts as $part ) {
            $part = trim($part);
            if ( '' !== $part ) {
                $trimmed[] = $part;
            }
        }

        return $trimmed;
    }

    /**
     * Split on top-level whitespace runs, keeping CSS tokens whole. This is the
     * syntax-aware replacement for `preg_split('/\s+/', ...)` over CSS
     * shorthand values such as `padding: clamp(3.5rem, 8vw, 6.5rem) 0` or
     * `border: 1px solid rgba(0, 0, 0, .1)`.
     *
     * @return array<int, string>
     */
    public static function splitTopLevelWhitespace(string $input): array
    {
        return self::split($input, array(), true);
    }

    /**
     * Lexing (quotes, escapes, comments, unquoted `url(` tokens, parens) is
     * {@see CssSyntaxScanner::consume()}; this only decides where a top-level
     * delimiter falls. A comment is discarded and reads as one space, so
     * `a/**\/b` stays two tokens. Square brackets do not shield delimiters
     * here, and an unmatched closer is kept as a literal byte.
     *
     * @param array<int, string> $delimiters
     * @return array<int, string>
     */
    private static function split(string $input, array $delimiters, bool $splitWhitespace): array
    {
        $parts  = array();
        $buffer = '';
        $state  = CssSyntaxScanner::state();
        $length = strlen($input);

        for ( $offset = 0; $offset < $length; ) {
            $wasComment = $state['comment'];
            $topLevel   = self::outsideGroups($state);
            $next       = CssSyntaxScanner::consume($input, $offset, $state) ?? $offset + 1;
            $piece      = $state['comment'] && ! $wasComment ? ' ' : substr($input, $offset, $next - $offset);
            $offset     = $next;
            if ( $wasComment ) {
                continue;
            }

            $isDelimiter = 1 === strlen($piece) && ( $splitWhitespace ? '' === trim($piece) : in_array($piece, $delimiters, true) );
            if ( $topLevel && $isDelimiter ) {
                if ( ! $splitWhitespace || '' !== $buffer ) {
                    $parts[] = $buffer;
                }
                $buffer = '';
                continue;
            }

            $buffer .= $piece;
        }

        if ( ! $splitWhitespace || '' !== $buffer ) {
            $parts[] = $buffer;
        }

        return $parts;
    }

    /**
     * Whether every `(` in the value has a matching `)` and no `)` closes more
     * than has been opened. A value that contains `(` but is unbalanced is a
     * truncated/malformed functional value (e.g. `rgba(251,`) and must not be
     * stored as a resolved CSS value.
     */
    public static function hasBalancedParens(string $value): bool
    {
        $state  = CssSyntaxScanner::state();
        $length = strlen($value);

        for ( $offset = 0; $offset < $length; ) {
            $next = CssSyntaxScanner::consume($value, $offset, $state);
            if ( null === $next ) {
                if ( ')' === $value[ $offset ] ) {
                    return false;
                }
                $next = $offset + 1;
            }
            $offset = $next;
        }

        return 0 === $state['parens'];
    }

    /** @param array{quote: string, comment: bool, url: bool, parens: int, brackets: int} $state */
    private static function outsideGroups(array $state): bool
    {
        return '' === $state['quote'] && ! $state['comment'] && 0 === $state['parens'];
    }
}
