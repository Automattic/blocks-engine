<?php

declare(strict_types=1);

namespace Automattic\BlocksEngine\FigmaTransformer\Html;

/**
 * Resolves responsive breakpoint width policy into CSS declarations.
 */
final class BreakpointDimensionPolicy
{
    /**
     * @param callable(float): string $number
     */
    public function __construct(
        private readonly mixed $number,
    ) {
    }

    /**
     * Keep a root breakpoint frame fluid instead of freezing it to the source canvas.
     *
     * @return array<int, string>
     */
    public function rootFillDeclarations(): array
    {
        return array('width:100%');
    }

    /**
     * Fill the available responsive inline size without preserving a source max width.
     *
     * @return array<int, string>
     */
    public function fluidFillDeclarations(): array
    {
        return array('width:100%', 'max-width:100%');
    }

    /**
     * Fill the breakpoint viewport with symmetric gutters while preserving source max width.
     *
     * @return array<int, string>
     */
    public function sourceMaxWidthDeclarations(float $sourceMaxWidth, float $gutter, string $placement): array
    {
        $declarations = array(
            'width:calc(100% - ' . ($this->number)($gutter * 2.0) . 'px)',
            'max-width:' . ($this->number)($sourceMaxWidth) . 'px',
        );

        if ( 'absolute' === $placement ) {
            $declarations[] = 'left:' . ($this->number)($gutter) . 'px';
            $declarations[] = 'right:auto';
            return $declarations;
        }

        if ( 'centered' === $placement ) {
            $declarations[] = 'margin-left:auto';
            $declarations[] = 'margin-right:auto';
        }

        return $declarations;
    }

    /**
     * Resolve a variant width override relative to its breakpoint parent.
     *
     * @param array<string, string> $baseMap
     * @param array<string, mixed> $baseNode
     * @param array<string, mixed> $variantNode
     * @param array<string, mixed>|null $baseParentNode
     * @param array<string, mixed>|null $variantParentNode
     * @return array<int, string>|null
     */
    public function breakpointWidthDeclarations(string $value, array $baseMap, array $baseNode, array $variantNode, ?array $baseParentNode, ?array $variantParentNode): ?array
    {
        $variantWidth = $this->cssPixelValue($value);
        if ( null === $variantWidth || empty($variantNode) ) {
            return null;
        }

        $variantType = strtoupper((string) ($variantNode['type'] ?? 'FRAME'));
        $variantSourceId = isset($variantNode['figma_component_source_id']) && is_scalar($variantNode['figma_component_source_id']) ? (string) $variantNode['figma_component_source_id'] : '';
        if ( '' === $variantSourceId && isset($variantNode['source_id']) && is_scalar($variantNode['source_id']) ) {
            $variantSourceId = (string) $variantNode['source_id'];
        }
        if ( '' !== $variantSourceId && ! in_array($variantType, array('FRAME', 'GROUP', 'INSTANCE', 'COMPONENT', 'SYMBOL'), true) ) {
            return null;
        }

        if ( null === $variantParentNode ) {
            return $this->rootFillDeclarations();
        }

        $variantParentBox = is_array($variantParentNode['box'] ?? null) ? $variantParentNode['box'] : array();
        if ( ! isset($variantParentBox['width']) || ! is_numeric($variantParentBox['width']) ) {
            return null;
        }

        $variantParentWidth = (float) $variantParentBox['width'];
        if ( $variantParentWidth <= 0.0 || $variantWidth > $variantParentWidth + 1.0 ) {
            return null;
        }

        $baseWidth = $this->nodeBoxWidth($baseNode);
        if ( null !== $baseWidth && abs($variantWidth - $baseWidth) <= 1.0 ) {
            return null;
        }

        $variantParentLayout = is_array($variantParentNode['layout'] ?? null) ? $variantParentNode['layout'] : array();
        $padding = is_array($variantParentLayout['padding'] ?? null) ? $variantParentLayout['padding'] : array();
        $paddingLeft = isset($padding['left']) && is_numeric($padding['left']) ? (float) $padding['left'] : 0.0;
        $paddingRight = isset($padding['right']) && is_numeric($padding['right']) ? (float) $padding['right'] : 0.0;
        $contentWidth = max(0.0, $variantParentWidth - $paddingLeft - $paddingRight);
        if ( abs($variantWidth - $variantParentWidth) <= 1.0 || abs($variantWidth - $contentWidth) <= 1.0 ) {
            return array('width:100%');
        }

        $gutter = ($variantParentWidth - $variantWidth) / 2.0;
        if ( $gutter <= 0.0 ) {
            return null;
        }

        $baseParentWidth = null === $baseParentNode ? null : $this->nodeBoxWidth($baseParentNode);
        if ( null === $baseWidth || null === $baseParentWidth || $baseWidth > $baseParentWidth + 1.0 ) {
            return null;
        }

        $placement = 'absolute' === ($baseMap['position'] ?? null) ? 'absolute' : 'fixed';
        if ( 'absolute' !== $placement && in_array((string) ($baseMap['display'] ?? ''), array('flex', 'inline-flex', 'grid', 'inline-grid'), true) ) {
            $placement = 'centered';
        }

        $declarations = $this->sourceMaxWidthDeclarations($baseWidth, $gutter, $placement);
        if ( 'absolute' === $placement ) {
            return array_slice($declarations, 0, 2);
        }

        return $declarations;
    }

    private function cssPixelValue(string $value): ?float
    {
        if ( 1 !== preg_match('/^(-?\d+(?:\.\d+)?)px$/', trim($value), $matches) ) {
            return null;
        }

        return (float) $matches[1];
    }

    /**
     * @param array<string, mixed> $node
     */
    private function nodeBoxWidth(array $node): ?float
    {
        $box = is_array($node['box'] ?? null) ? $node['box'] : array();
        if ( ! isset($box['width']) || ! is_numeric($box['width']) ) {
            return null;
        }

        return (float) $box['width'];
    }
}
