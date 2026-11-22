<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\Css;

/** Removes formatting at declaration boundaries, never inside value tokens. */
final class CssDeclarationBoundaryCompactor
{
    public static function stylesheet(string $css): string
    {
        return (new CssStylesheetTransformer())->transformStyleRules($css, static function (string $selector, string $body): string {
            // Nested syntax and comments (including licenses) are opaque here.
            if (str_contains($body, '{') || str_contains($body, '/*') || str_contains($body, '\\')) return $selector . '{' . $body . '}';
            $parts = array();
            $start = 0;
            $state = CssSyntaxScanner::state();
            for ($offset = 0, $length = strlen($body); $offset < $length;) {
                if (';' === $body[$offset] && '' === $state['quote'] && !$state['comment'] && 0 === $state['parens']) {
                    $parts[] = substr($body, $start, $offset - $start);
                    $start = $offset + 1;
                }
                $offset = CssSyntaxScanner::consume($body, $offset, $state) ?? $offset + 1;
            }
            $parts[] = substr($body, $start);
            foreach ($parts as &$part) {
                // Custom properties expose their token streams to var() and
                // JavaScript: even a whitespace-only value must remain intact.
                if (preg_match('/^\s*(-?[a-zA-Z][a-zA-Z0-9-]*)\s*:\s*(.*?)\s*$/sD', $part, $match)) {
                    $value = (string) preg_replace('/\s*!\s*important$/iD', '!important', $match[2]);
                    $part = $match[1] . ':' . $value;
                }
            }
            unset($part);
            return $selector . '{' . implode(';', $parts) . '}';
        });
    }
}
