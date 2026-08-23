<?php

declare(strict_types=1);

namespace Automattic\BlocksEngine\FigmaTransformer\Html;

/**
 * Converts normalized Figma text style arrays into CSS declarations.
 */
final class TextStyleDeclarationResolver
{
    public function __construct(
        private readonly TypographyModel $typographyModel,
        private readonly StaticHtmlValueFormatter $formatter,
        private readonly StaticHtmlTypographyState $typographyState,
        private readonly StaticHtmlTextSizingResolver $textSizingResolver,
    ) {
    }

    /**
     * @param array<string, mixed> $node
     * @return array<int, string>
     */
    public function nodeDeclarations(
        array $node,
        ?string $fallbackColor,
        bool $semanticListItemBodyText,
        bool $preserveChromeSpacing,
        bool $splitParagraphs,
        bool $atomicSingleLineLabel
    ): array {
        $text = is_array($node['figma_text'] ?? null) ? $node['figma_text'] : array();
        $style = is_array($text['style'] ?? null) ? $text['style'] : array();
        if ( $semanticListItemBodyText && $this->hasUnprovenUppercaseTransform($node, $style) ) {
            unset($style['text_transform']);
        }
        if ( ! isset($style['color']) && null !== $fallbackColor ) {
            $style['css_color'] = $fallbackColor;
        }

        $styles = $this->declarations($style);
        $derivedLineHeight = $this->textSizingResolver->derivedBaselineLineHeight($text);
        if ( null !== $derivedLineHeight && 0.0 < $derivedLineHeight ) {
            $styles = array_values(array_filter(
                $styles,
                static fn (string $style): bool => ! str_starts_with($style, 'line-height:')
            ));
            $styles[] = 'line-height:' . $this->formatter->number($derivedLineHeight) . 'px';
        }
        if ( $preserveChromeSpacing ) {
            $styles[] = 'white-space:pre-wrap';
        } elseif ( $this->textSizingResolver->hasLineBreaks($node) && ! $splitParagraphs ) {
            $styles[] = 'white-space:pre-line';
        } elseif ( $atomicSingleLineLabel ) {
            $styles[] = 'white-space:nowrap';
        }

        return $styles;
    }

    /** @param array<string, mixed> $source */
    public function hasExplicitUppercaseTextCase(array $source): bool
    {
        foreach ( array('textCase', 'text_case') as $key ) {
            if ( isset($source[$key]) && is_scalar($source[$key]) && 'UPPER' === strtoupper((string) $source[$key]) ) {
                return true;
            }
        }

        foreach ( array('style', 'textData', 'derivedTextData') as $key ) {
            if ( is_array($source[$key] ?? null) && $this->hasExplicitUppercaseTextCase($source[$key]) ) {
                return true;
            }
        }

        return false;
    }

    public function containsLowercase(string $text): bool
    {
        return 1 === preg_match('/\p{Ll}/u', $text);
    }

    /**
     * @param array<string, mixed> $node
     * @param array<string, mixed> $style
     */
    private function hasUnprovenUppercaseTransform(array $node, array $style): bool
    {
        return 'uppercase' === strtolower((string) ($style['text_transform'] ?? ''))
            && $this->containsLowercase($this->rawDecodedText($node))
            && ! $this->hasExplicitUppercaseTextCase($node);
    }

    /** @param array<string, mixed> $node */
    private function rawDecodedText(array $node): string
    {
        $text = is_array($node['figma_text'] ?? null) ? $node['figma_text'] : array();
        $segments = is_array($text['segments'] ?? null) ? $text['segments'] : array();
        if ( ! empty($segments) ) {
            $content = '';
            foreach ( $segments as $segment ) {
                if ( is_array($segment) && isset($segment['characters']) && is_scalar($segment['characters']) ) {
                    $content .= (string) $segment['characters'];
                }
            }
            if ( '' !== $content ) {
                return $content;
            }
        }

        if ( isset($text['characters']) && is_scalar($text['characters']) ) {
            return (string) $text['characters'];
        }

        return (string) ($node['characters'] ?? $node['text'] ?? '');
    }

    /**
     * @param array<string, mixed> $style
     * @return array<int, string>
     */
    public function declarations(array $style): array
    {
        $styles = array();
        $lineHeightStyles = array();

        $typographyStyle = $this->typographyModel->styleFromNormalizedStyle($style);
        if ( null !== $typographyStyle ) {
            foreach ( $this->typographyModel->declarations($typographyStyle, $this->typographyState->tokenVars()) as $declaration ) {
                if ( str_starts_with($declaration, 'line-height:') ) {
                    $lineHeightStyles[] = $declaration;
                    continue;
                }
                $styles[] = $declaration;
            }
        }

        if ( is_array($style['font_variation_settings'] ?? null) ) {
            $settings = array();
            foreach ( $style['font_variation_settings'] as $axis => $value ) {
                if ( is_string($axis) && 1 === preg_match('/^[A-Za-z0-9 ]{4}$/', $axis) && is_numeric($value) ) {
                    $settings[] = '"' . $axis . '" ' . $this->formatter->number((float) $value);
                }
            }
            if ( ! empty($settings) ) {
                $styles[] = 'font-variation-settings:' . implode(',', $settings);
            }
        }

        if ( is_array($style['font_feature_settings'] ?? null) ) {
            $settings = array();
            foreach ( $style['font_feature_settings'] as $feature => $enabled ) {
                if ( is_string($feature) && 1 === preg_match('/^[A-Za-z0-9 ]{4}$/', $feature) && is_numeric($enabled) ) {
                    $settings[] = '"' . $feature . '" ' . ((int) $enabled);
                }
            }
            if ( ! empty($settings) ) {
                $styles[] = 'font-feature-settings:' . implode(',', $settings);
            }
        }

        foreach ( $lineHeightStyles as $lineHeightStyle ) {
            $styles[] = $lineHeightStyle;
        }

        if ( isset($style['letter_spacing']) && is_numeric($style['letter_spacing']) ) {
            $styles[] = 'letter-spacing:' . $this->formatter->number((float) $style['letter_spacing']) . 'px';
        } elseif ( isset($style['letter_spacing_em']) && is_numeric($style['letter_spacing_em']) ) {
            $styles[] = 'letter-spacing:' . $this->formatter->number((float) $style['letter_spacing_em']) . 'em';
        }

        // Figma `paragraphIndent` maps to CSS first-line indent. Zero is implicit.
        if ( isset($style['paragraph_indent']) && is_numeric($style['paragraph_indent']) && 0.0 !== (float) $style['paragraph_indent'] ) {
            $styles[] = 'text-indent:' . $this->formatter->number((float) $style['paragraph_indent']) . 'px';
        }

        $color = $this->formatter->color($style['color'] ?? null);
        if ( null !== $color ) {
            $styles[] = 'color:' . $color;
        } elseif ( isset($style['css_color']) && is_scalar($style['css_color']) ) {
            $styles[] = 'color:' . (string) $style['css_color'];
        }

        if ( isset($style['text_align_horizontal']) && is_scalar($style['text_align_horizontal']) ) {
            $align = strtolower((string) $style['text_align_horizontal']);
            $align = 'justified' === $align ? 'justify' : $align;
            if ( in_array($align, array('left', 'center', 'right', 'justify'), true) ) {
                $styles[] = 'text-align:' . $align;
            }
        }

        if ( isset($style['text_align_vertical']) && is_scalar($style['text_align_vertical']) ) {
            $align = strtolower((string) $style['text_align_vertical']);
            if ( in_array($align, array('top', 'middle', 'bottom'), true) ) {
                $styles[] = 'vertical-align:' . $align;
            }
        }

        $decorations = array();
        if ( isset($style['text_decoration']) && is_scalar($style['text_decoration']) ) {
            $decoration = strtolower((string) $style['text_decoration']);
            if ( in_array($decoration, array('underline', 'line-through'), true) ) {
                $decorations[] = $decoration;
            }
        }
        if ( true === ($style['underline'] ?? false) ) {
            $decorations[] = 'underline';
        }
        if ( true === ($style['strikethrough'] ?? false) ) {
            $decorations[] = 'line-through';
        }
        if ( ! empty($decorations) ) {
            $styles[] = 'text-decoration:' . implode(' ', array_values(array_unique($decorations)));
        }

        if ( isset($style['text_transform']) && is_scalar($style['text_transform']) ) {
            $transform = strtolower((string) $style['text_transform']);
            if ( in_array($transform, array('uppercase', 'lowercase', 'capitalize', 'none'), true) ) {
                $styles[] = 'text-transform:' . $transform;
            }
        }

        if ( isset($style['font_variant']) && is_scalar($style['font_variant']) ) {
            $variant = strtolower((string) $style['font_variant']);
            if ( in_array($variant, array('small-caps', 'normal'), true) ) {
                $styles[] = 'font-variant:' . $variant;
            }
        }

        if ( isset($style['max_lines']) && is_numeric($style['max_lines']) && 0 < (int) $style['max_lines'] ) {
            $styles[] = '-webkit-line-clamp:' . ((int) $style['max_lines']);
            $styles[] = 'display:-webkit-box';
            $styles[] = '-webkit-box-orient:vertical';
            $styles[] = 'overflow:hidden';
        }

        return $styles;
    }
}
