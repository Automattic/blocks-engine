<?php

declare(strict_types=1);

namespace Automattic\BlocksEngine\FigmaTransformer\Html;

/**
 * Resolves the root/page canvas shell boundary before low-level CSS emission.
 */
final class CanvasShellResolver
{
    /**
     * @param callable(array<string, mixed>): bool $isFreeformContainer
     * @param callable(array<string, mixed>): bool $freeformContainerShouldUseFlow
     * @param callable(array<string, mixed>): bool $hasAbsoluteChild
     * @param callable(array<string, mixed>): bool $hasDecorativeFlexUnderlayChild
     */
    public function __construct(
        private readonly LayoutFrameRoleClassifier $layoutFrameRoleClassifier,
        private readonly mixed $isFreeformContainer,
        private readonly mixed $freeformContainerShouldUseFlow,
        private readonly mixed $hasAbsoluteChild,
        private readonly mixed $hasDecorativeFlexUnderlayChild,
    ) {
    }

    /**
     * @param array<string, mixed> $node
     * @param array<string, mixed>|null $parentNode
     * @param array<string, mixed>|null $grandParentNode
     */
    public function resolve(array $node, ?array $parentNode, ?array $grandParentNode): CanvasShellDecision
    {
        $box = is_array($node['box'] ?? null) ? $node['box'] : array();
        $layout = is_array($node['layout'] ?? null) ? $node['layout'] : array();
        $parentRendersFluidCanvas = null !== $parentNode && $this->nodeRendersFluidCanvas($parentNode, $grandParentNode);
        $parentUsesFluidCanvasCoordinates = null !== $parentNode && $this->nodeUsesFluidCanvasCoordinates($parentNode, $grandParentNode);
        $frameWidthRole = $this->layoutFrameRoleClassifier->frameWidthRole($box, $layout, $parentNode);
        $canvasChildRole = null !== $parentNode
            ? $this->layoutFrameRoleClassifier->canvasChildRole($box, $layout, $parentNode, $parentUsesFluidCanvasCoordinates, $this->isFreeformContainer($parentNode))
            : LayoutFrameRoleClassifier::ROLE_INTRINSIC;
        $fullBleedCanvasChild = LayoutFrameRoleClassifier::ROLE_FULL_BLEED_CANVAS_CHILD === $canvasChildRole;
        $centeredWithinParentFluidCanvas = LayoutFrameRoleClassifier::ROLE_CENTERED_SHELL === $canvasChildRole;
        $responsiveCenteredFlowWidth = $this->centeredShellShouldUseResponsiveFlowWidth($layout, $parentNode);
        $responsiveCenteredFlowShell = $centeredWithinParentFluidCanvas || (
            $parentRendersFluidCanvas
            && null !== $parentNode
            && $responsiveCenteredFlowWidth
            && $this->layoutFrameRoleClassifier->isCenteredCanvasShell($box, $parentNode)
        );
        $fluidStretchCanvasChild = null !== $parentNode
            && $parentUsesFluidCanvasCoordinates
            && $this->layoutFrameRoleClassifier->isFluidStretchAbsoluteChild($box, $layout, $parentNode, $this->isFreeformContainer($parentNode));

        return new CanvasShellDecision(
            $frameWidthRole,
            $canvasChildRole,
            $parentRendersFluidCanvas,
            $parentUsesFluidCanvasCoordinates,
            $fullBleedCanvasChild,
            $centeredWithinParentFluidCanvas,
            $responsiveCenteredFlowShell,
            $fluidStretchCanvasChild,
            $responsiveCenteredFlowWidth,
        );
    }

    /**
     * @param array<string, mixed> $layout
     */
    public function nodeShouldUseFlowHeight(string $type, array $layout, CanvasShellDecision $decision): bool
    {
        if ( ! in_array($type, array('FRAME', 'COMPONENT', 'INSTANCE', 'SECTION'), true) ) {
            return false;
        }

        if ( $this->layoutFrameRoleClassifier->roleUsesFlowHeight($decision->frameWidthRole, $layout) ) {
            return true;
        }

        return $this->layoutFrameRoleClassifier->roleUsesFlowHeight($decision->canvasChildRole, $layout);
    }

    /**
     * @return array<int, string>
     */
    public function fullBleedViewportBreakoutStyles(CanvasShellDecision $decision): array
    {
        if ( ! $decision->fullBleedCanvasChild ) {
            return array();
        }

        return array(
            'left:50%',
            'margin-left:-50vw',
        );
    }

    /**
     * @param array<string, mixed> $layout
     * @param array<string, mixed>|null $parentNode
     */
    private function centeredShellShouldUseResponsiveFlowWidth(array $layout, ?array $parentNode): bool
    {
        if ( null === $parentNode || 'absolute' === ($layout['positioning'] ?? null) ) {
            return false;
        }

        return ! $this->isFreeformContainer($parentNode) || $this->freeformContainerShouldUseFlow($parentNode);
    }

    /**
     * @param array<string, mixed> $node
     * @param array<string, mixed>|null $parentNode
     */
    private function nodeRendersFluidCanvas(array $node, ?array $parentNode): bool
    {
        $box = is_array($node['box'] ?? null) ? $node['box'] : array();
        $layout = is_array($node['layout'] ?? null) ? $node['layout'] : array();
        return $this->layoutFrameRoleClassifier->isFluidPageWidth($box, $layout, $parentNode);
    }

    /**
     * @param array<string, mixed> $node
     * @param array<string, mixed>|null $parentNode
     */
    private function nodeUsesFluidCanvasCoordinates(array $node, ?array $parentNode): bool
    {
        if ( ! $this->nodeRendersFluidCanvas($node, $parentNode) ) {
            return false;
        }

        $layout = is_array($node['layout'] ?? null) ? $node['layout'] : array();
        if ( 'FILL' === strtoupper((string) ($layout['sizing_horizontal'] ?? '')) ) {
            return true;
        }

        $type = strtoupper((string) ($node['type'] ?? ''));
        if ( 'COMPONENT' === $type || null === $parentNode ) {
            return false;
        }

        if ( 'INSTANCE' === $type ) {
            return $this->isFreeformContainer($node);
        }

        return $this->hasAbsoluteChild($node) || $this->hasDecorativeFlexUnderlayChild($node) || $this->isFreeformContainer($node);
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
     */
    private function hasAbsoluteChild(array $node): bool
    {
        return ($this->hasAbsoluteChild)($node);
    }

    /**
     * @param array<string, mixed> $node
     */
    private function hasDecorativeFlexUnderlayChild(array $node): bool
    {
        return ($this->hasDecorativeFlexUnderlayChild)($node);
    }
}
