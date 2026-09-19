<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style;

use Automattic\BlocksEngine\PhpTransformer\Css\CssIdent;
use Automattic\BlocksEngine\PhpTransformer\Css\CssStylesheetTransformer;

/**
 * Lifts color-scheme rest-state selector gates onto `@media (prefers-color-scheme)`.
 *
 * A source that adapts to the user color scheme often compiles that variant as
 * a selector gate (`:where([data-mode=dark],[data-mode=dark] *)`, `:where(.dark,.dark *)`)
 * rather than a media query, then sets the matching document state from script.
 * WordPress does not run that script, so the declarations never apply. The gate
 * is the same color-scheme rest-state the CSS media feature names, so the
 * variant is projected as that media query and the remaining selector is left
 * to match the element.
 */
final class ColorSchemeVariant
{
    /**
     * @return array{prelude: string, scheme: ?string}
     */
    public static function liftPrelude(string $prelude): array
    {
        $selectors = CssStylesheetTransformer::splitSelectorList($prelude);
        if ( null === $selectors ) {
            return self::liftSelector($prelude);
        }

        $scheme = null;
        $parts = array();
        foreach ( $selectors as $selector ) {
            $lifted = self::liftSelector($selector);
            if ( null === $lifted['scheme'] ) {
                if ( null !== $scheme ) {
                    return array( 'prelude' => $prelude, 'scheme' => null );
                }
                $parts[] = $lifted['prelude'];
                continue;
            }
            if ( null !== $scheme && $scheme !== $lifted['scheme'] ) {
                return array( 'prelude' => $prelude, 'scheme' => null );
            }
            $scheme = $lifted['scheme'];
            $parts[] = $lifted['prelude'];
        }

        return array( 'prelude' => implode(',', $parts), 'scheme' => $scheme );
    }

    /**
     * @return array{prelude: string, scheme: ?string}
     */
    public static function liftSelector(string $selector): array
    {
        if ( 1 !== preg_match('/:(?:where|is)\(\s*(' . self::gatePattern() . ')\s*,\s*\1\s+\*\s*\)\s*$/i', $selector, $match) ) {
            return array( 'prelude' => $selector, 'scheme' => null );
        }

        $scheme = (string) ( $match[2] ?? '' );
        if ( '' === $scheme ) {
            $scheme = (string) ( $match[3] ?? '' );
        }

        return array(
            'prelude' => substr($selector, 0, -strlen($match[0])),
            'scheme' => strtolower($scheme),
        );
    }

    /** @param list<string> $ancestors */
    public static function wrap(string $css, ?string $scheme, array $ancestors = array()): string
    {
        if ( '' === $css || null === $scheme || self::ancestorsDeclareScheme($ancestors, $scheme) ) {
            return $css;
        }

        return '@media (prefers-color-scheme: ' . $scheme . '){' . $css . '}';
    }

    /** @param list<string> $ancestors */
    public static function ancestorsDeclareScheme(array $ancestors, string $scheme): bool
    {
        $scheme = preg_quote($scheme, '/');
        foreach ( $ancestors as $ancestor ) {
            if ( 1 === preg_match('/^@media\b[^\{]*prefers-color-scheme\s*:\s*' . $scheme . '\b/i', trim((string) $ancestor) ) ) {
                return true;
            }
        }

        return false;
    }

    public static function cssEscapeIdent(string $ident): string
    {
        return CssIdent::escape($ident);
    }

    public static function cssContainsClassSelector(string $css, string $className): bool
    {
        if ( '' === $className ) {
            return false;
        }

        return 1 === preg_match('/' . CssIdent::classSelectorRegex($className) . '(?:\b|(?=[.#:\[]))/', $css);
    }

    private static function gatePattern(): string
    {
        return '(?:\[[^\]=]+=\s*[\'"]?(dark|light)[\'"]?\s*\]|\.(dark|light)(?![A-Za-z0-9_-]|\\\\))';
    }
}
