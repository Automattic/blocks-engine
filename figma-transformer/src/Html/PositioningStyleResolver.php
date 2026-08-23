<?php

declare(strict_types=1);

namespace Automattic\BlocksEngine\FigmaTransformer\Html;

/**
 * Resolves node positioning, shell-centering, and local stacking declarations.
 */
final class PositioningStyleResolver
{
    public function __construct(
        private readonly CssPositioningResolver $cssPositioningResolver,
        private readonly CanvasShellResolver $canvasShellResolver,
    ) {
    }

    /**
     * @param array<string, mixed> $node
     * @param array<string, mixed> $box
     * @param array<string, mixed> $layout
     * @param array<string, mixed>|null $parentNode
     * @param array<int, string> $declaredStyles
     */
    public function resolve(array $node, ?array $parentNode, array $box, array $layout, NodeRenderPlan $plan, array $declaredStyles): PositioningStyleDecision
    {
        $styles = array();
        $isDecorativeFlexUnderlay = $plan->decorativeFlexUnderlay;
        $parentFreeformUsesFlow = $plan->parentFreeformUsesFlow;
        $willPositionAbsolute = ($plan->parentIsFreeform && ! $parentFreeformUsesFlow) || ('absolute' === ($layout['positioning'] ?? null) && ! $parentFreeformUsesFlow) || $isDecorativeFlexUnderlay;
        $stackingContextPlan = $plan->stackingContext;
        $effectiveZIndex = isset($stackingContextPlan['z_index']) && is_int($stackingContextPlan['z_index']) ? $stackingContextPlan['z_index'] : null;
        $zIndexReason = isset($stackingContextPlan['z_index_reason']) && is_string($stackingContextPlan['z_index_reason']) ? $stackingContextPlan['z_index_reason'] : null;

        if ( $plan->canvasShell->responsiveCenteredFlowShell && ! $willPositionAbsolute ) {
            $styles[] = 'margin-left:auto';
            $styles[] = 'margin-right:auto';
        }
        if ( ! $willPositionAbsolute && (true === ($stackingContextPlan['manages_local_stacking'] ?? false) || ($parentFreeformUsesFlow && 'FRAME' === $plan->type)) ) {
            $styles[] = 'position:relative';
        }

        if ( true === ($stackingContextPlan['needs_isolation'] ?? false) ) {
            $styles[] = 'isolation:isolate';
        }

        $absolutePositioningDecision = $this->absolutePositioningDecision($node, $parentNode, $box, $layout, $plan->canvasShell, $isDecorativeFlexUnderlay, $parentFreeformUsesFlow, $plan->parentIsFreeform);
        if ( null !== $absolutePositioningDecision ) {
            foreach ( $absolutePositioningDecision->declarations as $style ) {
                $styles[] = $style;
            }
        }

        if ( $isDecorativeFlexUnderlay ) {
            if ( null !== $effectiveZIndex && ! $this->stylesDeclareProperty(array_merge($declaredStyles, $styles), 'z-index') ) {
                $styles[] = 'z-index:' . (string) $effectiveZIndex;
            }
            $styles[] = 'pointer-events:none';
        }

        if ( null !== $parentNode && ! $willPositionAbsolute && null === $effectiveZIndex && $plan->parentHasDecorativeFlexUnderlay ) {
            $styles[] = 'position:relative';
            $styles[] = 'z-index:1';
        }

        if ( null !== $effectiveZIndex && ! $willPositionAbsolute && ! $this->stylesDeclareProperty(array_merge($declaredStyles, $styles), 'position') ) {
            $styles[] = 'position:relative';
        }

        if ( null !== $effectiveZIndex && ! $this->stylesDeclareProperty(array_merge($declaredStyles, $styles), 'z-index') ) {
            $styles[] = 'z-index:' . (string) $effectiveZIndex;
        }

        return new PositioningStyleDecision($styles, $willPositionAbsolute, $isDecorativeFlexUnderlay, $zIndexReason, $absolutePositioningDecision);
    }

    /**
     * @param array<string, mixed> $node
     * @param array<string, mixed>|null $parentNode
     * @param array<string, mixed> $box
     * @param array<string, mixed> $layout
     */
    private function absolutePositioningDecision(array $node, ?array $parentNode, array $box, array $layout, CanvasShellDecision $canvasShell, bool $isDecorativeFlexUnderlay, bool $parentFreeformUsesFlow, bool $parentIsFreeform): ?AbsolutePositioningDecision
    {
        $reasonCode = '';
        if ( $isDecorativeFlexUnderlay ) {
            $reasonCode = 'decorative_flex_underlay_absolute';
        } elseif ( null !== $parentNode && $parentIsFreeform && ! $parentFreeformUsesFlow ) {
            $reasonCode = 'freeform_parent_absolute_child';
        } elseif ( 'absolute' === ($layout['positioning'] ?? null) && ! $parentFreeformUsesFlow ) {
            $reasonCode = 'explicit_absolute_positioning';
        }

        if ( '' === $reasonCode ) {
            return null;
        }

        $declarations = array('position:absolute');
        $suppressedFullBleedHorizontalOffsets = false;
        foreach ( $this->cssPositioningResolver->styles($box, $layout, $parentNode, $node, $canvasShell->centeredWithinParentFluidCanvas) as $style ) {
            if ( $canvasShell->fullBleedCanvasChild && $this->isHorizontalOffsetStyle($style) ) {
                $suppressedFullBleedHorizontalOffsets = true;
                continue;
            }
            $declarations[] = $style;
        }
        foreach ( $this->canvasShellResolver->fullBleedViewportBreakoutDecision($canvasShell)['declarations'] as $style ) {
            $declarations[] = $style;
        }

        return new AbsolutePositioningDecision($reasonCode, $declarations, $suppressedFullBleedHorizontalOffsets);
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

    private function isHorizontalOffsetStyle(string $style): bool
    {
        return str_starts_with($style, 'left:') || str_starts_with($style, 'right:') || str_starts_with($style, 'margin-left:') || str_starts_with($style, 'margin-right:');
    }

}
