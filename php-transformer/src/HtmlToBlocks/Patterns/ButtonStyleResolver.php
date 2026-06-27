<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Patterns;

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
 * The translation is theme-independent and keys only off the resolved
 * declarations:
 *  - Filled buttons get style.color.background + style.color.text (+ border radius).
 *  - Outline/ghost buttons (transparent background) get style.border.* + style.color.text
 *    and never a background.
 * A button whose resolved CSS carries no paintable colors/border stays default.
 */
final class ButtonStyleResolver
{
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

        $style = array();

        $background = $this->backgroundColor($declarations);
        $text       = $this->color($declarations['color'] ?? '');
        if ( '' !== $background ) {
            $style['color']['background'] = $background;
        }
        if ( '' !== $text ) {
            $style['color']['text'] = $text;
        }

        $border = $this->border($declarations);
        if ( array() !== $border ) {
            $style['border'] = $border;
        }

        $padding = $this->padding($declarations);
        if ( array() !== $padding ) {
            $style['spacing']['padding'] = $padding;
        }

        $typography = $this->typography($declarations);
        if ( array() !== $typography ) {
            $style['typography'] = $typography;
        }

        if ( array() === $style ) {
            return array();
        }

        return array( 'style' => $style );
    }

    /**
     * Resolve the background color, ignoring transparent/none/gradient/image
     * backgrounds so outline/ghost buttons never receive a paintable fill.
     *
     * @param array<string, string> $declarations
     */
    private function backgroundColor(array $declarations): string
    {
        $value = trim((string) ($declarations['background-color'] ?? ''));
        if ( '' === $value ) {
            // `background` shorthand: only treat it as a fill when it is a bare color.
            $value = trim((string) ($declarations['background'] ?? ''));
            if ( '' === $value || preg_match('/\b(?:url\s*\(|gradient\s*\()/i', $value) ) {
                return '';
            }
            // Take the first token of the shorthand as the candidate color.
            $value = preg_split('/\s+/', $value)[0] ?? '';
        }

        return $this->color($value);
    }

    /**
     * @param array<string, string> $declarations
     * @return array<string, string>
     */
    private function border(array $declarations): array
    {
        $border = array();

        // Longhand declarations win over the shorthand.
        $shorthand = $this->parseBorderShorthand((string) ($declarations['border'] ?? ''));

        $width = trim((string) ($declarations['border-width'] ?? $shorthand['width'] ?? ''));
        $style = strtolower(trim((string) ($declarations['border-style'] ?? $shorthand['style'] ?? '')));
        $color = $this->color((string) ($declarations['border-color'] ?? $shorthand['color'] ?? ''));

        // `border: 0` / `border: none` means no border at all.
        $noBorder = 'none' === $style || ( '' !== $width && (float) $width === 0.0 && '' === $color && '' === $style );
        if ( ! $noBorder ) {
            if ( '' !== $width && (float) $width !== 0.0 ) {
                $border['width'] = $width;
            }
            if ( '' !== $style && 'none' !== $style ) {
                $border['style'] = $style;
            }
            if ( '' !== $color ) {
                $border['color'] = $color;
            }
        }

        $radius = trim((string) ($declarations['border-radius'] ?? ''));
        if ( '' !== $radius ) {
            $border['radius'] = $radius;
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
        foreach ( preg_split('/\s+/', $value) ?: array() as $token ) {
            $lower = strtolower($token);
            if ( in_array($lower, array( 'none', 'hidden', 'solid', 'dashed', 'dotted', 'double', 'groove', 'ridge', 'inset', 'outset' ), true) ) {
                $parsed['style'] = $lower;
                continue;
            }
            if ( preg_match('/^[0-9.]+(?:px|em|rem|%|pt|vw|vh)?$/i', $token) || in_array($lower, array( 'thin', 'medium', 'thick' ), true) ) {
                $parsed['width'] = $token;
                continue;
            }
            if ( '' !== $this->color($token) ) {
                $parsed['color'] = $token;
            }
        }

        return $parsed;
    }

    /**
     * @param array<string, string> $declarations
     * @return array<string, string>
     */
    private function padding(array $declarations): array
    {
        $shorthand = trim((string) ($declarations['padding'] ?? ''));
        $sides = array( 'top' => '', 'right' => '', 'bottom' => '', 'left' => '' );

        if ( '' !== $shorthand ) {
            $parts = preg_split('/\s+/', $shorthand) ?: array();
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
            $longhand = trim((string) ($declarations[ 'padding-' . $side ] ?? ''));
            if ( '' !== $longhand ) {
                $sides[ $side ] = $longhand;
            }
        }

        return array_filter($sides, static fn (string $value): bool => '' !== trim($value));
    }

    /**
     * @param array<string, string> $declarations
     * @return array<string, string>
     */
    private function typography(array $declarations): array
    {
        $typography = array();
        $map = array(
            'font-size'      => 'fontSize',
            'font-weight'    => 'fontWeight',
            'line-height'    => 'lineHeight',
            'text-transform' => 'textTransform',
        );

        foreach ( $map as $cssName => $attrName ) {
            $value = trim((string) ($declarations[ $cssName ] ?? ''));
            if ( '' !== $value ) {
                $typography[ $attrName ] = $value;
            }
        }

        return $typography;
    }

    /**
     * Return the value when it is a usable CSS color, otherwise an empty string.
     */
    private function color(string $value): string
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
        if ( preg_match('/^(?:rgb|rgba|hsl|hsla)\s*\(/i', $value) ) {
            return $value;
        }
        if ( 'currentcolor' === $lower ) {
            return 'currentColor';
        }
        // Named colors (e.g. white, navy). Reject anything with whitespace/symbols.
        if ( preg_match('/^[a-z]+$/', $lower) ) {
            return $value;
        }

        return '';
    }

    /**
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
