<?php

declare(strict_types=1);

namespace Automattic\BlocksEngine\FigmaTransformer\Html;

/**
 * Resolves visual paint, stroke, image, and effect declarations.
 *
 * @internal
 */
final class StaticHtmlVisualStyleResolver
{
    public function __construct(
        private readonly StaticHtmlValueFormatter $formatter,
        private readonly StaticHtmlVectorEvidence $vectorEvidence,
        private readonly VectorSvgRenderer $vectorSvgRenderer,
        private readonly StyleDeclarationBuilder $styleDeclarationBuilder,
        private readonly PaintStackResolver $paintStackResolver,
        private readonly StaticHtmlAssetRegistry $assetRegistry,
    ) {
    }

    /**
     * @param array<string, mixed> $node
     * @return array<int, string>
     */
    public function backgroundDeclarations(array $node, string $type, ?float $zeroHeightVectorFallbackHeight, bool $rendersInlineVectorSvg): array
    {
        $styles = array();
        if ( $this->nodeShouldEmitCssBackground($type, $zeroHeightVectorFallbackHeight, $rendersInlineVectorSvg) ) {
            $background = $this->vectorEvidence->backgroundColor($node);
            if ( null !== $background ) {
                $styles[] = 'background:' . $background;
            }
        }

        $figmaBox = is_array($node['figma_box'] ?? null) ? $node['figma_box'] : array();
        if ( isset($figmaBox['opacity']) && is_numeric($figmaBox['opacity']) && is_finite((float) $figmaBox['opacity']) ) {
            $styles[] = 'opacity:' . $this->formatter->number((float) $figmaBox['opacity']);
        }

        if ( isset($figmaBox['blend_mode']) && is_scalar($figmaBox['blend_mode']) ) {
            $blendMode = $this->blendModeCss((string) $figmaBox['blend_mode']);
            if ( null !== $blendMode ) {
                $styles[] = 'mix-blend-mode:' . $blendMode;
            }
        }

        return $styles;
    }

    /**
     * @param array<string, mixed> $node
     * @param array<string, mixed>|null $parentNode
     * @param array<string, mixed> $layoutBox
     * @return array<int, string>
     */
    public function decorationDeclarations(array $node, string $type, ?array $parentNode, bool $fullBleedCanvasChild, array $layoutBox): array
    {
        $figmaBox = is_array($node['figma_box'] ?? null) ? $node['figma_box'] : array();
        $styles = $this->styleDeclarationBuilder->radiusStyles($figmaBox);
        if ( ! $this->rendersStrokeInsideInlineSvg($node, $type, $parentNode) ) {
            array_push($styles, ...$this->styleDeclarationBuilder->strokeStyles($node));
        }

        array_push($styles, ...$this->composedImageBackgroundStyles($node));
        return $fullBleedCanvasChild ? $this->scaleFullBleedImageCropStyles($styles, $layoutBox) : $styles;
    }

    /**
     * @param array<string, mixed> $node
     * @return array<int, string>
     */
    public function effectDeclarations(array $node, string $type): array
    {
        return $this->styleDeclarationBuilder->effectStyles($node, $type);
    }

    private function nodeShouldEmitCssBackground(string $type, ?float $zeroHeightVectorFallbackHeight, bool $rendersInlineVectorSvg): bool
    {
        if ( 'TEXT' === $type || ('LINE' === $type && $rendersInlineVectorSvg) ) {
            return false;
        }

        if ( ! in_array($type, array('VECTOR', 'BOOLEAN_OPERATION', 'LINE', 'ELLIPSE'), true) ) {
            return true;
        }

        return ! $rendersInlineVectorSvg || null !== $zeroHeightVectorFallbackHeight;
    }

    /** @param array<string, mixed> $node */
    private function rendersStrokeInsideInlineSvg(array $node, string $type, ?array $parentNode): bool
    {
        if ( ! in_array($type, array('VECTOR', 'BOOLEAN_OPERATION', 'LINE', 'ELLIPSE', 'STAR', 'POLYGON', 'REGULAR_POLYGON'), true) ) {
            return false;
        }

        $strokeStyles = $this->styleDeclarationBuilder->strokeStyles($node);
        if ( empty($strokeStyles) ) {
            return false;
        }

        foreach ( $strokeStyles as $style ) {
            if ( str_starts_with($style, 'border-image:') ) {
                return false;
            }
        }

        return null !== $this->vectorSvgRenderer->supportedVectorSvg($node, $type, $parentNode);
    }

    /**
     * @param array<string, mixed> $node
     * @return array<int, string>
     */
    private function composedImageBackgroundStyles(array $node): array
    {
        $imageLayers = $this->paintStackResolver->nodeImagePaintLayers($node);
        $fallbackPaths = ! empty($imageLayers)
            ? array_map(static fn (array $layer): string => (string) $layer['path'], $imageLayers)
            : array_values(array_filter(array($this->assetRegistry->resolveAndMarkNode($node))));

        return $this->paintStackResolver->composedImageBackgroundStyles($node, $fallbackPaths);
    }

    /**
     * @param array<int, string> $styles
     * @param array<string, mixed> $box
     * @return array<int, string>
     */
    private function scaleFullBleedImageCropStyles(array $styles, array $box): array
    {
        if ( ! isset($box['width']) || ! is_numeric($box['width']) || (float) $box['width'] <= 0.0 ) {
            return $styles;
        }

        $sourceWidth = (float) $box['width'];
        $scaled = array();
        foreach ( $styles as $style ) {
            if ( str_starts_with($style, 'background-size:') ) {
                $scaled[] = $this->scaleFullBleedImageCropDeclaration($style, $sourceWidth, 'size');
            } elseif ( str_starts_with($style, 'background-position:') ) {
                $scaled[] = $this->scaleFullBleedImageCropDeclaration($style, $sourceWidth, 'position');
            } else {
                $scaled[] = $style;
            }
        }

        return $scaled;
    }

    private function scaleFullBleedImageCropDeclaration(string $style, float $sourceWidth, string $kind): string
    {
        $parts = explode(':', $style, 2);
        if ( 2 !== count($parts) ) {
            return $style;
        }

        $scaledLayers = array();
        foreach ( explode(',', $parts[1]) as $layer ) {
            $tokens = preg_split('/\s+/', trim($layer));
            if ( ! is_array($tokens) || 2 !== count($tokens) ) {
                return $style;
            }

            $scaledTokens = array();
            foreach ( $tokens as $token ) {
                if ( 1 !== preg_match('/^-?\d+(?:\.\d+)?px$/', $token) ) {
                    return $style;
                }
                $value = (float) substr($token, 0, -2);
                if ( 'size' === $kind && $value <= 0.0 ) {
                    return $style;
                }
                $scaledTokens[] = 'calc(100vw * ' . $this->formatter->number($value / $sourceWidth) . ')';
            }
            $scaledLayers[] = implode(' ', $scaledTokens);
        }

        return $parts[0] . ':' . implode(',', $scaledLayers);
    }

    private function blendModeCss(string $blendMode): ?string
    {
        return match ( strtoupper($blendMode) ) {
            'MULTIPLY' => 'multiply',
            'SCREEN' => 'screen',
            'OVERLAY' => 'overlay',
            'DARKEN' => 'darken',
            'LIGHTEN' => 'lighten',
            'COLOR_DODGE' => 'color-dodge',
            'COLOR_BURN' => 'color-burn',
            'HARD_LIGHT' => 'hard-light',
            'SOFT_LIGHT' => 'soft-light',
            'DIFFERENCE' => 'difference',
            'EXCLUSION' => 'exclusion',
            'HUE' => 'hue',
            'SATURATION' => 'saturation',
            'COLOR' => 'color',
            'LUMINOSITY' => 'luminosity',
            default => null,
        };
    }
}
