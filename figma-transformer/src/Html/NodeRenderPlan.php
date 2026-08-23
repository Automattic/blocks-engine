<?php

declare(strict_types=1);

namespace Automattic\BlocksEngine\FigmaTransformer\Html;

/**
 * Immutable layout facts shared by node HTML and CSS emission.
 */
final class NodeRenderPlan
{
    /**
     * @param array<int, mixed> $children
     * @param array<string, mixed>|null $layoutIntent
     * @param array<string, mixed> $stackingContext
     */
    public function __construct(
        public readonly string $type,
        public readonly array $children,
        public readonly ?array $layoutIntent,
        public readonly CanvasShellDecision $canvasShell,
        public readonly array $stackingContext,
        public readonly bool $parentIsFreeform,
        public readonly bool $parentFreeformUsesFlow,
        public readonly bool $decorativeFlexUnderlay,
        public readonly bool $parentHasDecorativeFlexUnderlay,
    ) {
    }
}
