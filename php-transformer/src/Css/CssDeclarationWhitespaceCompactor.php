<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\Css;

/** Removes declaration formatting, never whitespace inside value tokens. */
final class CssDeclarationWhitespaceCompactor
{
    public static function stylesheet(string $css): string
    {
        return (new CssStylesheetTransformer())->transformStyleRules($css, static function (string $prelude, string $body): string {
            // Nested CSS, comments and escapes require their original spelling.
            if (str_contains($body, '{') || str_contains($body, '/*') || str_contains($body, '\\')) return $prelude . '{' . $body . '}';
            $parts = CssValueSplitter::splitTopLevelVerbatim($body, array(';'));
            foreach ($parts as &$part) {
                // Custom properties are token streams: even an all-whitespace
                // value is meaningful when substituted into another property.
                if (preg_match('/^[ \t\r\n\f]*(-?[a-zA-Z][a-zA-Z0-9-]*)[ \t\r\n\f]*:[ \t\r\n\f]*(.*)$/s', $part, $match)) {
                    $value = rtrim($match[2], " \t\r\n\f");
                    // Importance is a declaration flag, not part of its value.
                    // A quoted string cannot end at this unquoted identifier.
                    $value = preg_replace('/[ \t\r\n\f]*![ \t\r\n\f]*important$/i', '!important', $value) ?? $value;
                    $part = $match[1] . ':' . $value;
                }
            }
            unset($part);
            return $prelude . '{' . implode(';', $parts) . '}';
        });
    }
}
