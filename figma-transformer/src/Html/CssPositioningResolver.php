<?php

declare(strict_types=1);

namespace Automattic\BlocksEngine\FigmaTransformer\Html;

/**
 * Resolves CSS declarations for nodes that leave normal flow.
 */
final class CssPositioningResolver
{
    /**
     * @param callable(float): string $numberFormatter
     */
    public function __construct(
        private readonly LayoutIntentClassifier $layoutIntentClassifier,
        private readonly mixed $numberFormatter,
    ) {
    }

    /**
     * @param array<string, mixed> $box
     * @param array<string, mixed> $layout
     * @param array<string, mixed>|null $parentNode
     * @param array<string, mixed>|null $node
     * @return array<int, string>
     */
    public function styles(array $box, array $layout, ?array $parentNode, ?array $node = null): array
    {
        $styles = array();
        $parentBox = is_array($parentNode['box'] ?? null) ? $parentNode['box'] : array();
        $left = $this->layoutIntentClassifier->positionOffset($box, $parentBox, 'x', $parentNode);
        $top = $this->layoutIntentClassifier->positionOffset($box, $parentBox, 'y', $parentNode);
        if ( null !== $node && $this->hasComponentCloneGeometry($node) ) {
            $left = $this->componentCloneSourceOffset($node, $box, $parentBox, 'x', $left);
            $top = $this->componentCloneSourceOffset($node, $box, $parentBox, 'y', $top);
        }
        $constraints = is_array($layout['constraints'] ?? null) ? $layout['constraints'] : array();

        foreach ( $this->axisConstraintStyles('horizontal', is_scalar($constraints['horizontal'] ?? null) ? (string) $constraints['horizontal'] : null, $left, $parentBox, $box) as $style ) {
            $styles[] = $style;
        }
        foreach ( $this->axisConstraintStyles('vertical', is_scalar($constraints['vertical'] ?? null) ? (string) $constraints['vertical'] : null, $top, $parentBox, $box) as $style ) {
            $styles[] = $style;
        }

        return $styles;
    }

    /**
     * @param array<string, mixed> $node
     */
    private function hasComponentCloneGeometry(array $node): bool
    {
        if ( true === ($node['_component_source_clone_geometry'] ?? false) ) {
            return true;
        }

        foreach ( array('box', 'figma_box') as $boxKey ) {
            $box = is_array($node[$boxKey] ?? null) ? $node[$boxKey] : array();
            if ( 'component_source_clone' === ($box['geometry_semantics'] ?? null) ) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $node
     * @param array<string, mixed> $box
     * @param array<string, mixed> $parentBox
     */
    private function componentCloneSourceOffset(array $node, array $box, array $parentBox, string $dimension, ?float $offset): ?float
    {
        if ( null === $offset ) {
            return null;
        }

        if ( 'local' === ($box['coordinate_space'] ?? null) ) {
            return $offset;
        }

        $sizeKey = 'x' === $dimension ? 'width' : 'height';
        if ( ! isset($parentBox[$sizeKey], $box[$sizeKey]) || ! is_numeric($parentBox[$sizeKey]) || ! is_numeric($box[$sizeKey]) ) {
            return $offset;
        }

        $parentSize = (float) $parentBox[$sizeKey];
        $boxSize = (float) $box[$sizeKey];
        if ( $parentSize <= 0.0 || $boxSize <= 0.0 || ($offset >= -0.5 && $offset + $boxSize <= $parentSize + 0.5) ) {
            return $offset;
        }

        $sourceBox = is_array($node['_component_source_clone_source_box'] ?? null) ? $node['_component_source_clone_source_box'] : array();
        if ( isset($sourceBox[$dimension]) && is_numeric($sourceBox[$dimension]) ) {
            return (float) $sourceBox[$dimension];
        }

        return 0.0;
    }

    /**
     * Resolve the absolute-position CSS for a single axis from its Figma pin
     * constraint. The near edge (left/top) is the default; LEFT_RIGHT/TOP_BOTTOM
     * pin both edges, RIGHT/BOTTOM pin only the far edge, and CENTER holds a fixed
     * offset from the parent center without relying on `transform` (which the
     * emitter reserves for the node's own matrix). SCALE is percentage-based and
     * has no clean pixel translation, so it falls back to the deterministic near
     * pin instead of emitting a wrong guess.
     *
     * @param array<string, mixed> $parentBox
     * @param array<string, mixed> $box
     * @return array<int, string>
     */
    private function axisConstraintStyles(string $axis, ?string $constraint, ?float $offset, array $parentBox, array $box): array
    {
        $isHorizontal = 'horizontal' === $axis;
        $startProp = $isHorizontal ? 'left' : 'top';
        $endProp = $isHorizontal ? 'right' : 'bottom';
        $sizeKey = $isHorizontal ? 'width' : 'height';
        $bothPin = $isHorizontal ? 'LEFT_RIGHT' : 'TOP_BOTTOM';
        $farPin = $isHorizontal ? 'RIGHT' : 'BOTTOM';
        $parentSize = isset($parentBox[$sizeKey]) && is_numeric($parentBox[$sizeKey]) ? (float) $parentBox[$sizeKey] : null;
        $boxSize = isset($box[$sizeKey]) && is_numeric($box[$sizeKey]) ? (float) $box[$sizeKey] : null;
        $constraint = null === $constraint ? null : strtoupper($constraint);

        $styles = array();

        // Far-edge-only pin (REST RIGHT/BOTTOM, Kiwi MAX): anchor to the trailing
        // edge and drop the leading offset so the node stays glued on resize.
        if ( $farPin === $constraint && null !== $offset && null !== $parentSize && null !== $boxSize ) {
            $styles[] = $endProp . ':' . $this->number($parentSize - $offset - $boxSize) . 'px';
            return $styles;
        }

        // Center pin: keep the child center at a constant offset from the parent
        // center. Emit the leading edge directly so node transforms remain free.
        if ( 'CENTER' === $constraint && null !== $offset && null !== $parentSize ) {
            $halfBoxSize = null !== $boxSize ? $boxSize / 2.0 : 0.0;
            $centerDelta = $offset + $halfBoxSize - ( $parentSize / 2.0 );
            $leadingDelta = $centerDelta - $halfBoxSize;
            $sign = $leadingDelta < 0 ? '-' : '+';
            $styles[] = $startProp . ':calc(50% ' . $sign . ' ' . $this->number(abs($leadingDelta)) . 'px)';
            return $styles;
        }

        // Near-edge pin (LEFT/TOP/default, also SCALE fallback) plus an optional
        // far-edge pin for the both-side stretch constraint.
        if ( null !== $offset ) {
            $styles[] = $startProp . ':' . $this->number($offset) . 'px';
        }
        if ( $bothPin === $constraint && null !== $offset && null !== $parentSize && null !== $boxSize ) {
            $styles[] = $endProp . ':' . $this->number($parentSize - $offset - $boxSize) . 'px';
        }

        return $styles;
    }

    private function number(float $value): string
    {
        return ($this->numberFormatter)($value);
    }
}
