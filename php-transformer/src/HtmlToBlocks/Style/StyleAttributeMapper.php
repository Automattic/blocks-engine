<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style;

/**
 * Translates a block element's resolved CSS declarations (inline style plus the
 * matched `<style>`/linked CSS rules the transformer already resolves) into the
 * canonical WordPress block style attribute OBJECT — never a raw `style` string.
 *
 * Core blocks expect a structured `style` object (style.typography / style.color /
 * style.spacing / style.border) plus the `layout` attribute, and they render that
 * object back to inline CSS + support classes in `save()`. Storing a raw inline
 * `style` STRING on a core block makes the stored HTML diverge from `save()`, so
 * the editor flags "unexpected or invalid content" for every styled element. This
 * generalizes the `ButtonStyleResolver` (#241) approach to all blocks (#261).
 *
 * Declarations that do not map to a block support (position, overflow, transform,
 * background images, etc.) are returned as `leftover` so the caller can drop them
 * and rely on the preserved `className` + carried CSS — they are NEVER emitted as
 * a raw `style` string. Declarations consumed by the block `layout` attribute
 * (display/flex/grid/gap) are dropped from the style object entirely.
 */
final class StyleAttributeMapper
{
    /**
     * CSS properties consumed by the block `layout` attribute rather than the
     * canonical `style` object. These are never emitted as inline style.
     */
    private const LAYOUT_PROPERTIES = array(
        'display',
        'flex-direction',
        'flex-flow',
        'flex-wrap',
        'justify-content',
        'justify-items',
        'align-content',
        'align-items',
        'place-content',
        'place-items',
        'gap',
        'row-gap',
        'column-gap',
        'grid-template-columns',
        'grid-template-rows',
        'grid-template-areas',
        'grid-auto-flow',
        'grid-auto-rows',
        'grid-auto-columns',
    );

    /**
     * Map resolved CSS declarations to canonical block style attributes.
     *
     * @param array<string, string> $declarations
     * @return array{style: array<string, mixed>, leftover: array<string, string>}
     */
    public function map(array $declarations): array
    {
        $normalized = array();
        foreach ( $declarations as $name => $value ) {
            $name  = strtolower(trim((string) $name));
            $value = trim((string) $value);
            if ( '' !== $name && '' !== $value ) {
                $normalized[ $name ] = $value;
            }
        }

        $consumed = array();
        $style    = array();

        $typography = $this->typography($normalized, $consumed);
        if ( array() !== $typography ) {
            $style['typography'] = $typography;
        }

        $color = $this->color($normalized, $consumed);
        if ( array() !== $color ) {
            $style['color'] = $color;
        }

        $spacing = array();
        $padding = $this->boxSides('padding', $normalized, $consumed);
        if ( array() !== $padding ) {
            $spacing['padding'] = $padding;
        }
        $margin = $this->boxSides('margin', $normalized, $consumed);
        if ( array() !== $margin ) {
            $spacing['margin'] = $margin;
        }
        if ( array() !== $spacing ) {
            $style['spacing'] = $spacing;
        }

        $border = $this->border($normalized, $consumed);
        if ( array() !== $border ) {
            $style['border'] = $border;
        }

        $leftover = array();
        foreach ( $normalized as $name => $value ) {
            if ( isset($consumed[ $name ]) || in_array($name, self::LAYOUT_PROPERTIES, true) ) {
                continue;
            }
            $leftover[ $name ] = $value;
        }

        return array(
            'style'    => $style,
            'leftover' => $leftover,
        );
    }

    /**
     * Serialize a canonical block style object back to the inline CSS string and
     * the has-* support classes WordPress emits in `save()`. Keeping the rendered
     * markup in sync with the stored attribute object is what makes the block
     * validate in the editor.
     *
     * @param array<string, mixed> $style
     * @return array{classes: string, style: string}
     */
    public function serialize(array $style): array
    {
        $classes      = array();
        $declarations = array();

        $colorStyle = is_array($style['color'] ?? null) ? $style['color'] : array();
        $text       = trim((string) ($colorStyle['text'] ?? ''));
        $background = trim((string) ($colorStyle['background'] ?? ''));
        $gradient   = trim((string) ($colorStyle['gradient'] ?? ''));
        if ( '' !== $text ) {
            $classes[]      = 'has-text-color';
            $declarations[] = 'color:' . $text;
        }
        if ( '' !== $background ) {
            $classes[]      = 'has-background';
            $declarations[] = 'background-color:' . $background;
        }
        if ( '' !== $gradient ) {
            $classes[]      = 'has-background';
            $declarations[] = 'background:' . $gradient;
        }

        $border = is_array($style['border'] ?? null) ? $style['border'] : array();
        if ( '' !== trim((string) ($border['color'] ?? '')) ) {
            $classes[]      = 'has-border-color';
            $declarations[] = 'border-color:' . trim((string) $border['color']);
        }
        if ( '' !== trim((string) ($border['width'] ?? '')) ) {
            $declarations[] = 'border-width:' . trim((string) $border['width']);
        }
        if ( '' !== trim((string) ($border['style'] ?? '')) ) {
            $declarations[] = 'border-style:' . trim((string) $border['style']);
        }
        if ( '' !== trim((string) ($border['radius'] ?? '')) ) {
            $declarations[] = 'border-radius:' . trim((string) $border['radius']);
        }

        $spacing = is_array($style['spacing'] ?? null) ? $style['spacing'] : array();
        foreach ( array( 'padding', 'margin' ) as $box ) {
            $sides = is_array($spacing[ $box ] ?? null) ? $spacing[ $box ] : array();
            foreach ( array( 'top', 'right', 'bottom', 'left' ) as $side ) {
                $value = trim((string) ($sides[ $side ] ?? ''));
                if ( '' !== $value ) {
                    $declarations[] = $box . '-' . $side . ':' . $value;
                }
            }
        }

        $typography    = is_array($style['typography'] ?? null) ? $style['typography'] : array();
        $typographyMap = array(
            'fontSize'      => 'font-size',
            'fontWeight'    => 'font-weight',
            'lineHeight'    => 'line-height',
            'letterSpacing' => 'letter-spacing',
            'textTransform' => 'text-transform',
            'textDecoration' => 'text-decoration',
            'fontStyle'     => 'font-style',
        );
        foreach ( $typographyMap as $attrName => $cssName ) {
            $value = trim((string) ($typography[ $attrName ] ?? ''));
            if ( '' !== $value ) {
                $declarations[] = $cssName . ':' . $value;
            }
        }

        return array(
            'classes' => implode(' ', array_values(array_unique($classes))),
            'style'   => implode(';', $declarations),
        );
    }

    /**
     * @param array<string, string> $declarations
     * @param array<string, bool> $consumed
     * @return array<string, string>
     */
    private function typography(array $declarations, array &$consumed): array
    {
        $typography = array();
        $map = array(
            'font-size'       => 'fontSize',
            'font-weight'     => 'fontWeight',
            'line-height'     => 'lineHeight',
            'letter-spacing'  => 'letterSpacing',
            'text-transform'  => 'textTransform',
            'text-decoration' => 'textDecoration',
            'font-style'      => 'fontStyle',
        );

        foreach ( $map as $cssName => $attrName ) {
            $value = trim((string) ($declarations[ $cssName ] ?? ''));
            if ( '' !== $value ) {
                $typography[ $attrName ] = $value;
                $consumed[ $cssName ]    = true;
            }
        }

        return $typography;
    }

    /**
     * @param array<string, string> $declarations
     * @param array<string, bool> $consumed
     * @return array<string, string>
     */
    private function color(array $declarations, array &$consumed): array
    {
        $color = array();

        if ( isset($declarations['color']) ) {
            $consumed['color'] = true;
            $text = $this->cssColor($declarations['color']);
            if ( '' !== $text ) {
                $color['text'] = $text;
            }
        }

        $gradient = $this->gradient($declarations, $consumed);
        $background = $this->backgroundColor($declarations, $consumed);
        if ( '' !== $background ) {
            $color['background'] = $background;
        }
        if ( '' !== $gradient ) {
            $color['gradient'] = $gradient;
        }

        return $color;
    }

    /**
     * @param array<string, string> $declarations
     * @param array<string, bool> $consumed
     */
    private function backgroundColor(array $declarations, array &$consumed): string
    {
        if ( isset($declarations['background-color']) ) {
            $consumed['background-color'] = true;
            return $this->cssColor($declarations['background-color']);
        }

        $value = trim((string) ($declarations['background'] ?? ''));
        if ( '' === $value || preg_match('/\b(?:url\s*\(|gradient\s*\()/i', $value) ) {
            return '';
        }

        $consumed['background'] = true;
        return $this->cssColor(CssValueSplitter::splitTopLevelWhitespace($value)[0] ?? '');
    }

    /**
     * @param array<string, string> $declarations
     * @param array<string, bool> $consumed
     */
    private function gradient(array $declarations, array &$consumed): string
    {
        foreach ( array( 'background', 'background-image' ) as $name ) {
            $value = trim((string) ($declarations[ $name ] ?? ''));
            if ( '' !== $value && preg_match('/\bgradient\s*\(/i', $value) && CssValueSplitter::hasBalancedParens($value) ) {
                $consumed[ $name ] = true;
                return $value;
            }
        }

        return '';
    }

    /**
     * @param array<string, string> $declarations
     * @param array<string, bool> $consumed
     * @return array<string, string>
     */
    private function boxSides(string $property, array $declarations, array &$consumed): array
    {
        $sides = array( 'top' => '', 'right' => '', 'bottom' => '', 'left' => '' );

        $shorthand = trim((string) ($declarations[ $property ] ?? ''));
        if ( '' !== $shorthand ) {
            $consumed[ $property ] = true;
            $parts = CssValueSplitter::splitTopLevelWhitespace($shorthand);
            $count = count($parts);
            if ( 1 === $count ) {
                $sides = array( 'top' => $parts[0], 'right' => $parts[0], 'bottom' => $parts[0], 'left' => $parts[0] );
            } elseif ( 2 === $count ) {
                $sides = array( 'top' => $parts[0], 'right' => $parts[1], 'bottom' => $parts[0], 'left' => $parts[1] );
            } elseif ( 3 === $count ) {
                $sides = array( 'top' => $parts[0], 'right' => $parts[1], 'bottom' => $parts[2], 'left' => $parts[1] );
            } elseif ( $count >= 4 ) {
                $sides = array( 'top' => $parts[0], 'right' => $parts[1], 'bottom' => $parts[2], 'left' => $parts[3] );
            }
        }

        foreach ( array( 'top', 'right', 'bottom', 'left' ) as $side ) {
            $longhand = trim((string) ($declarations[ $property . '-' . $side ] ?? ''));
            if ( '' !== $longhand ) {
                $sides[ $side ]                       = $longhand;
                $consumed[ $property . '-' . $side ]  = true;
            }
        }

        return array_filter($sides, static fn (string $value): bool => '' !== trim($value));
    }

    /**
     * @param array<string, string> $declarations
     * @param array<string, bool> $consumed
     * @return array<string, string>
     */
    private function border(array $declarations, array &$consumed): array
    {
        $border    = array();
        $shorthand = $this->parseBorderShorthand((string) ($declarations['border'] ?? ''));
        if ( isset($declarations['border']) ) {
            $consumed['border'] = true;
        }

        $width = trim((string) ($declarations['border-width'] ?? $shorthand['width'] ?? ''));
        $style = strtolower(trim((string) ($declarations['border-style'] ?? $shorthand['style'] ?? '')));
        $colorValue = $this->cssColor((string) ($declarations['border-color'] ?? $shorthand['color'] ?? ''));
        foreach ( array( 'border-width', 'border-style', 'border-color' ) as $name ) {
            if ( isset($declarations[ $name ]) ) {
                $consumed[ $name ] = true;
            }
        }

        $noBorder = 'none' === $style || ( '' !== $width && (float) $width === 0.0 && '' === $colorValue && '' === $style );
        if ( ! $noBorder ) {
            if ( '' !== $width && (float) $width !== 0.0 ) {
                $border['width'] = $width;
            }
            if ( '' !== $style && 'none' !== $style ) {
                $border['style'] = $style;
            }
            if ( '' !== $colorValue ) {
                $border['color'] = $colorValue;
            }
        }

        $radius = trim((string) ($declarations['border-radius'] ?? ''));
        if ( '' !== $radius ) {
            $consumed['border-radius'] = true;
            $border['radius']          = $radius;
        }

        return $border;
    }

    /**
     * @return array{width?: string, style?: string, color?: string}
     */
    private function parseBorderShorthand(string $value): array
    {
        $value = trim($value);
        if ( '' === $value ) {
            return array();
        }

        $parsed = array();
        foreach ( CssValueSplitter::splitTopLevelWhitespace($value) as $token ) {
            $lower = strtolower($token);
            if ( in_array($lower, array( 'none', 'hidden', 'solid', 'dashed', 'dotted', 'double', 'groove', 'ridge', 'inset', 'outset' ), true) ) {
                $parsed['style'] = $lower;
                continue;
            }
            if ( preg_match('/^[0-9.]+(?:px|em|rem|%|pt|vw|vh)?$/i', $token) || in_array($lower, array( 'thin', 'medium', 'thick' ), true) ) {
                $parsed['width'] = $token;
                continue;
            }
            if ( '' !== $this->cssColor($token) ) {
                $parsed['color'] = $token;
            }
        }

        return $parsed;
    }

    /**
     * Return the value when it is a usable CSS color, otherwise an empty string.
     */
    private function cssColor(string $value): string
    {
        $value = trim($value);
        if ( '' === $value ) {
            return '';
        }

        $lower = strtolower($value);
        if ( in_array($lower, array( 'transparent', 'none', 'inherit', 'initial', 'unset', 'revert', 'auto' ), true) ) {
            return '';
        }

        if ( preg_match('/^#[0-9a-f]{3,8}$/i', $value) ) {
            return $value;
        }

        // Functional color notation must be syntactically complete: a truncated
        // value such as `rgba(251,` (produced by a non-paren-aware split) still
        // matches the `rgb(`/`var(` prefix but is invalid CSS. WordPress drops
        // the inline style for it while keeping the has-* support class, so the
        // saved markup diverges from the block attributes ("unexpected or invalid
        // content"). Require balanced parentheses and a closing `)` so only
        // values WordPress will actually render are stored.
        if ( str_contains($value, '(') ) {
            if ( ! str_ends_with($value, ')') || ! CssValueSplitter::hasBalancedParens($value) ) {
                return '';
            }
        }

        if ( preg_match('/^(?:rgb|rgba|hsl|hsla)\s*\(/i', $value) ) {
            return $value;
        }
        if ( preg_match('/^var\s*\(\s*--[a-z0-9_-]+/i', $value) ) {
            return $value;
        }
        if ( 'currentcolor' === $lower ) {
            return 'currentColor';
        }
        if ( preg_match('/^[a-z]+$/', $lower) ) {
            return $value;
        }

        return '';
    }
}
