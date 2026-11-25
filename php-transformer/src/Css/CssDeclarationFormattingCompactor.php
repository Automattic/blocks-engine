<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\Css;

/** Removes declaration framing whitespace, never whitespace within value tokens. */
final class CssDeclarationFormattingCompactor
{
    public static function stylesheet(string $css): string
    {
        return (new CssStylesheetTransformer())->transformStyleRules($css, static function (string $prelude, string $body): string {
            // Comments, escapes and nested rules can affect token boundaries.
            if ( str_contains($body, '{') || str_contains($body, '/*') || str_contains($body, '\\') ) {
                return $prelude . '{' . $body . '}';
            }
            $state = CssSyntaxScanner::state();
            $start = 0;
            $output = '';
            $length = strlen($body);
            for ( $offset = 0; $offset < $length; ) {
                $end = CssSyntaxScanner::inertEnd($body, $offset, $state, ';');
                if ( $end > $offset ) {
                    $offset = $end;
                    continue;
                }
                if ( ';' === $body[$offset] && '' === $state['quote'] && ! $state['url'] && 0 === $state['parens'] && 0 === $state['brackets'] ) {
                    $output .= self::declaration(substr($body, $start, $offset - $start)) . ';';
                    $start = $offset + 1;
                }
                $next = CssSyntaxScanner::consume($body, $offset, $state);
                if ( null === $next ) return $prelude . '{' . $body . '}';
                $offset = $next;
            }
            $tail = substr($body, $start);
            // The last declaration needs no separator before the closing brace.
            // Only remove a separator observed outside all lexical groups.
            $output = '' === trim($tail) ? rtrim($output, ';') : $output . self::declaration($tail);
            return $prelude . '{' . $output . '}';
        });
    }

    private static function declaration(string $declaration): string
    {
        // Custom properties are arbitrary token streams, including whitespace.
        if ( ! preg_match('/^\s*(-?[a-zA-Z][a-zA-Z0-9-]*)\s*:\s*(.*?)\s*$/s', $declaration, $match) ) {
            return $declaration;
        }
        return $match[1] . ':' . $match[2];
    }
}
