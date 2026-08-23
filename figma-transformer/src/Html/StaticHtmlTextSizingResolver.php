<?php

declare(strict_types=1);

namespace Automattic\BlocksEngine\FigmaTransformer\Html;

/**
 * Resolves measured Figma text sizing authority and fixed-height safety.
 *
 * @internal
 */
final class StaticHtmlTextSizingResolver
{
    public function __construct(private readonly StaticHtmlNodeInspector $nodeInspector)
    {
    }

    /**
     * @param array<string, mixed> $node
     * @return array{size: float, authority: string, derived_size: float, agreement_tolerance: float}|null
     */
    public function derivedLayoutSizeDecision(array $node, string $dimension): ?array
    {
        $derivedSize = $this->derivedLayoutSize($node, $dimension);
        if ( null === $derivedSize ) {
            return null;
        }

        $agreementTolerance = 0.5;
        $sourceBox = is_array($node['box'] ?? null) ? $node['box'] : array();
        $sourceSize = isset($sourceBox[$dimension]) && is_numeric($sourceBox[$dimension]) && is_finite((float) $sourceBox[$dimension])
            ? (float) $sourceBox[$dimension]
            : null;
        $sourceIsAuthoritative = 'height' === $dimension
            && null !== $sourceSize
            && abs($derivedSize - $sourceSize) > $agreementTolerance;

        return array(
            'size' => $sourceIsAuthoritative ? $sourceSize : $derivedSize,
            'authority' => $sourceIsAuthoritative ? 'source_box' : 'derived_layout',
            'derived_size' => $derivedSize,
            'agreement_tolerance' => $agreementTolerance,
        );
    }

    /** @param array<string, mixed> $node */
    public function shouldAvoidTinyFixedHeight(array $node, float $height): bool
    {
        if ( 0.0 >= $height || '' === trim($this->nodeInspector->nodePlainText($node)) || $this->hasLineBreaks($node) || $this->hasDerivedLineBreaks($node) ) {
            return false;
        }

        $text = is_array($node['figma_text'] ?? null) ? $node['figma_text'] : array();
        $derivedLayout = is_array($text['derived_layout'] ?? null) ? $text['derived_layout'] : array();
        $baselines = is_array($derivedLayout['baselines'] ?? null) ? array_values(array_filter($derivedLayout['baselines'], 'is_array')) : array();
        if ( 1 !== count($baselines) ) {
            return false;
        }

        $baseline = $baselines[0];
        if ( ! isset($baseline['lineHeight'], $baseline['lineY']) || ! is_numeric($baseline['lineHeight']) || ! is_numeric($baseline['lineY']) ) {
            return false;
        }

        return 0.0 > (float) $baseline['lineY'] && (float) $baseline['lineHeight'] > $height + 0.5;
    }

    /**
     * @param array<string, mixed> $node
     * @param array<string, mixed>|null $parentNode
     */
    public function shouldUseMeasuredFlexHeight(array $node, ?array $parentNode): bool
    {
        if ( null === $parentNode || 'TEXT' !== strtoupper((string) ($node['type'] ?? '')) ) {
            return false;
        }

        $parentLayout = is_array($parentNode['layout'] ?? null) ? $parentNode['layout'] : array();
        if ( 'flex' !== ($parentLayout['display'] ?? null) || 'center' === ($parentLayout['align_items'] ?? null) ) {
            return false;
        }

        $box = is_array($node['box'] ?? null) ? $node['box'] : array();
        return isset($box['height']) && is_numeric($box['height']) && $this->shouldAvoidTinyFixedHeight($node, (float) $box['height']);
    }

    /** @param array<string, mixed> $node */
    public function hasLineBreaks(array $node): bool
    {
        $text = is_array($node['figma_text'] ?? null) ? $node['figma_text'] : array();
        $segments = is_array($text['segments'] ?? null) ? $text['segments'] : array();
        foreach ( $segments as $segment ) {
            if ( is_array($segment) && isset($segment['characters']) && is_scalar($segment['characters']) && str_contains((string) $segment['characters'], "\n") ) {
                return true;
            }
        }

        foreach ( array($text['characters'] ?? null, $node['characters'] ?? null, $node['text'] ?? null) as $value ) {
            if ( is_scalar($value) && str_contains((string) $value, "\n") ) {
                return true;
            }
        }

        return false;
    }

    /** @param array<string, mixed> $node */
    public function hasDerivedLineBreaks(array $node): bool
    {
        $text = is_array($node['figma_text'] ?? null) ? $node['figma_text'] : array();
        $derivedLayout = is_array($text['derived_layout'] ?? null) ? $text['derived_layout'] : array();
        return isset($derivedLayout['baseline_count']) && is_numeric($derivedLayout['baseline_count']) && 1 < (int) $derivedLayout['baseline_count'];
    }

    /** @param array<string, mixed> $node */
    private function derivedLayoutSize(array $node, string $dimension): ?float
    {
        $text = is_array($node['figma_text'] ?? null) ? $node['figma_text'] : array();
        $derivedLayout = is_array($text['derived_layout'] ?? null) ? $text['derived_layout'] : array();
        $size = is_array($derivedLayout['size'] ?? null) ? $derivedLayout['size'] : array();
        return isset($size[$dimension]) && is_numeric($size[$dimension]) && 0.0 <= (float) $size[$dimension]
            ? (float) $size[$dimension]
            : null;
    }
}
