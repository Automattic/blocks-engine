<?php

declare(strict_types=1);

namespace Automattic\BlocksEngine\FigmaTransformer\Html;

/**
 * Pure paint and asset-reference evidence used while resolving vector SVG.
 */
final class StaticHtmlVectorEvidence
{
    public function __construct(
        private readonly StaticHtmlValueFormatter $formatter,
        private readonly PaintStackResolver $paintStackResolver,
    ) {
    }

    /** @param array<int, mixed> $paints */
    public function firstSolidPaint(array $paints): ?string
    {
        foreach ( $paints as $paint ) {
            if ( ! is_array($paint) || 'SOLID' !== ($paint['type'] ?? null) ) {
                continue;
            }

            $color = $this->formatter->color($paint['color'] ?? null, $paint['opacity'] ?? null);
            if ( null !== $color ) {
                return $color;
            }
        }

        return null;
    }

    /** @param array<string, mixed> $node */
    public function backgroundColor(array $node): ?string
    {
        foreach ( array('fills', 'background') as $key ) {
            $paints = is_array($node['figma_paints'][$key] ?? null) ? $node['figma_paints'][$key] : array();
            $paint = $this->paintStackResolver->firstCssPaint($paints);
            if ( is_array($paint) ) {
                return $paint['css'];
            }
        }

        return $this->formatter->color($node['background'] ?? $node['backgroundColor'] ?? $node['fill'] ?? $node['fills'][0]['color'] ?? $node['fillPaints'][0]['color'] ?? $node['paints']['fills'][0]['color'] ?? $node['paints'][0]['color'] ?? $node['paints'][0][0]['color'] ?? null);
    }

    /**
     * @param array<string, mixed> $node
     * @return array<int, array<string, mixed>>
     */
    public function nodeImagePaints(array $node): array
    {
        return VisualLayerEvidence::imagePaints($node);
    }

    /**
     * @param array<string, mixed> $node
     * @return array<int, string>
     */
    public function explicitNodeAssetReferences(array $node): array
    {
        return VisualLayerEvidence::explicitNodeAssetReferences($node);
    }
}
