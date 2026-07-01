<?php

declare(strict_types=1);

namespace Automattic\BlocksEngine\FigmaTransformer\Html;

/**
 * Classifies generic layout intent shared by HTML CSS emission and visual maps.
 */
final class LayoutIntentClassifier
{
    /**
     * @param array<string, array<string, mixed>> $assetsById
     */
    public function __construct(
        private readonly array $assetsById = array()
    ) {
    }

    /**
     * @param array<string, mixed> $node
     */
    public function isFreeformContainer(array $node): bool
    {
        if ( true === ($node['layout']['freeform'] ?? false) ) {
            return true;
        }

        $children = $this->nodeList($node);
        if ( true === ($node['figma_component']['resolved'] ?? false) && ! empty($children) && empty($node['layout']['display'] ?? null) ) {
            return true;
        }

        if ( empty($node['layout']['display'] ?? null) && $this->hasPositionedSourceChild($node, $children) ) {
            return true;
        }

        if ( 1 !== count($children) || ! is_array($children[0]) ) {
            return false;
        }

        $box = is_array($node['box'] ?? null) ? $node['box'] : array();
        $childBox = is_array($children[0]['box'] ?? null) ? $children[0]['box'] : array();
        if ( ! isset($box['width'], $box['height'], $childBox['width'], $childBox['height']) || ! is_numeric($box['width']) || ! is_numeric($box['height']) || ! is_numeric($childBox['width']) || ! is_numeric($childBox['height']) ) {
            return false;
        }

        $layout = is_array($node['layout'] ?? null) ? $node['layout'] : array();
        if ( ! empty($layout['display'] ?? null) ) {
            if ( 'flex' !== ($layout['display'] ?? null) ) {
                return false;
            }
            $mainAxis = 'row' === ($layout['flex_direction'] ?? null) ? 'width' : 'height';
            return (float) $childBox[$mainAxis] > (float) $box[$mainAxis];
        }

        return (float) $childBox['width'] > (float) $box['width'] || (float) $childBox['height'] > (float) $box['height'];
    }

    /**
     * @param array<string, mixed> $node
     */
    public function hasAbsoluteChild(array $node): bool
    {
        foreach ( $this->nodeList($node) as $child ) {
            if ( is_array($child) && $this->isAbsoluteChild($child) ) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $node
     */
    public function isAbsoluteChild(array $node): bool
    {
        return 'absolute' === ($node['layout']['positioning'] ?? null);
    }

    /**
     * @param array<string, mixed> $node
     */
    public function hasDecorativeFlexUnderlayChild(array $node): bool
    {
        foreach ( $this->nodeList($node) as $child ) {
            if ( is_array($child) && $this->isDecorativeFlexUnderlay($child, $node) ) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $node
     * @param array<string, mixed> $parentNode
     */
    public function isDecorativeFlexUnderlay(array $node, array $parentNode): bool
    {
        $parentLayout = is_array($parentNode['layout'] ?? null) ? $parentNode['layout'] : array();
        if ( ! $this->isFlexDisplayLayout($parentLayout) ) {
            return false;
        }

        if ( ! $this->isDecorativeUnderlayVisualCandidate($node) || ! $this->parentHasTextOutsideNode($parentNode, $node) ) {
            return false;
        }

        return $this->isOversizedAgainstParent($node, $parentNode) || $this->isAbsoluteBackgroundBleed($node, $parentNode, $parentLayout);
    }

    /**
     * @param array<string, mixed> $layout
     * @param array<string, mixed>|null $parentNode
     */
    public function fillsParentFlexMainAxis(array $layout, ?array $parentNode): bool
    {
        $parentLayout = is_array($parentNode['layout'] ?? null) ? $parentNode['layout'] : array();
        $parentMainAxisSizingKey = 'column' === ($parentLayout['flex_direction'] ?? null) ? 'sizing_vertical' : 'sizing_horizontal';
        return 'FILL' === ($layout[$parentMainAxisSizingKey] ?? null);
    }

    /**
     * @param array<string, mixed> $box
     * @param array<string, mixed> $parentBox
     */
    public function positionOffset(array $box, array $parentBox, string $dimension, ?array $parentNode = null): ?float
    {
        if ( ! isset($box[$dimension]) || ! is_numeric($box[$dimension]) ) {
            return null;
        }

        if ( 'local' === ($box['coordinate_space'] ?? null) ) {
            return (float) $box[$dimension];
        }

        if ( null !== $parentNode && (! isset($parentBox[$dimension]) ? $this->shouldInferMissingParentOrigin($parentBox, $parentNode, $dimension) : $this->shouldInferRootCanvasOrigin($parentBox, $parentNode, $dimension)) ) {
            $origin = $this->inferredContainingBlockOrigin($parentNode, $dimension);
            if ( null !== $origin ) {
                return (float) $box[$dimension] - $origin;
            }
        }

        return $this->relativeOffset($box, $parentBox, $dimension);
    }

    /**
     * @param array<string, mixed> $box
     * @param array<string, mixed> $parentBox
     */
    public function relativeOffset(array $box, array $parentBox, string $dimension): ?float
    {
        if ( ! isset($box[$dimension]) || ! is_numeric($box[$dimension]) ) {
            return null;
        }

        $offset = (float) $box[$dimension];
        if ( isset($parentBox[$dimension]) && is_numeric($parentBox[$dimension]) ) {
            $offset -= (float) $parentBox[$dimension];
        }

        return $offset;
    }

    /**
     * @param array<string, mixed> $node
     */
    public function isClippableDecorativeVisualNode(array $node): bool
    {
        return $this->isDecorativeUnderlayVisualCandidate($node);
    }

    /**
     * @param array<string, mixed> $layout
     */
    private function isFlexDisplayLayout(array $layout): bool
    {
        return in_array((string) ($layout['display'] ?? ''), array('flex', 'inline-flex'), true);
    }

    /**
     * @param array<string, mixed> $node
     */
    private function isDecorativeUnderlayVisualCandidate(array $node): bool
    {
        return ! $this->treeHasText($node) && ! $this->treeHasImageReference($node) && $this->treeIsVectorShapeOnly($node);
    }

    /**
     * @param array<string, mixed> $node
     * @param array<int, mixed> $children
     */
    private function hasPositionedSourceChild(array $node, array $children): bool
    {
        $type = strtoupper((string) ($node['type'] ?? ''));
        if ( ! in_array($type, array('FRAME', 'GROUP', 'COMPONENT', 'INSTANCE', 'SECTION'), true) ) {
            return false;
        }

        $box = is_array($node['box'] ?? null) ? $node['box'] : array();
        foreach ( $children as $child ) {
            if ( ! is_array($child) ) {
                continue;
            }

            $childBox = is_array($child['box'] ?? null) ? $child['box'] : array();
            $left = $this->positionOffset($childBox, $box, 'x', $node);
            $top = $this->positionOffset($childBox, $box, 'y', $node);
            if ( (null !== $left && abs($left) > 0.5) || (null !== $top && abs($top) > 0.5) ) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $parentBox
     * @param array<string, mixed> $parentNode
     */
    private function shouldInferRootCanvasOrigin(array $parentBox, array $parentNode, string $dimension): bool
    {
        if ( ! isset($parentBox[$dimension]) || ! is_numeric($parentBox[$dimension]) ) {
            return false;
        }

        if ( ! empty($parentNode['_parent_id']) ) {
            return false;
        }

        $origin = $this->inferredContainingBlockOrigin($parentNode, $dimension);
        if ( null === $origin ) {
            return false;
        }

        $parentOrigin = (float) $parentBox[$dimension];
        if ( 0.0 === $parentOrigin ) {
            return $origin < 0.0 || $this->hasRootCanvasOriginMismatch($parentBox, $parentNode);
        }

        return ($origin < 0.0 && ($parentOrigin - $origin) >= 100.0)
            || $this->hasRootCanvasOriginMismatch($parentBox, $parentNode);
    }

    /**
     * @param array<string, mixed> $parentBox
     * @param array<string, mixed> $parentNode
     */
    private function shouldInferMissingParentOrigin(array $parentBox, array $parentNode, string $dimension): bool
    {
        $origin = $this->inferredContainingBlockOrigin($parentNode, $dimension);
        if ( null === $origin ) {
            return false;
        }

        foreach ( array('x' => 'width', 'y' => 'height') as $originDimension => $sizeKey ) {
            $origin = $this->inferredContainingBlockOrigin($parentNode, $originDimension);
            if ( null === $origin ) {
                continue;
            }

            $parentSize = isset($parentBox[$sizeKey]) && is_numeric($parentBox[$sizeKey]) ? (float) $parentBox[$sizeKey] : null;
            if ( abs($origin) >= 1000.0 || (null !== $parentSize && $origin > $parentSize + 100.0) ) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $parentBox
     * @param array<string, mixed> $parentNode
     */
    private function hasRootCanvasOriginMismatch(array $parentBox, array $parentNode): bool
    {
        foreach ( array('x', 'y') as $dimension ) {
            $origin = $this->inferredContainingBlockOrigin($parentNode, $dimension);
            if ( null === $origin || ! isset($parentBox[$dimension]) || ! is_numeric($parentBox[$dimension]) ) {
                continue;
            }

            $parentOrigin = (float) $parentBox[$dimension];
            $sizeKey = 'x' === $dimension ? 'width' : 'height';
            $parentSize = isset($parentBox[$sizeKey]) && is_numeric($parentBox[$sizeKey]) ? (float) $parentBox[$sizeKey] : null;
            if ( abs($origin - $parentOrigin) >= 1000.0 || (null !== $parentSize && $origin > $parentOrigin + $parentSize + 100.0) ) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $parentNode
     */
    private function inferredContainingBlockOrigin(array $parentNode, string $dimension): ?float
    {
        $preferredOrigin = null;
        $fallbackOrigin = null;
        foreach ( $this->nodeList($parentNode) as $child ) {
            if ( ! is_array($child) ) {
                continue;
            }

            $childBox = is_array($child['box'] ?? null) ? $child['box'] : array();
            if ( 'local' === ($childBox['coordinate_space'] ?? null) || ! isset($childBox[$dimension]) || ! is_numeric($childBox[$dimension]) ) {
                continue;
            }

            $value = (float) $childBox[$dimension];
            $fallbackOrigin = null === $fallbackOrigin ? $value : min($fallbackOrigin, $value);
            if ( $this->isContainingBlockOriginCandidate($child) ) {
                $preferredOrigin = null === $preferredOrigin ? $value : min($preferredOrigin, $value);
            }
        }

        return $preferredOrigin ?? $fallbackOrigin;
    }

    /**
     * @param array<string, mixed> $node
     */
    private function isContainingBlockOriginCandidate(array $node): bool
    {
        return $this->treeHasText($node) || $this->treeHasImageReference($node) || ! $this->treeIsVectorShapeOnly($node);
    }

    /**
     * @param array<string, mixed> $parentNode
     * @param array<string, mixed> $node
     */
    private function parentHasTextOutsideNode(array $parentNode, array $node): bool
    {
        $nodeId = (string) ($node['id'] ?? '');
        foreach ( $this->nodeList($parentNode) as $sibling ) {
            if ( ! is_array($sibling) || (string) ($sibling['id'] ?? '') === $nodeId ) {
                continue;
            }
            if ( $this->treeHasText($sibling) ) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $node
     */
    private function isOversizedAgainstParent(array $node, array $parentNode): bool
    {
        $box = is_array($node['box'] ?? null) ? $node['box'] : array();
        $parentBox = is_array($parentNode['box'] ?? null) ? $parentNode['box'] : array();
        foreach ( array('width', 'height') as $dimension ) {
            if ( ! isset($box[$dimension], $parentBox[$dimension]) || ! is_numeric($box[$dimension]) || ! is_numeric($parentBox[$dimension]) || 0.0 >= (float) $parentBox[$dimension] ) {
                return false;
            }
        }

        if ( (float) $box['width'] < 300.0 && (float) $box['height'] < 300.0 ) {
            return false;
        }

        $widthRatio = (float) $box['width'] / (float) $parentBox['width'];
        $heightRatio = (float) $box['height'] / (float) $parentBox['height'];
        $areaRatio = ((float) $box['width'] * (float) $box['height']) / ((float) $parentBox['width'] * (float) $parentBox['height']);

        return 0.75 <= $widthRatio || 0.75 <= $heightRatio || 0.45 <= $areaRatio;
    }

    /**
     * @param array<string, mixed> $node
     * @param array<string, mixed> $parentNode
     * @param array<string, mixed> $parentLayout
     */
    private function isAbsoluteBackgroundBleed(array $node, array $parentNode, array $parentLayout): bool
    {
        if ( 'absolute' !== ($node['layout']['positioning'] ?? null) ) {
            return false;
        }

        $box = is_array($node['box'] ?? null) ? $node['box'] : array();
        $parentBox = is_array($parentNode['box'] ?? null) ? $parentNode['box'] : array();
        foreach ( array('width', 'height') as $dimension ) {
            if ( ! isset($box[$dimension], $parentBox[$dimension]) || ! is_numeric($box[$dimension]) || ! is_numeric($parentBox[$dimension]) || 0.0 >= (float) $parentBox[$dimension] ) {
                return false;
            }
        }

        $isRow = 'row' === ($parentLayout['flex_direction'] ?? null);
        $mainAxis = $isRow ? 'width' : 'height';
        $crossAxis = $isRow ? 'height' : 'width';
        $crossOrigin = $isRow ? 'y' : 'x';
        $mainRatio = (float) $box[$mainAxis] / (float) $parentBox[$mainAxis];
        if ( 0.95 > $mainRatio ) {
            return false;
        }

        $crossOffset = $this->positionOffset($box, $parentBox, $crossOrigin, $parentNode);
        if ( null === $crossOffset ) {
            return false;
        }

        $crossSize = (float) $box[$crossAxis];
        $parentCrossSize = (float) $parentBox[$crossAxis];
        return 1.0 <= ($crossSize / $parentCrossSize) || ($crossOffset <= 0.0 && $crossOffset + $crossSize >= $parentCrossSize);
    }

    /**
     * @param array<string, mixed> $node
     */
    private function treeHasText(array $node): bool
    {
        if ( 'TEXT' === strtoupper((string) ($node['type'] ?? '')) ) {
            return '' !== trim(strip_tags($this->textContent($node)));
        }

        foreach ( $this->nodeList($node) as $child ) {
            if ( is_array($child) && $this->treeHasText($child) ) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $node
     */
    private function treeHasImageReference(array $node): bool
    {
        if ( $this->nodeHasImageReference($node) ) {
            return true;
        }

        foreach ( $this->nodeList($node) as $child ) {
            if ( is_array($child) && $this->treeHasImageReference($child) ) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $node
     */
    private function treeIsVectorShapeOnly(array $node): bool
    {
        $type = strtoupper((string) ($node['type'] ?? ''));
        $children = $this->nodeList($node);
        if ( empty($children) ) {
            return $this->isPrimitiveVectorShapeType($type);
        }

        if ( ! $this->isVectorShapeContainerType($type) ) {
            return false;
        }

        foreach ( $children as $child ) {
            if ( ! is_array($child) || ! $this->treeIsVectorShapeOnly($child) ) {
                return false;
            }
        }

        return true;
    }

    private function isPrimitiveVectorShapeType(string $type): bool
    {
        return in_array($type, array('VECTOR', 'BOOLEAN_OPERATION', 'LINE', 'ELLIPSE', 'STAR', 'POLYGON', 'REGULAR_POLYGON', 'RECTANGLE', 'ROUNDED_RECTANGLE'), true);
    }

    private function isVectorShapeContainerType(string $type): bool
    {
        return in_array($type, array('FRAME', 'GROUP', 'COMPONENT', 'INSTANCE', 'BOOLEAN_OPERATION'), true);
    }

    /**
     * @param array<string, mixed> $node
     */
    private function textContent(array $node): string
    {
        $text = is_array($node['figma_text'] ?? null) ? $node['figma_text'] : array();
        $segments = is_array($text['segments'] ?? null) ? $text['segments'] : array();
        if ( ! empty($segments) ) {
            $content = '';
            foreach ( $segments as $segment ) {
                if ( is_array($segment) && isset($segment['characters']) && is_scalar($segment['characters']) ) {
                    $content .= (string) $segment['characters'];
                }
            }
            if ( '' !== $content ) {
                return htmlspecialchars($content, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            }
        }

        $characters = isset($text['characters']) && is_scalar($text['characters']) ? (string) $text['characters'] : (string) ($node['characters'] ?? $node['text'] ?? '');
        if ( $this->isUnresolvedComponentPlaceholderText($node, $characters) ) {
            return '';
        }

        return htmlspecialchars($characters, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /**
     * @param array<string, mixed> $node
     */
    private function isUnresolvedComponentPlaceholderText(array $node, string $characters): bool
    {
        $placeholder = strtolower(trim($characters));
        if ( ! in_array($placeholder, array('button label'), true) ) {
            return false;
        }

        $id = (string) ($node['id'] ?? '');
        return str_contains($id, '/') || isset($node['figma_component_source_id']);
    }

    private function nodeAssetPath(array $node): ?string
    {
        foreach ( $this->nodeAssetReferences($node) as $assetId ) {
            if ( isset($this->assetsById[$assetId]) ) {
                return (string) $this->assetsById[$assetId]['path'];
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $node
     */
    private function nodeHasImageReference(array $node): bool
    {
        return null !== $this->nodeAssetPath($node) || ! empty($this->explicitNodeAssetReferences($node)) || ! empty($this->nodeImagePaints($node));
    }

    /**
     * @param array<string, mixed> $node
     * @return array<int, string>
     */
    private function nodeAssetReferences(array $node): array
    {
        $references = array();
        foreach ( array('asset_id', 'assetId', 'image_ref', 'imageRef', 'imageHash', 'ref') as $key ) {
            if ( isset($node[$key]) && is_scalar($node[$key]) ) {
                $references[] = (string) $node[$key];
            }
        }
        foreach ( array('fills', 'strokes', 'background') as $paintKey ) {
            foreach ( is_array($node[$paintKey] ?? null) ? $node[$paintKey] : array() as $paint ) {
                if ( ! is_array($paint) ) {
                    continue;
                }
                foreach ( array('imageRef', 'imageHash', 'ref', 'asset_id', 'assetId', 'image_ref') as $key ) {
                    if ( isset($paint[$key]) && is_scalar($paint[$key]) ) {
                        $references[] = (string) $paint[$key];
                    }
                }
            }
        }

        return array_values(array_unique(array_filter($references, static fn (string $reference): bool => '' !== $reference)));
    }

    /**
     * @param array<string, mixed> $node
     * @return array<int, string>
     */
    private function explicitNodeAssetReferences(array $node): array
    {
        $references = array();
        foreach ( array('asset_id', 'assetId', 'image_ref', 'imageRef', 'imageHash', 'ref') as $key ) {
            if ( isset($node[$key]) && is_scalar($node[$key]) && '' !== (string) $node[$key] ) {
                $references[] = (string) $node[$key];
            }
        }
        if ( is_array($node['image'] ?? null) ) {
            foreach ( array('asset_id', 'assetId', 'image_ref', 'imageRef', 'imageHash', 'ref') as $key ) {
                if ( isset($node['image'][$key]) && is_scalar($node['image'][$key]) && '' !== (string) $node['image'][$key] ) {
                    $references[] = (string) $node['image'][$key];
                }
            }
        }

        return array_values(array_unique($references));
    }

    /**
     * @param array<string, mixed> $node
     * @return array<int, array<string, mixed>>
     */
    private function nodeImagePaints(array $node): array
    {
        $imagePaints = array();
        foreach ( array('fills', 'strokes', 'background') as $paintKey ) {
            $paintCollections = array();
            if ( is_array($node[$paintKey] ?? null) ) {
                $paintCollections[] = $node[$paintKey];
            }
            if ( is_array($node['figma_paints'][$paintKey] ?? null) ) {
                $paintCollections[] = $node['figma_paints'][$paintKey];
            }
            foreach ( $paintCollections as $paints ) {
                foreach ( $paints as $paint ) {
                    if ( is_array($paint) && 'IMAGE' === strtoupper((string) ($paint['type'] ?? '')) ) {
                        $imagePaints[] = $paint;
                    }
                }
            }
        }

        return $imagePaints;
    }

    /**
     * @param array<string, mixed> $container
     * @return array<int, mixed>
     */
    private function nodeList(array $container): array
    {
        if ( is_array($container['nodes'] ?? null) ) {
            return array_values($container['nodes']);
        }
        if ( is_array($container['children'] ?? null) ) {
            return array_values($container['children']);
        }

        return array();
    }
}
