<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\Css;

/** Compacts declaration framing and literal colors without changing selectors. */
final class CssDeclarationFormattingCompactor
{
    public static function stylesheet(string $css, bool $compactColors = false): string
    {
        return (new CssStylesheetTransformer())->transformStyleRules($css, static function (string $prelude, string $body) use ($compactColors): string {
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
                    $output .= self::declaration(substr($body, $start, $offset - $start), $compactColors) . ';';
                    $start = $offset + 1;
                }
                $next = CssSyntaxScanner::consume($body, $offset, $state);
                if ( null === $next ) return $prelude . '{' . $body . '}';
                $offset = $next;
            }
            $tail = substr($body, $start);
            // The last declaration needs no separator before the closing brace.
            // Only remove a separator observed outside all lexical groups.
            $output = '' === trim($tail) ? rtrim($output, ';') : $output . self::declaration($tail, $compactColors);
            return $prelude . '{' . $output . '}';
        });
    }

    private static function declaration(string $declaration, bool $compactColors): string
    {
        // Custom properties are arbitrary token streams, including whitespace.
        if ( ! preg_match('/^\s*(-?[a-zA-Z][a-zA-Z0-9-]*)\s*:\s*(.*?)\s*$/s', $declaration, $match) ) {
            return $declaration;
        }
        $value = $match[2];
        // Only complete literal color values: hashes in URLs, strings, custom
        // properties and identifiers are not colors. Selector/style guards keep
        // their original spelling, while the declared color remains identical.
        if ( $compactColors && preg_match('/^(?:color|background-color|border(?:-(?:top|right|bottom|left))?-color|outline-color|text-decoration-color|fill|stroke)$/i', $match[1])
            && preg_match('/^#([0-9a-f])\1([0-9a-f])\2([0-9a-f])\3((?:[0-9a-f]){2})?(\s*!important)?$/i', $value, $color) ) {
            $alpha = $color[4] ?? '';
            if ( '' === $alpha || strtolower($alpha[0]) === strtolower($alpha[1]) ) {
                $value = '#' . $color[1] . $color[2] . $color[3] . ('' === $alpha ? '' : $alpha[0]) . ($color[5] ?? '');
            }
        }
        return $match[1] . ':' . $value;
    }
}
