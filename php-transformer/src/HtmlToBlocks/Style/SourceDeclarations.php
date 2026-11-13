<?php

declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style;

use Automattic\BlocksEngine\PhpTransformer\Css\CssFontShorthand;
use Automattic\BlocksEngine\PhpTransformer\Css\CssPropertyName;
use Automattic\BlocksEngine\PhpTransformer\Css\CssRuleAnalyzer;
use Automattic\BlocksEngine\PhpTransformer\Css\CssRuleTreeCache;
use Automattic\BlocksEngine\PhpTransformer\Css\CssValueSplitter;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Support\SourceDom;
use DOMElement;

/**
 * Source declaration lists read as property maps: parsed with the engine's
 * URL and expression sanitization, optionally expanded to physical box sides
 * and font longhands, and merged with shorthand reset semantics. Pure
 * functions of their input, so every reader shares one parse.
 */
final class SourceDeclarations
{
    /** Include the intermediate text-wrap shorthand as well as its longhands. */
    public const WHITE_SPACE_SHORTHAND_LONGHANDS = array(
        'white-space' => array('text-wrap', 'text-wrap-mode', 'text-wrap-style', 'white-space-collapse'),
        'text-wrap' => array('text-wrap-mode', 'text-wrap-style'),
    );

    /**
     * @return array<string, string>
     */
    public static function parse(string $style): array
    {
        $declarations = array();
        foreach ( self::verbatim($style, true) as $name => $value ) {
            foreach ( self::physicalBox($name, $value) as $boxName => $boxValue ) {
                // Importance precedes source order even within one declaration
                // list. Reducing to a property map must retain that winner.
                if (isset($declarations[$boxName]) && CssValueInspector::isImportant($declarations[$boxName]) && ! CssValueInspector::isImportant($boxValue)) {
                    continue;
                }
                // Keep the surviving declaration at its final authored position.
                // Border shorthands and longhands reset one another in source
                // order, so overwriting a prior key in place is not sufficient.
                unset($declarations[$boxName]);
                $declarations[$boxName] = $boxValue;
            }
        }

        return $declarations;
    }

    /**
     * The element's own `style` attribute as declarations, read the same way
     * as {@see self::parse()}.
     *
     * @return array<string, string>
     */
    public static function inline(DOMElement $element): array
    {
        return self::parse(SourceDom::attr($element, 'style'));
    }

    /**
     * Parse a declaration list without logical-to-physical box expansion.
     *
     * Author-stylesheet projection re-emits authored declaration text, so it
     * must keep the authored property names (`margin-inline`, …) byte-for-byte;
     * only cascade resolution and classification read the expanded physical
     * sides. The font expansion option is likewise reserved for cascade readers;
     * it runs before map reduction to preserve shorthand/longhand source order.
     *
     * @return array<string, string>
     */
    public static function verbatim(string $style, bool $expandFont = false): array
    {
        return CssRuleTreeCache::memo(
            $expandFont ? 'declarations:font' : 'declarations',
            $style,
            static fn (): array => self::parseVerbatim($style, $expandFont)
        );
    }

    private static function parseVerbatim(string $style, bool $expandFont): array
    {
        $declarations = array();
        foreach ( CssRuleAnalyzer::declarationList($style) as [ 'name' => $name, 'value' => $value ] ) {
            // A browser drops `0: none`; its name would also be an int key.
            if ( CssPropertyName::startsLikeNumber($name) ) {
                continue;
            }
            $value = preg_replace('/\s+/', ' ', $value) ?? $value;
            // A consumed custom property can supply the URL to an authored
            // background rule. Keep it for the same sanitized carrier path.
            $allowsImageUrl = (str_starts_with($name, '--') || in_array($name, array( 'background', 'background-image', 'list-style', 'list-style-image' ), true)) && ! preg_match('/(?:expression\s*\(|javascript\s*:)/i', $value);
            // A same-document fragment (`clip-path: url(#wave)`) requests nothing:
            // it names a definition the converted document keeps.
            $withoutFragments = preg_replace('/\burl\(\s*(["\']?)#[A-Za-z_][\w.:-]*\1\s*\)/i', '', $value) ?? 'url(';
            if ( '' !== $name && '' !== $value && ( $allowsImageUrl || ! preg_match('/(?:expression\s*\(|javascript\s*:|url\s*\()/i', $withoutFragments) ) ) {
                // Expand before reducing to a map: even repeated shorthands must
                // compete with intervening longhands in authored source order.
                $entries = $expandFont && 'font' === $name ? CssFontShorthand::expand($value) : array();
                if (array() === $entries) $entries = array($name => $value);
                foreach ($entries as $property => $entryValue) {
                    if (isset($declarations[$property]) && CssValueInspector::isImportant($declarations[$property]) && ! CssValueInspector::isImportant($entryValue)) {
                        continue;
                    }
                    unset($declarations[$property]);
                    $declarations[$property] = $entryValue;
                }
            }
        }

        return $declarations;
    }

    /**
     * Expand a logical box property into its physical side longhands.
     *
     * A builder's box utilities are increasingly authored as logical properties
     * (`padding-block`, `padding-inline`), while the cascade and every
     * downstream box consumer (control-surface classification, spacing
     * projection) read physical sides. Expansion assumes the desktop reference
     * viewport's horizontal-tb, left-to-right writing mode — the same fixed
     * assumption the rest of style resolution makes.
     *
     * @return array<string, string> Physical declarations keyed by property.
     */
    private static function physicalBox(string $property, string $value): array
    {
        $axes = array(
            'padding-block' => array( 'padding-top', 'padding-bottom' ),
            'padding-inline' => array( 'padding-left', 'padding-right' ),
            'margin-block' => array( 'margin-top', 'margin-bottom' ),
            'margin-inline' => array( 'margin-left', 'margin-right' ),
        );
        if ( isset($axes[$property]) ) {
            $important = CssValueInspector::isImportant($value) ? ' !important' : '';
            $plain = trim(preg_replace('/\s*!\s*important\s*$/i', '', $value) ?? $value);
            $parts = CssValueSplitter::splitTopLevelWhitespace($plain);
            if ( count($parts) < 1 || count($parts) > 2 ) {
                return array();
            }

            return array(
                $axes[$property][0] => $parts[0] . $important,
                $axes[$property][1] => ( $parts[1] ?? $parts[0] ) . $important,
            );
        }

        $sides = array(
            'padding-block-start' => 'padding-top',
            'padding-block-end' => 'padding-bottom',
            'padding-inline-start' => 'padding-left',
            'padding-inline-end' => 'padding-right',
            'margin-block-start' => 'margin-top',
            'margin-block-end' => 'margin-bottom',
            'margin-inline-start' => 'margin-left',
            'margin-inline-end' => 'margin-right',
        );
        if ( isset($sides[$property]) ) {
            return array( $sides[$property] => $value );
        }

        return array( $property => $value );
    }

    /**
     * Preserve declaration order while applying shorthand reset semantics.
     *
     * @param array<string, string> $base
     * @param array<string, string> $incoming
     * @return array<string, string>
     */
    public static function merge(array $base, array $incoming): array
    {
        foreach ( $incoming as $property => $value ) {
            if ( 'all' === $property && self::isAllReset($value) ) {
                // `all:unset|initial|revert` resets every longhand except
                // custom properties, direction, and unicode-bidi; earlier
                // declarations cannot survive the reset regardless of origin.
                // The keyword itself never rides forward: honoring it means
                // dropping what it reset, not serializing `all`.
                foreach ( array_keys($base) as $existing ) {
                    if ( ! str_starts_with($existing, '--') && ! in_array($existing, array( 'direction', 'unicode-bidi' ), true) ) {
                        unset($base[$existing]);
                    }
                }
                continue;
            }
            foreach ( self::WHITE_SPACE_SHORTHAND_LONGHANDS[$property] ?? array() as $longhand ) {
                unset($base[$longhand]);
            }
            if ( 'background' === $property ) {
                foreach ( array_keys($base) as $existing ) {
                    if ( 'background' === $existing || str_starts_with($existing, 'background-') ) {
                        unset($base[$existing]);
                    }
                }
            }
            unset($base[$property]);
            $base[$property] = $value;
        }

        return $base;
    }

    public static function isAllReset(string $value): bool
    {
        $value = strtolower(trim(preg_replace('/\s*!\s*important\s*$/i', '', $value) ?? $value));

        return in_array($value, array( 'unset', 'initial', 'revert', 'revert-layer' ), true);
    }
}
