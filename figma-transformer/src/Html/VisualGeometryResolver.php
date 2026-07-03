<?php

declare(strict_types=1);

namespace Automattic\BlocksEngine\FigmaTransformer\Html;

/**
 * Resolves visual-box geometry that must stay consistent between emission and diagnostics.
 */
final class VisualGeometryResolver
{
    public function __construct(
        private readonly LayoutIntentClassifier $layoutIntentClassifier,
    ) {
    }

    /**
     * @param array<string, mixed> $node
     * @param array<string, mixed> $parentNode
     */
    public function isFullyClippedDecorativeChild(array $node, array $parentNode): bool
    {
        $parentLayout = is_array($parentNode['layout'] ?? null) ? $parentNode['layout'] : array();
        if ( true !== ($parentLayout['clips_content'] ?? false) || ! $this->layoutIntentClassifier->isClippableDecorativeVisualNode($node) ) {
            return false;
        }

        return null === $this->childVisibleRectInParent($node, $parentNode);
    }

    /**
     * @param array<string, mixed> $node
     * @param array<string, mixed> $parentNode
     */
    public function isFullyOffCanvasChild(array $node, array $parentNode): bool
    {
        $layout = is_array($node['layout'] ?? null) ? $node['layout'] : array();
        if ( 'absolute' === ($layout['positioning'] ?? null) ) {
            return false;
        }

        return null === $this->childVisibleRectInParent($node, $parentNode, true);
    }

    /**
     * @param array<int, mixed> $children
     * @param array<string, mixed> $parentNode
     */
    public function hasOffCanvasChildCluster(array $children, array $parentNode, int $threshold = 2): bool
    {
        $count = 0;
        foreach ( $children as $child ) {
            if ( is_array($child) && $this->isFullyOffCanvasChild($child, $parentNode) ) {
                ++$count;
                if ( $count >= $threshold ) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @param array{x: float|int, y: float|int, width: float|int, height: float|int} $rect
     * @param array{x: float|int, y: float|int, width: float|int, height: float|int} $clipRect
     * @return array{x: float, y: float, width: float, height: float}|null
     */
    public function rectIntersection(array $rect, array $clipRect): ?array
    {
        $left = max($rect['x'], $clipRect['x']);
        $top = max($rect['y'], $clipRect['y']);
        $right = min($rect['x'] + $rect['width'], $clipRect['x'] + $clipRect['width']);
        $bottom = min($rect['y'] + $rect['height'], $clipRect['y'] + $clipRect['height']);
        if ( $right <= $left || $bottom <= $top ) {
            return null;
        }

        return array('x' => $left, 'y' => $top, 'width' => $right - $left, 'height' => $bottom - $top);
    }

    /**
     * @return array{x: float, y: float, width: float, height: float}
     */
    public function transformedRect(float $width, float $height, array $matrix): array
    {
        [$a, $b, $c, $d, $e, $f] = $matrix;
        $points = array(array(0.0, 0.0), array($width, 0.0), array(0.0, $height), array($width, $height));
        $xs = array();
        $ys = array();
        foreach ( $points as $point ) {
            [$localX, $localY] = $point;
            $xs[] = ($a * $localX) + ($c * $localY) + $e;
            $ys[] = ($b * $localX) + ($d * $localY) + $f;
        }

        $left = min($xs);
        $top = min($ys);
        $right = max($xs);
        $bottom = max($ys);

        return array('x' => $left, 'y' => $top, 'width' => $right - $left, 'height' => $bottom - $top);
    }

    /**
     * @param array<int|string, mixed>|null $transform
     * @return array{0: float, 1: float, 2: float, 3: float, 4: float, 5: float}|null
     */
    public function cssTransformMatrixValues(?array $transform): ?array
    {
        if ( null === $transform ) {
            return null;
        }

        if ( isset($transform['m00'], $transform['m01'], $transform['m02'], $transform['m10'], $transform['m11'], $transform['m12']) ) {
            if ( 0.00001 > abs((float) $transform['m00'] - 1.0) && 0.00001 > abs((float) $transform['m01']) && 0.00001 > abs((float) $transform['m10']) && 0.00001 > abs((float) $transform['m11'] - 1.0) ) {
                return null;
            }
            $values = array($transform['m00'], $transform['m10'], $transform['m01'], $transform['m11'], 0, 0);
        } elseif ( 2 === count($transform) && is_array($transform[0] ?? null) && is_array($transform[1] ?? null) ) {
            $values = array($transform[0][0] ?? null, $transform[1][0] ?? null, $transform[0][1] ?? null, $transform[1][1] ?? null, $transform[0][2] ?? null, $transform[1][2] ?? null);
        } else {
            return null;
        }

        foreach ( $values as $value ) {
            if ( ! is_numeric($value) ) {
                return null;
            }
        }

        return array_map(static fn (mixed $value): float => (float) $value, $values);
    }

    /**
     * @param array<string, mixed> $node
     * @param array<string, mixed> $parentNode
     */
    private function childVisibleRectInParent(array $node, array $parentNode, bool $requirePositiveParentAndChild = false): ?array
    {
        $parentBox = is_array($parentNode['box'] ?? null) ? $parentNode['box'] : array();
        $box = is_array($node['box'] ?? null) ? $node['box'] : array();
        if ( ! isset($parentBox['width'], $parentBox['height'], $box['width'], $box['height']) || ! is_numeric($parentBox['width']) || ! is_numeric($parentBox['height']) || ! is_numeric($box['width']) || ! is_numeric($box['height']) ) {
            return array();
        }

        if ( $requirePositiveParentAndChild && ((float) $box['width'] <= 0.0 || (float) $box['height'] <= 0.0 || (float) $parentBox['width'] <= 0.0 || (float) $parentBox['height'] <= 0.0) ) {
            return array();
        }

        $left = $this->layoutIntentClassifier->positionOffset($box, $parentBox, 'x', $parentNode);
        $top = $this->layoutIntentClassifier->positionOffset($box, $parentBox, 'y', $parentNode);
        if ( null === $left || null === $top ) {
            return array();
        }

        $parentRect = array('x' => 0.0, 'y' => 0.0, 'width' => (float) $parentBox['width'], 'height' => (float) $parentBox['height']);
        $childRect = array('x' => $left, 'y' => $top, 'width' => (float) $box['width'], 'height' => (float) $box['height']);

        return $this->rectIntersection($parentRect, $childRect);
    }
}
