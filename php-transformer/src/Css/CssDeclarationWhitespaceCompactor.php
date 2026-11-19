<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\Css;

/** Remove declaration delimiters' whitespace, never whitespace inside values. */
final class CssDeclarationWhitespaceCompactor
{
    public static function stylesheet(string $css): string
    {
        return (new CssStylesheetTransformer())->transformStyleRules($css, static function (string $prelude, string $body): string {
            // Nested blocks, comments and escapes need their original token boundaries.
            if (str_contains($body, '{') || str_contains($body, '/*') || str_contains($body, '\\') || preg_match('/(?:^|;)\s*--/', $body)) return $prelude . '{' . $body . '}';
            $declarations = CssValueSplitter::splitTopLevel($body, array(';'));
            foreach ($declarations as &$declaration) {
                // Custom properties are token streams, including leading/trailing whitespace.
                if (!preg_match('/^\s*(-?[a-zA-Z][a-zA-Z0-9-]*)\s*:\s*(.*?)\s*$/sD', $declaration, $match)) continue;
                $declaration = $match[1] . ':' . $match[2];
            }
            unset($declaration);
            return $prelude . '{' . implode(';', $declarations) . '}';
        });
    }
}
