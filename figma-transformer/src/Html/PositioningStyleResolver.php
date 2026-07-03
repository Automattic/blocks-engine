<?php

declare(strict_types=1);

namespace Automattic\BlocksEngine\FigmaTransformer\Html;

/**
 * Resolves node positioning, shell-centering, and local stacking declarations.
 */
final class PositioningStyleResolver
{
    /**
     * @param callable(array<string, mixed>): bool $isFreeformContainer
     * @param callable(array<string, mixed>): bool $freeformContainerShouldUseFlow
     * @param callable(array<string, mixed>, array<string, mixed>): bool $isDecorativeFlexUnderlay
     * @param callable(array<string, mixed>): bool $hasDecorativeFlexUnderlayChild
     */
    public function __construct(
        private readonly LayoutIntentClassifier $layoutIntentClassifier,
        private readonly CssPositioningResolver $cssPositioningResolver,
        private readonly CanvasShellResolver $canvasShellResolver,
        private readonly mixed $isFreeformContainer,
        private readonly mixed $freeformContainerShouldUseFlow,
        private readonly mixed $isDecorativeFlexUnderlay,
        private readonly mixed $hasDecorativeFlexUnderlayChild,
    ) {
    }

    /**
     * @param array<string, mixed> $node
     * @param array<string, mixed> $box
     * @param array<string, mixed> $layout
     * @param array<string, mixed>|null $parentNode
     * @param array<int, string> $declaredStyles
     */
    public function resolve(array $node, string $type, ?array $parentNode, array $box, array $layout, CanvasShellDecision $canvasShell, array $declaredStyles): PositioningStyleDecision
    {
        $styles = array();
        $isDecorativeFlexUnderlay = null !== $parentNode && $this->isDecorativeFlexUnderlay($node, $parentNode);
        $parentFreeformUsesFlow = null !== $parentNode && $this->freeformContainerShouldUseFlow($parentNode);
        $willPositionAbsolute = (null !== $parentNode && $this->isFreeformContainer($parentNode) && ! $parentFreeformUsesFlow) || 'absolute' === ($layout['positioning'] ?? null) || $isDecorativeFlexUnderlay;
        $layerStackPlan = null !== $parentNode ? $this->layoutIntentClassifier->siblingLayerStackPlan($node, $parentNode) : array('z_index' => null);
        $overlapZIndex = isset($layerStackPlan['z_index']) && is_int($layerStackPlan['z_index']) ? $layerStackPlan['z_index'] : null;
        $managesLocalStacking = $this->layoutIntentClassifier->managesLocalStacking($node);
        $needsLocalStackIsolation = $this->layoutIntentClassifier->needsLocalStackIsolation($node);

        if ( $canvasShell->responsiveCenteredFlowShell && ! $willPositionAbsolute ) {
            $styles[] = 'margin-left:auto';
            $styles[] = 'margin-right:auto';
        }
        if ( ! $willPositionAbsolute && ($managesLocalStacking || ($parentFreeformUsesFlow && 'FRAME' === $type)) ) {
            $styles[] = 'position:relative';
        }

        if ( $needsLocalStackIsolation ) {
            $styles[] = 'isolation:isolate';
        }

        if ( $isDecorativeFlexUnderlay ) {
            $styles[] = 'position:absolute';
            foreach ( $this->cssPositioningResolver->styles($box, $layout, $parentNode, $node, $canvasShell->centeredWithinParentFluidCanvas) as $style ) {
                $styles[] = $style;
            }
            $styles[] = 'z-index:0';
            $styles[] = 'pointer-events:none';
        } elseif ( null !== $parentNode && $this->isFreeformContainer($parentNode) && ! $parentFreeformUsesFlow ) {
            $styles[] = 'position:absolute';
            foreach ( $this->cssPositioningResolver->styles($box, $layout, $parentNode, $node, $canvasShell->centeredWithinParentFluidCanvas) as $style ) {
                $styles[] = $style;
            }
            foreach ( $this->canvasShellResolver->fullBleedViewportBreakoutDecision($canvasShell)['declarations'] as $style ) {
                $styles[] = $style;
            }
        } elseif ( 'absolute' === ($layout['positioning'] ?? null) ) {
            $styles[] = 'position:absolute';
            foreach ( $this->cssPositioningResolver->styles($box, $layout, $parentNode, $node, $canvasShell->centeredWithinParentFluidCanvas) as $style ) {
                $styles[] = $style;
            }
            foreach ( $this->canvasShellResolver->fullBleedViewportBreakoutDecision($canvasShell)['declarations'] as $style ) {
                $styles[] = $style;
            }
        }

        if ( null !== $parentNode && ! $willPositionAbsolute && $this->hasDecorativeFlexUnderlayChild($parentNode) ) {
            $styles[] = 'position:relative';
            $styles[] = 'z-index:1';
        }

        if ( null !== $overlapZIndex && ! $willPositionAbsolute && ! $this->stylesDeclareProperty(array_merge($declaredStyles, $styles), 'position') ) {
            $styles[] = 'position:relative';
        }

        if ( $this->isFiniteNumeric($layout['z_index'] ?? null) && ! $this->stylesDeclareProperty(array_merge($declaredStyles, $styles), 'z-index') ) {
            $styles[] = 'z-index:' . (string) (int) $layout['z_index'];
        } elseif ( null !== $parentNode && ! $this->stylesDeclareProperty(array_merge($declaredStyles, $styles), 'z-index') && null !== $overlapZIndex ) {
            $styles[] = 'z-index:' . (string) $overlapZIndex;
        }

        return new PositioningStyleDecision($styles, $willPositionAbsolute, $isDecorativeFlexUnderlay);
    }

    /**
     * @param array<int, string> $styles
     */
    private function stylesDeclareProperty(array $styles, string $property): bool
    {
        $prefix = $property . ':';
        foreach ( $styles as $style ) {
            if ( str_starts_with($style, $prefix) ) {
                return true;
            }
        }

        return false;
    }

    private function isFiniteNumeric(mixed $value): bool
    {
        return is_int($value) || is_float($value) || (is_string($value) && is_numeric($value));
    }

    /**
     * @param array<string, mixed> $node
     */
    private function isFreeformContainer(array $node): bool
    {
        return ($this->isFreeformContainer)($node);
    }

    /**
     * @param array<string, mixed> $node
     */
    private function freeformContainerShouldUseFlow(array $node): bool
    {
        return ($this->freeformContainerShouldUseFlow)($node);
    }

    /**
     * @param array<string, mixed> $node
     * @param array<string, mixed> $parentNode
     */
    private function isDecorativeFlexUnderlay(array $node, array $parentNode): bool
    {
        return ($this->isDecorativeFlexUnderlay)($node, $parentNode);
    }

    /**
     * @param array<string, mixed> $node
     */
    private function hasDecorativeFlexUnderlayChild(array $node): bool
    {
        return ($this->hasDecorativeFlexUnderlayChild)($node);
    }
}
