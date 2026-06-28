<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Patterns;

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
 *  - Filled buttons get style.color.background + style.color.text (+ border radius).
 *  - Outline/ghost buttons (transparent/absent background) get style.border.* +
 *    style.color.text and never a background or gradient fill.
 *  - Buttons carry padding but not margin (inter-button spacing rides on the
 *    parent core/buttons block gap), plus a curated typography subset.
 * A button whose resolved CSS carries no paintable colors/border stays default.
 */
final class ButtonStyleResolver
{
    /**
     * Typography supports projected onto buttons, in canonical emission order.
     */
    private const BUTTON_TYPOGRAPHY = array( 'fontSize', 'fontWeight', 'lineHeight', 'textTransform' );

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

        // Button color policy: paintable fill + text only, never a gradient. Emit
        // background before text to match the native core/button attribute shape.
        $color      = is_array($mapped['color'] ?? null) ? $mapped['color'] : array();
        $background = (string) ($color['background'] ?? '');
        $text       = (string) ($color['text'] ?? '');
        if ( '' !== $background ) {
            $style['color']['background'] = $background;
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
            $style['spacing']['padding'] = $padding;
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
            if ( '' !== $name && '' !== $value ) {
                $declarations[ $name ] = preg_replace('/\s+/', ' ', $value) ?? $value;
            }
        }

        return $declarations;
    }
}
