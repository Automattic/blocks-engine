<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\Css;

/** Shorten literal colours without touching selectors or custom-property tokens. */
final class CssColorValueCompactor
{
    public static function stylesheet(string $css): string
    {
        return (new CssStylesheetTransformer())->transformStyleRules($css, static function (string $prelude, string $body): string {
            $declarations = CssValueSplitter::splitTopLevel($body, array(';'));
            foreach ($declarations as &$declaration) {
                // Only whole literal values in colour-valued properties: never
                // rewrite strings, URLs, fallbacks or script-readable variables.
                if (1 !== preg_match('/^(\s*(?:color|background-color|(?:border(?:-(?:top|right|bottom|left))?|outline|text-decoration|column-rule)-color)\s*:\s*)(#[a-f0-9]{6}|rgb\(\s*\d+\s*,\s*\d+\s*,\s*\d+\s*\))(\s*(?:!important)?\s*)$/iD', $declaration, $match)) continue;
                $color = $match[2];
                if ('#' !== $color[0]) {
                    preg_match_all('/\d+/', $color, $channels);
                    if (array_filter($channels[0], static fn (string $channel): bool => (int) $channel > 255)) continue;
                    $color = sprintf('#%02x%02x%02x', ...array_map('intval', $channels[0]));
                }
                if (1 === preg_match('/^#(.)\1(.)\2(.)\3$/iD', $color, $pairs)) $color = '#' . $pairs[1] . $pairs[2] . $pairs[3];
                if (strlen($color) < strlen($match[2])) $declaration = $match[1] . $color . $match[3];
            }
            unset($declaration);
            return $prelude . '{' . implode(';', $declarations) . '}';
        });
    }
}
