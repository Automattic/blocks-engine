<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Patterns;

use Automattic\BlocksEngine\PhpTransformer\Css\CssValueSplitter;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\CssValueInspector;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\StyleAttributeMapper;

/**
 * Translates a button's resolved CSS declarations (from inline style plus the
 * `<style>`/linked CSS rules the transformer already matches to the element)
 * into native WordPress core/button block attributes.
 *
 * This keeps imported buttons rendering with their source colors and borders
 * instead of falling back to the theme's default (grey) button styling, because
 * the styling lives in canonical block attributes (style.color.*, style.border.*)
 * rather than a non-canonical inline style string that WordPress drops on
 * block recovery.
 *
 * The generic CSS -> canonical-attribute parsing (color / typography / spacing /
 * border, including border-shorthand splitting and CSS-color validation) is
 * delegated to the shared {@see StyleAttributeMapper} (#261) so buttons reuse the
 * exact mechanic every other block uses. This class keeps ONLY the button-specific
 * presentation policy layered on top of that shared mechanic, keyed off the
 * resolved declarations:
 *  - Filled buttons get style.color.background/gradient + style.color.text (+ border radius).
 *  - Outline/ghost buttons (transparent/absent background) get style.border.* +
 *    style.color.text without inventing a fill.
 *  - Buttons carry padding but not margin (inter-button spacing rides on the
 *    parent core/buttons block gap), plus source shadow and a curated
 *    typography subset.
 * A button whose resolved CSS carries no paintable colors/border stays default.
 */
final class ButtonStyleResolver
{
    /**
     * Typography supports projected onto buttons, in canonical emission order.
     *
     * fontFamily belongs here: core/button registers a `fontFamily` attribute,
     * which core injects only when the typography fontFamily support is enabled.
     * A raw authored value is not a preset slug, so it rides in
     * style.typography.fontFamily and serializes inline on the link. Dropping it
     * left the typeface to theme.json's styles.elements.button, because the
     * authored class is consumed into block attributes and its rewritten rule no
     * longer wins the cascade.
     */
    private const BUTTON_TYPOGRAPHY = array( 'fontFamily', 'fontSize', 'fontWeight', 'letterSpacing', 'lineHeight', 'textTransform' );

    /**
     * Logical padding mapped to the physical sides the canonical spacing
     * attribute stores. Builders author the box as logical properties
     * (`padding-inline`, `padding-block`), and the cascade keeps those authored
     * names, so the shared mapper's physical-side reading never sees them.
     * Expansion assumes the desktop reference viewport's horizontal-tb,
     * left-to-right writing mode — the same fixed assumption
     * StyleResolver::physicalBoxDeclarations() makes.
     */
    private const LOGICAL_PADDING_AXES = array(
        'padding-block' => array( 'padding-top', 'padding-bottom' ),
        'padding-inline' => array( 'padding-left', 'padding-right' ),
    );

    private const LOGICAL_PADDING_SIDES = array(
        'padding-block-start' => 'padding-top',
        'padding-block-end' => 'padding-bottom',
        'padding-inline-start' => 'padding-left',
        'padding-inline-end' => 'padding-right',
    );

    private readonly StyleAttributeMapper $mapper;

    public function __construct(?StyleAttributeMapper $mapper = null)
    {
        $this->mapper = $mapper ?? new StyleAttributeMapper();
    }

    /**
     * Build native core/button style attributes from a resolved CSS string.
     *
     * @return array<string, mixed> Either an empty array (no native styling) or
     *                              an array with a `style` object suitable for the
     *                              core/button block attributes.
     */
    public function nativeAttributes(string $resolvedStyle): array
    {
        $declarations = $this->declarations($resolvedStyle);
        if ( array() === $declarations ) {
            return array();
        }

        $mapped = $this->mapper->map($declarations)['style'];
        $style  = array();

        // Emit canonical button paint so theme defaults cannot replace authored chrome.
        $color      = is_array($mapped['color'] ?? null) ? $mapped['color'] : array();
        $background = (string) ($color['background'] ?? '');
        $gradient   = (string) ($color['gradient'] ?? '');
        $text       = (string) ($color['text'] ?? '');
        if ( '' !== $background ) {
            $style['color']['background'] = $background;
        }
        if ( '' !== $gradient ) {
            $style['color']['gradient'] = $gradient;
        }
        if ( '' !== $text ) {
            $style['color']['text'] = $text;
        }

        $border = is_array($mapped['border'] ?? null) ? $mapped['border'] : array();
        if ( array() !== $border ) {
            $style['border'] = $border;
        }

        // Buttons carry padding but not margin.
        $padding = ( is_array($mapped['spacing'] ?? null) && is_array($mapped['spacing']['padding'] ?? null) )
            ? $mapped['spacing']['padding']
            : array();
        if ( array() !== $padding ) {
            foreach ( $padding as $side => $value ) {
                $padding[ $side ] = $this->gutenbergSpacingLength((string) $value, $declarations);
            }
            $style['spacing']['padding'] = $padding;
        }

        $shadow = $this->buttonShadow($declarations);
        if ( '' !== $shadow ) {
            $style['shadow'] = $shadow;
        }

        $typography = $this->buttonTypography(is_array($mapped['typography'] ?? null) ? $mapped['typography'] : array());
        if ( array() !== $typography ) {
            $style['typography'] = $typography;
        }

        if ( array() === $style ) {
            return array();
        }

        return array( 'style' => $style );
    }

    /**
     * Core/button supports shadow as a canonical `style.shadow` value. Keep this
     * button-specific instead of making every block consume `box-shadow`, because
     * class-owned card/section shadows should continue riding on preserved CSS.
     *
     * @param array<string, string> $declarations
     */
    private function buttonShadow(array $declarations): string
    {
        $shadow = trim((string) ($declarations['box-shadow'] ?? ''));
        if ( '' === $shadow || in_array(strtolower($shadow), array( 'none', 'initial', 'inherit', 'unset' ), true) ) {
            return '';
        }

        if ( preg_match('/(?:expression\s*\(|javascript\s*:|url\s*\()/i', $shadow) ) {
            return '';
        }

        return $shadow;
    }

    /**
     * Project the shared typography attributes onto the button-supported subset,
     * preserving the canonical emission order.
     *
     * @param array<string, string> $typography
     * @return array<string, string>
     */
    private function buttonTypography(array $typography): array
    {
        $selected = array();
        foreach ( self::BUTTON_TYPOGRAPHY as $key ) {
            $value = trim((string) ($typography[ $key ] ?? ''));
            if ( '' !== $value ) {
                $selected[ $key ] = $value;
            }
        }

        return $selected;
    }

    /**
     * Parse a resolved CSS string into a declaration map for the shared mapper.
     * Logical padding is expanded to physical sides in declaration order, so a
     * later physical winner still overwrites an expanded logical side.
     *
     * @return array<string, string>
     */
    private function declarations(string $style): array
    {
        $declarations = array();
        foreach ( explode(';', $style) as $declaration ) {
            if ( ! str_contains($declaration, ':') ) {
                continue;
            }
            [ $name, $value ] = array_map('trim', explode(':', $declaration, 2));
            $name = strtolower($name);
            if ( '' === $name || '' === $value ) {
                continue;
            }
            if ( 'background' === $name ) {
                unset($declarations['background-color']);
            }
            $value = preg_replace('/\s+/', ' ', $value) ?? $value;
            foreach ( $this->physicalPaddingDeclarations($name, $value) as $physicalName => $physicalValue ) {
                $declarations[ $physicalName ] = $physicalValue;
            }
        }

        return $declarations;
    }

    /**
     * Expand one declaration into the physical padding sides the spacing
     * attribute stores. A physical property passes through unchanged; an
     * axis shorthand (`padding-inline: 8px 24px`) expands to its two sides;
     * an axis value that is not one or two lengths is dropped rather than
     * guessed at.
     *
     * @return array<string, string>
     */
    private function physicalPaddingDeclarations(string $property, string $value): array
    {
        if ( isset(self::LOGICAL_PADDING_SIDES[ $property ]) ) {
            return array( self::LOGICAL_PADDING_SIDES[ $property ] => $value );
        }

        $axes = self::LOGICAL_PADDING_AXES[ $property ] ?? null;
        if ( null === $axes ) {
            return array( $property => $value );
        }

        $important = CssValueInspector::isImportant($value) ? ' !important' : '';
        $plain = CssValueInspector::withoutImportant($value);
        $parts = CssValueSplitter::splitTopLevelWhitespace($plain);
        if ( count($parts) < 1 || count($parts) > 2 ) {
            return array();
        }

        return array(
            $axes[0] => $parts[0] . $important,
            $axes[1] => ( $parts[1] ?? $parts[0] ) . $important,
        );
    }

    /**
     * Gutenberg spacing.padding does not apply calc() values, so Tailwind
     * `calc(.25rem * 6)` was stored and then dropped at render (pad 0).
     *
     * @param array<string, string> $declarations
     */
    private function gutenbergSpacingLength(string $value, array $declarations): string
    {
        $value = trim(CssValueInspector::withoutImportant($value));
        if ( '' === $value || '0' === $value ) {
            return $value;
        }

        if ( 1 === preg_match('/^calc\(\s*(.+?)\s*\*\s*([0-9]*\.?[0-9]+)\s*\)$/i', $value, $match) ) {
            $rem = $this->lengthInRem(trim($match[1]), $declarations);
            if ( null !== $rem ) {
                return $this->formatRem($rem * (float) $match[2]);
            }
        }

        return $value;
    }

    /**
     * @param array<string, string> $declarations
     */
    private function lengthInRem(string $token, array $declarations): ?float
    {
        if ( 1 === preg_match('/^var\(\s*(--[a-z0-9_-]+)\s*\)$/i', $token, $match) ) {
            $resolved = trim((string) ($declarations[ $match[1] ] ?? ''));

            return '' === $resolved ? null : $this->lengthInRem($resolved, $declarations);
        }

        if ( 1 === preg_match('/^([0-9]*\.?[0-9]+)rem$/i', $token, $match) ) {
            return (float) $match[1];
        }

        if ( 1 === preg_match('/^([0-9]*\.?[0-9]+)px$/i', $token, $match) ) {
            return (float) $match[1] / 16.0;
        }

        return null;
    }

    private function formatRem(float $rem): string
    {
        if ( abs($rem) < 0.0001 ) {
            return '0';
        }

        return rtrim(rtrim(number_format($rem, 4, '.', ''), '0'), '.') . 'rem';
    }
}
