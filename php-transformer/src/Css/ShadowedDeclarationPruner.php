<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\Css;

/**
 * Drops declarations a later declaration of the same property in the same
 * style rule always overrides.
 *
 * Source stylesheets restate a property inside one block (a framework's
 * default `font-size:48px` before the theme's own value), and preset
 * binding turns the later value into `var(--wp--preset--…)`. The earlier
 * declaration still ships on every route, for every arm the rule was
 * projected onto, yet it never decides a computed value.
 *
 * An earlier declaration only matters when a browser rejects the later one
 * at parse time (the `display:-webkit-box;display:flex` fallback idiom). So
 * an earlier declaration is dropped only when the later one is certain to be
 * accepted wherever the earlier one is, and wins the cascade within the block:
 * - it is a custom property, which accepts any value;
 * - its value references `var()`, which defers validation to computed-value
 *   time (an invalid substitution yields `unset`, never the earlier value);
 * - or its value is the earlier value itself.
 * Importance decides first: a later normal declaration never shadows an
 * earlier `!important` one. Blocks with comments, nested rules, or names
 * that are not plain properties (IE `*zoom` hacks) are left as written.
 */
final class ShadowedDeclarationPruner
{
    private const IMPORTANT = '/!\s*important\s*$/i';

    public static function prune(string $css): string
    {
        if ( ! str_contains($css, ';') ) {
            return $css;
        }
        return (new CssStylesheetTransformer())->transformStyleRules($css, static fn (string $prelude, string $body): string => $prelude . '{' . self::withoutShadowedDeclarations($body) . '}');
    }

    /** The declaration block without declarations a later one always overrides. */
    public static function withoutShadowedDeclarations(string $body): string
    {
        if ( ! str_contains($body, ';') || str_contains($body, '{') || str_contains($body, '/*') ) {
            return $body;
        }
        $declarations = CssValueSplitter::splitTopLevel($body, array( ';' ));
        $parsed = array();
        foreach ( $declarations as $index => $declaration ) {
            if ( ! preg_match('/^(--[^\s:]+|-?[a-zA-Z][a-zA-Z0-9-]*)\s*:(.*)$/s', $declaration, $match) ) {
                $parsed[ $index ] = null;
                continue;
            }
            $value = trim($match[2]);
            $important = 1 === preg_match(self::IMPORTANT, $value);
            $parsed[ $index ] = array(
                'property'  => str_starts_with($match[1], '--') ? $match[1] : strtolower($match[1]),
                'value'     => trim((string) preg_replace(self::IMPORTANT, '', $value)),
                'important' => $important,
            );
        }
        $kept = array();
        $dropped = false;
        foreach ( $declarations as $index => $declaration ) {
            $earlier = $parsed[ $index ];
            if ( null !== $earlier && self::overriddenLater($earlier, $parsed, $index) ) {
                $dropped = true;
                continue;
            }
            $kept[] = $declaration;
        }
        return $dropped ? implode(';', $kept) : $body;
    }

    /**
     * @param array{property: string, value: string, important: bool} $earlier
     * @param array<int, array{property: string, value: string, important: bool}|null> $parsed
     */
    private static function overriddenLater(array $earlier, array $parsed, int $index): bool
    {
        for ( $later = $index + 1, $count = count($parsed); $later < $count; ++$later ) {
            $candidate = $parsed[ $later ];
            if ( null === $candidate || $candidate['property'] !== $earlier['property'] ) {
                continue;
            }
            if ( $earlier['important'] && ! $candidate['important'] ) {
                continue;
            }
            if ( str_starts_with($candidate['property'], '--')
                || 1 === preg_match('/(?<![\w-])var\(/i', (string) preg_replace('/"(?:[^"\\\\]|\\\\.)*"|\'(?:[^\'\\\\]|\\\\.)*\'/s', '""', $candidate['value']))
                || $candidate['value'] === $earlier['value'] ) {
                return true;
            }
        }
        return false;
    }
}
