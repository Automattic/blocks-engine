<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\Css;

/** Shortens fractional number tokens without changing their value or unit. */
final class CssNumberCompactor
{
    public static function stylesheet(string $css): string
    {
        if ( ! str_contains($css, '0.') ) {
            return $css;
        }
        return (new CssStylesheetTransformer())->transformStyleRules($css, static function (string $prelude, string $body): string {
            // Nested blocks and custom-property token streams are not values
            // we can normalize. Leave strings, URLs and escaped tokens alone.
            if ( str_contains($body, '{') || str_contains($body, '/*') ) {
                return $prelude . '{' . $body . '}';
            }
            $declarations = array();
            foreach ( CssValueSplitter::splitTopLevel($body, array( ';' )) as $declaration ) {
                if ( preg_match('/^(-?[a-zA-Z][a-zA-Z0-9-]*\s*:)(.*)$/s', $declaration, $match)
                    && ! preg_match('/["\'\\\\]|url\(/i', $match[2]) ) {
                    // A decimal inside an identifier or hash is not a number.
                    // Including the sign in the match preserves signed tokens.
                    $value = preg_replace('/(?<![\w\x80-\xff.#%+\\\\-])([+-]?)0\.(?=\d)/', '$1.', $match[2]) ?? $match[2];
                    $declaration = $match[1] . $value;
                }
                $declarations[] = $declaration;
            }
            return $prelude . '{' . implode(';', $declarations) . '}';
        });
    }
}
